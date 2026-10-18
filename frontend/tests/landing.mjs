// Phase 3 landing page checks.
// Part A: production build (vite preview) with SIMULATED /auth/me states and simulated
//         onboarding endpoints — redirects, links, viewports, keyboard, menu, motion.
//         The preview proxy points at an unreachable port: nothing reaches Apache/dev.
// Part B: the REAL Apache-served page as a logged-out visitor (read-only: the only API
//         call a visitor makes is GET /auth/me -> 401). No accounts, no writes.
// Run from frontend/ after `npm run build`: node tests/landing.mjs
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4196, BASE = `http://localhost:${PORT}/hunter-system`, LIVE = 'http://localhost/hunter-system';
let checks = 0;
const check = (name, ok) => { assert.ok(ok, name); checks++; console.log('PASS ' + name); };

// ---- source-of-truth drift checks for the static landing copy
const seed = readFileSync('../database/seed.sql', 'utf8');
const seedTopics = [...seed.matchAll(/^\s*\(\s*(\d+),\s*\d+,\s*'[a-z0-9-]+',\s*'([^']+)',\s*'[^']*',\s*(\d)/gm)].map((m) => ({ position: +m[1], title: m[2].trim(), group: 'G' + m[3] }));
const seedGroups = Object.fromEntries([...seed.matchAll(/\(\d, '(G\d)', '([^']+)',\s+\d\)/g)].map((m) => [m[1], m[2].trim()]));
const contract = readFileSync('../docs/api-contract.md', 'utf8');
const passedRow = /\| Distinct topics passed \|([^\n]+)\|/.exec(contract)[1].split('|').map((s) => s.trim().replace('-', '–'));
const rankRow = /\| Earned rank \|([^\n]+)\|/.exec(contract)[1].split('|').map((s) => s.trim());
check(`Seed provides 18 topics and 6 groups; contract provides the rank table (${rankRow.join(' ')})`, seedTopics.length === 18 && Object.keys(seedGroups).length === 6 && rankRow.length === 6);

const STUDENT = (state) => ({ id: 1, email: 'sim.student@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: state });
const ADMIN = { id: 2, email: 'sim.admin@example.invalid', display_name: 'Sim Admin', role: 'admin' };
function simulate(s, method, path) {
  if (path === '/auth/me') return s.user ? [200, { user: s.user, csrf_token: 'simulated' }] : [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (!s.user) return [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (path === '/experience-levels') return [200, [{ id: 1, code: 'complete_beginner', label: 'Complete beginner' }]];
  if (path === '/assessment' && method === 'POST') return [503, { error: { code: 'assessment_unavailable', message: 'Simulated: unavailable.' } }];
  if (path === '/me/dashboard') return [200, { display_name: 'Sim Student', onboarding_state: 'assessment_submitted', experience_level: null, placement_rank: { code: 'E' }, hunter_rank: { code: 'E' },
    assessment: { state: 'submitted' }, recommended_topic: null, offered_topic: null, current_topic: null, coins: 0, topics_passed: 0, topics_total: 18, next_rank: null }];
  if (['/admin/topics', '/admin/badges'].includes(path)) return [200, []];
  if (path === '/admin/questions') return [200, { questions: [], page: 1, per_page: 1, total: 0 }];
  if (path === '/admin/students') return [200, { students: [], page: 1, per_page: 1, total: 0 }];
  return [404, { error: { code: 'not_found', message: 'Simulated: not found.' } }];
}

const preview = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', 'localhost', '--port', String(PORT), '--strictPort'],
  { cwd: process.cwd(), stdio: 'ignore', env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:9' } });
let browser;
const errors = [];
try {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(BASE + '/login')).ok) break; } catch { /* starting */ } await delay(100); }
  browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });

  async function context(user, options = {}) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
    c.sim = { user, meDelay: 0, api: [], external: [] };
    await c.addInitScript(() => {
      const pending = new Set(); const raf = window.requestAnimationFrame.bind(window), cancel = window.cancelAnimationFrame.bind(window);
      window.requestAnimationFrame = (cb) => { const id = raf((t) => { pending.delete(id); cb(t); }); pending.add(id); return id; };
      window.cancelAnimationFrame = (id) => { pending.delete(id); cancel(id); };
      window.__pendingFrames = () => pending.size;
    });
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const path = new URL(route.request().url()).pathname.split('/api/v1')[1];
      c.sim.api.push(`${route.request().method()} ${path}`);
      if (path === '/auth/me' && c.sim.meDelay) await delay(c.sim.meDelay);
      const [status, body] = simulate(c.sim, route.request().method(), path);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) c.sim.external.push(r.url()); }); });
    return c;
  }
  const landing = async (p) => { await p.goto(BASE + '/'); await p.locator('#hero-title').waitFor(); };
  const path = (p) => new URL(p.url()).pathname;

  // ---- A1. routing: logged-out landing, signed-in redirects unchanged
  const c0 = await context(null), p0 = await c0.newPage();
  c0.sim.meDelay = 700;
  await p0.goto(BASE + '/');
  await p0.getByText('Checking your session').waitFor();
  check('While the session check is pending, "/" shows the loading state (no landing flash)', (await p0.locator('#hero-title').count()) === 0);
  await p0.locator('#hero-title').waitFor();
  check('Logged-out visitor at "/" sees the landing page and stays on "/"', path(p0) === '/hunter-system/' && (await p0.locator('.landing').count()) === 1);
  await c0.close();
  for (const [user, target] of [[STUDENT('not_started'), '/start'], [STUDENT('experience_chosen'), '/assessment'], [STUDENT('assessment_submitted'), '/dashboard'], [ADMIN, '/admin']]) {
    const c = await context(user), p = await c.newPage();
    await p.goto(BASE + '/'); await p.waitForURL(new RegExp(`${target}$`));
    await p.locator('main h1').first().waitFor();
    check(`Signed-in ${user.role}${user.onboarding_state ? ` (${user.onboarding_state})` : ''} at "/" is redirected to ${target}; no landing rendered`, path(p) === '/hunter-system' + target && (await p.locator('.landing').count()) === 0);
    await c.close();
  }
  const cg = await context(null), pg = await cg.newPage();
  await pg.goto(BASE + '/dashboard'); await pg.waitForURL(/\/login$/);
  check('Protected routes still send logged-out visitors to /login', path(pg) === '/hunter-system/login');
  await cg.close();

  // ---- A2. content, links, structure (desktop)
  const c = await context(null), p = await c.newPage();
  await landing(p);
  check('Headline reads "AWAKEN. LEARN. LEVEL UP."', (await p.locator('#hero-title').innerText()).replace(/\s+/g, ' ').trim() === 'AWAKEN. LEARN. LEVEL UP.');
  check('Exactly one h1; five labelled content sections', (await p.locator('h1').count()) === 1
    && (await p.locator('main section[aria-labelledby]').count()) === 6);  // hero + 5 sections
  for (const [name, to] of [['Begin Awakening', '/register'], ['Enter Hunter Portal', '/login'], ['Create account', '/register'], ['Log in', '/login']]) {
    const hrefs = await p.getByRole('link', { name, exact: true }).evaluateAll((as) => as.map((a) => new URL(a.href).pathname));
    check(`Every "${name}" link (${hrefs.length}) points to ${to}`, hrefs.length > 0 && hrefs.every((h) => h === '/hunter-system' + to));
  }
  await p.getByRole('link', { name: 'Begin Awakening', exact: true }).first().click(); await p.waitForURL(/\/register$/);
  check('"Begin Awakening" navigates to /register (registration form shown)', (await p.getByRole('heading', { name: 'Create your account' }).count()) === 1);
  await landing(p);
  await p.getByRole('link', { name: 'Enter Hunter Portal', exact: true }).first().click(); await p.waitForURL(/\/login$/);
  check('"Enter Hunter Portal" navigates to /login (login form shown)', (await p.getByRole('heading', { name: 'Log in' }).count()) === 1);
  await landing(p);
  const gates = await p.locator('.gate').evaluateAll((gs) => gs.map((g) => ({ title: g.querySelector('.gate-title').innerText, missions: [...g.querySelectorAll('.gate-mission')].map((m) => m.innerText.replace(/^\d+\s*/, '').replace(/^Mission \d+:\s*/, '').trim()) })));
  check('Six gates named exactly as the seed skill groups', gates.length === 6 && gates.every((g, i) => g.title.endsWith(seedGroups[`G${i + 1}`])));
  check('All 18 mission titles and their gates match database/seed.sql', JSON.stringify(gates.map((g) => g.missions))
    === JSON.stringify(Object.keys(seedGroups).map((gc) => seedTopics.filter((t) => t.group === gc).map((t) => t.title))));
  const ladder = await p.locator('.rank-step').evaluateAll((ls) => ls.map((l) => [l.querySelector('.rank-step-name').innerText, l.querySelector('.rank-step-req').innerText]));
  check('Rank ladder uses the mapped labels E→S', ladder.map((l) => l[0]).join('|') === 'E-Rank · Peasant|D-Rank · Apprentice|C-Rank · Squire|B-Rank · Knight|A-Rank · Champion|S-Rank · Mythic');
  check('Rank thresholds match docs/api-contract.md §7', rankRow.join('') === 'EDCBAS' && ladder.every((l, i) => l[1].startsWith(passedRow[i] + ' ')));
  const body = await p.locator('body').innerText();
  check('No invented statistics, badge names or prices; the only Mana amount is the approved 10', !/Shadow (Recruit|Soldier|Knight|Commander|Monarch)/.test(body)
    && [...body.matchAll(/(\d+)\s*Mana/g)].every((m) => m[1] === '10') && !/Rookie/.test(body) && !/\b\d+[,.]?\d*\s*(students|hunters|users|learners)\b/i.test(body));
  check('Attribution and not-affiliated notice present in the footer', /artwork belongs to its respective owners/.test(await p.locator('footer').innerText())
    && /not affiliated with/.test(await p.locator('footer').innerText()));
  check('Hero art is decorative (alt="" inside aria-hidden) and decodes the 560px file on desktop', await p.locator('.hero-art').evaluate(async (el) => {
    const img = el.querySelector('img'); await img.decode(); const probe = new Image(); probe.src = img.currentSrc; await probe.decode();
    return el.getAttribute('aria-hidden') === 'true' && img.getAttribute('alt') === '' && probe.naturalWidth === 560; }));
  await p.getByRole('navigation', { name: 'Page sections' }).getByRole('link', { name: 'Ranks' }).click();
  await p.waitForFunction(() => location.hash === '#ranks');
  await delay(300);
  check('Section link scrolls to #ranks and stays on the landing route', path(p) === '/hunter-system/' && await p.locator('#ranks').evaluate((e) => { const r = e.getBoundingClientRect(); return r.top < innerHeight && r.bottom > 0; }));

  // ---- A3. keyboard
  await landing(p);
  await p.keyboard.press('Tab');
  check('First Tab reaches the skip link', (await p.evaluate(() => document.activeElement.textContent)) === 'Skip to content');
  const order = [];
  for (let i = 0; i < 9; i++) { await p.keyboard.press('Tab'); order.push(await p.evaluate(() => document.activeElement.textContent.trim())); }
  check(`Header tab order: ${order.slice(0, 7).join(' → ')}`, order.slice(1, 7).join('|') === 'Awakening|Missions|Ranks|Mana|Log in|Create account');
  check('Keyboard continues into the hero calls to action', order.slice(7).join('|') === 'Begin Awakening|Enter Hunter Portal');
  await p.locator('.hero .btn-primary').focus(); await p.keyboard.press('Shift+Tab'); await p.keyboard.press('Tab');
  check('Focused call to action shows a 3px focus outline', await p.evaluate(() => { const s = getComputedStyle(document.activeElement); return document.activeElement.textContent === 'Begin Awakening' && s.outlineStyle === 'solid' && s.outlineWidth === '3px'; }));
  await p.keyboard.press('Enter'); await p.waitForURL(/\/register$/);
  check('Enter on "Begin Awakening" opens /register', path(p) === '/hunter-system/register');
  await p.goto(BASE + '/no-such-page'); await p.getByRole('link', { name: 'Go to the start' }).click(); await p.locator('#hero-title').waitFor();
  await p.waitForFunction(() => document.activeElement?.id === 'hero-title', {}, { timeout: 3000 });
  check('In-app navigation to "/" moves focus to the landing title', await p.evaluate(() => document.activeElement.id === 'hero-title'));

  // ---- A4. viewports 320–1440: no overflow, hero art and headline visible
  for (const width of [1440, 1280, 1024, 900, 768, 600, 390, 360, 320]) {
    await p.setViewportSize({ width, height: 900 }); await landing(p);
    const m = await p.evaluate(() => {
      const h = document.querySelector('#hero-title').getBoundingClientRect(), a = document.querySelector('.hero-art-frame').getBoundingClientRect();
      return { overflow: document.documentElement.scrollWidth > innerWidth, heading: h.width > 0 && h.right <= innerWidth + 0.5, art: a.width > 0 && a.height > 100, artFirst: a.top < h.top };
    });
    check(`${width}px: no horizontal overflow; headline and art visible${width <= 760 ? '; art above headline' : ''}`, !m.overflow && m.heading && m.art && (width > 760 || m.artFirst));
  }
  check('Touch targets: header and call-to-action controls are at least 44px tall at 390px', await (async () => { await p.setViewportSize({ width: 390, height: 844 }); await landing(p);
    return p.locator('.landing-actions .btn:visible, .hero .btn').evaluateAll((bs) => bs.every((b) => b.getBoundingClientRect().height >= 44)); })());
  check('390px uses the 400px hero file (srcset)', await p.locator('.hero-art img').evaluate(async (img) => { await img.decode(); const probe = new Image(); probe.src = img.currentSrc; await probe.decode(); return probe.naturalWidth === 400; }));

  // ---- A5. mobile menu
  check('Section links collapse into a Menu button at 390px', !(await p.getByRole('navigation', { name: 'Page sections', exact: true }).isVisible()) && (await p.getByRole('button', { name: 'Menu', exact: true }).isVisible()));
  await p.getByRole('button', { name: 'Menu', exact: true }).click(); await p.locator('dialog[open]').waitFor();
  check('Menu opens as a modal with focus inside', await p.evaluate(() => document.querySelector('dialog[open]').contains(document.activeElement)) && (await p.getByRole('button', { name: 'Menu', exact: true }).getAttribute('aria-expanded')) === 'true');
  check('Menu lists 4 sections plus Begin Awakening and Enter Hunter Portal', (await p.locator('dialog[open] nav a').count()) === 4
    && (await p.locator('dialog[open]').getByRole('link', { name: 'Begin Awakening' }).getAttribute('href')).endsWith('/register')
    && (await p.locator('dialog[open]').getByRole('link', { name: 'Enter Hunter Portal' }).getAttribute('href')).endsWith('/login'));
  await p.getByRole('button', { name: 'Close menu' }).focus(); await p.keyboard.press('Shift+Tab');
  check('Reverse Tab wraps inside the menu', await p.evaluate(() => document.activeElement.textContent === 'Enter Hunter Portal'));
  await p.keyboard.press('Escape'); await p.locator('dialog[open]').waitFor({ state: 'detached' }).catch(() => {});
  check('Escape closes the menu, returns focus to Menu and restores scrolling', (await p.locator('dialog[open]').count()) === 0
    && (await p.evaluate(() => document.activeElement.textContent.trim() === 'Menu' && document.body.style.overflow === '')));
  await p.getByRole('button', { name: 'Menu', exact: true }).click(); await p.locator('dialog[open]').waitFor();
  await p.locator('dialog[open] nav').getByRole('link', { name: 'Mana' }).click();
  await p.waitForFunction(() => location.hash === '#mana'); await delay(300);
  check('Choosing a section closes the menu and scrolls to it', (await p.locator('dialog[open]').count()) === 0
    && await p.locator('#mana').evaluate((e) => { const r = e.getBoundingClientRect(); return r.top < innerHeight && r.bottom > 0; }));
  await c.close();

  // ---- A6. motion
  const cm = await context(null), pm = await cm.newPage();
  await landing(pm);
  check('With motion allowed, sections start hidden for a one-time reveal', (await pm.locator('.landing.js-reveal').count()) === 1 && (await pm.locator('.reveal:not(.is-visible)').count()) > 0);
  await pm.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await delay(250);
  for (const sel of ['#awakening', '#missions', '#ranks', '#mana', '#begin']) { await pm.locator(sel).scrollIntoViewIfNeeded(); await delay(120); }
  check('Every section is revealed after scrolling (no content left hidden)', (await pm.locator('.reveal:not(.is-visible)').count()) === 0);
  check('Ambient motes animate with motion allowed (one loop)', (await pm.evaluate(() => window.__pendingFrames())) === 1);
  await cm.close();
  const cr = await context(null, { reducedMotion: 'reduce' }), pr = await cr.newPage();
  await landing(pr); await delay(200);
  check('Reduced motion: no reveal hiding, all sections visible immediately', (await pr.locator('.landing.js-reveal').count()) === 0
    && await pr.locator('.reveal').evaluateAll((els) => els.every((e) => getComputedStyle(e).opacity === '1')));
  check('Reduced motion: hero entrance and transitions effectively disabled', await pr.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.hero-text')).animationDuration) < 0.01
    && parseFloat(getComputedStyle(document.querySelector('.btn')).transitionDuration) < 0.01));
  const frame = await pr.locator('canvas').evaluate((e) => e.toDataURL()); await delay(200);
  check('Reduced motion: ambient canvas is one static frame with no pending animation', frame === (await pr.locator('canvas').evaluate((e) => e.toDataURL())) && (await pr.evaluate(() => window.__pendingFrames())) === 0);
  check('Reduced motion: anchor scrolling is instant', (await pr.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)) === 'auto');
  await cr.close();

  // ---- A7. network (simulated run): logged-out landing calls only GET /auth/me
  const cn = await context(null), pn = await cn.newPage();
  await landing(pn); await pn.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await delay(400);
  check(`Logged-out landing makes exactly one API call (${cn.sim.api.join(', ')})`, cn.sim.api.length === 1 && cn.sim.api[0] === 'GET /auth/me');
  check('No external requests in the simulated run', cn.sim.external.length === 0);
  await cn.close();

  // ---- Part B. LIVE Apache, logged-out visitor (read-only)
  const cl = await browser.newContext({ viewport: { width: 1440, height: 900 } }), pl = await cl.newPage();
  const requests = [];
  pl.on('request', (r) => requests.push({ url: r.url(), method: r.method() }));
  pl.on('pageerror', (e) => errors.push('live: ' + e.message));
  const liveStatus = [];
  pl.on('response', (r) => { if (r.url().includes('/api/v1/')) liveStatus.push(`${r.request().method()} ${new URL(r.url()).pathname} ${r.status()}`); });
  const nav = await pl.goto(LIVE + '/');
  await pl.locator('#hero-title').waitFor(); await pl.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await delay(500);
  check('LIVE Apache "/" (HTTP 200, CSP) renders the landing page for a logged-out visitor', nav.status() === 200 && (nav.headers()['content-security-policy'] ?? '').includes("default-src 'self'")
    && (await pl.locator('#hero-title').innerText()).replace(/\s+/g, ' ').trim() === 'AWAKEN. LEARN. LEVEL UP.');
  check(`LIVE: the only API call is the session check (${liveStatus.join(', ')})`, liveStatus.length === 1 && liveStatus[0] === 'GET /hunter-system/api/v1/auth/me 401');
  check('LIVE: every request is same-origin GET (no external fonts, CDNs or images; no writes)', requests.every((r) => new URL(r.url).origin === 'http://localhost' && r.method === 'GET'));
  check('LIVE: hero art served from hashed static/ WebP', requests.some((r) => /\/hunter-system\/static\/hero-awakened-hunter(-sm)?-[\w-]+\.webp$/.test(r.url)));
  await cl.close();

  check('No uncaught browser errors (simulated and live)', errors.length === 0);
  console.log(`${checks} Phase 3 landing checks passed — Part A production build with SIMULATED session states; Part B live Apache, logged-out, read-only.`);
} finally {
  await browser?.close();
  preview.kill();
}
