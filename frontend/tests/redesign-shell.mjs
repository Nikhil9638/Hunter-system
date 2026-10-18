// Phase 2 app-shell checks against the REAL production build (vite preview of dist/).
// ALL API responses are SIMULATED in-browser (page.route); the preview proxy points at an
// unreachable port, so nothing can reach Apache/dev. UI evidence only — never reported as
// API integration. Run from frontend/ after `npm run build`: node tests/redesign-shell.mjs
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4195, BASE = `http://localhost:${PORT}/hunter-system`;
// Optional: --shots <dir> saves review screenshots (outside the project unless chosen otherwise).
const SHOTS = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false }); };
let checks = 0;
const check = (name, ok) => { assert.ok(ok, name); checks++; console.log('PASS ' + name); };

// ---- WCAG contrast of the token pairs used for text/UI (spec §1.2) — pure arithmetic
const lum = (hex) => { const c = hex.match(/\w\w/g).map((h) => parseInt(h, 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
for (const [fg, bg, min, what] of [
  ['#eef1ff', '#0a0b1a', 4.5, 'body text on night'], ['#a3a9cf', '#0a0b1a', 4.5, 'muted text on night'], ['#7c82ad', '#0a0b1a', 4.5, 'dim meta text on night'],
  ['#a3a9cf', '#151938', 4.5, 'muted text on surface-2'], ['#ffffff', '#7c3aed', 4.5, 'primary button text'], ['#8be3ff', '#151938', 4.5, 'secondary button text'],
  ['#b69cff', '#0a0b1a', 4.5, 'active nav text'], ['#9ecbff', '#0a0b1a', 4.5, 'links'], ['#ff6b81', '#0a0b1a', 4.5, 'danger text'], ['#fbbf24', '#0a0b1a', 4.5, 'warning text'],
  ['#4ade80', '#0a0b1a', 4.5, 'success text'], ['#f5d48a', '#0a0b1a', 4.5, 'gold text'], ['#8be3ff', '#0a0b1a', 3, 'focus ring (non-text 3:1)'],
  ['#5f66ad', '#0b0d22', 3, 'control border vs input fill (WCAG 1.4.11)'], ['#5f66ad', '#151938', 3, 'control border vs surface-2 (WCAG 1.4.11)'],
]) check(`Contrast ${what}: ${ratio(fg, bg).toFixed(2)}:1 >= ${min}`, ratio(fg, bg) >= min);

// ---- SIMULATED API
const STUDENT = { id: 1, email: 'sim.student@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: 'assessment_submitted' };
const ADMIN = { id: 2, email: 'sim.admin@example.invalid', display_name: 'Sim Admin', role: 'admin' };
const E_RANK = { code: 'E', name: 'E-Rank', title: 'Rookie' };            // the API really carries title "Rookie"
const TOPICS = Array.from({ length: 18 }, (_, i) => ({ id: i + 1, position: i + 1, title: `Simulated topic ${i + 1}`, skill_group: 'G1', unlocked: i < 2, unlock_source: i < 2 ? 'placement' : null,
  content_ready: i < 2, status: i === 0 ? 'passed' : i === 1 ? 'available' : 'locked', lesson_visited: i === 0, completed_attempts: i === 0 ? 1 : 0, best_correct: i === 0 ? 4 : null, first_passed_at: null, open_attempt_id: null }));
function simulate(state, method, path) {
  const user = state.role === 'student' ? STUDENT : state.role === 'admin' ? ADMIN : null;
  if (path === '/auth/me') return user ? [200, { user, csrf_token: 'simulated' }] : [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (path === '/auth/logout' && method === 'POST') { state.role = 'anon'; return [200, { logged_out: true }]; }
  if (!user) return [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (state.role === 'student') {
    if (path === '/me/dashboard') return [200, { display_name: STUDENT.display_name, onboarding_state: 'assessment_submitted', experience_level: { code: 'basic', label: 'Knows a little basic Python' },
      placement_rank: E_RANK, hunter_rank: E_RANK, assessment: { state: 'submitted', attempt_id: 1, can_start: false }, recommended_topic: { id: 2, position: 2, title: 'Simulated topic 2', ready: true },
      offered_topic: null, current_topic: { id: 2, title: 'Simulated topic 2', content_ready: true }, coins: 10, topics_passed: 1, topics_total: 18, next_rank: { code: 'D', name: 'D-Rank', topics_required: 3, topics_remaining: 2 } }];
    if (path === '/topics') return [200, TOPICS];
    if (path === '/me/rank-history') return [200, [{ at: '2026-10-18T09:00:00.000Z', reason: 'placement', from: null, to: 'E' }]];
    if (path === '/me/coins') return [200, { balance: 10, entries: [{ id: 1, at: '2026-10-18T09:30:00.000Z', direction: 'credit', amount: 10, balance_after: 10, type: 'topic_reward', topic_id: 1, badge_id: null }] }];
  }
  if (state.role === 'admin') {
    if (path === '/admin/topics' || path === '/admin/badges') return [200, []];
    if (path === '/admin/questions') return [200, { questions: [], page: 1, per_page: 1, total: 0 }];
    if (path === '/admin/students') return [200, { students: [], page: 1, per_page: 1, total: 0 }];
  }
  return [404, { error: { code: 'not_found', message: 'Simulated: not found.' } }];
}

const preview = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', 'localhost', '--port', String(PORT), '--strictPort'],
  { cwd: process.cwd(), stdio: 'ignore', env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:9' } });
let browser;
const errors = [], external = [];
try {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(BASE + '/login')).ok) break; } catch { /* starting */ } await delay(100); }
  browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });

  async function context(role, options = {}) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
    c.state = { role, unrouted: 0 };
    await c.addInitScript(() => {                 // same frame/listener instrumentation as theme-checks.mjs
      const pending = new Set(), resize = new Set(), visibility = new Set();
      const raf = window.requestAnimationFrame.bind(window), cancel = window.cancelAnimationFrame.bind(window);
      window.requestAnimationFrame = (cb) => { const id = raf((t) => { pending.delete(id); cb(t); }); pending.add(id); return id; };
      window.cancelAnimationFrame = (id) => { pending.delete(id); cancel(id); };
      const add = EventTarget.prototype.addEventListener, remove = EventTarget.prototype.removeEventListener;
      EventTarget.prototype.addEventListener = function (type, cb, ...rest) { if (this === window && type === 'resize') resize.add(cb); if (this === document && type === 'visibilitychange') visibility.add(cb); return add.call(this, type, cb, ...rest); };
      EventTarget.prototype.removeEventListener = function (type, cb, ...rest) { if (this === window && type === 'resize') resize.delete(cb); if (this === document && type === 'visibilitychange') visibility.delete(cb); return remove.call(this, type, cb, ...rest); };
      window.__metrics = () => ({ pending: pending.size, resize: resize.size, visibility: visibility.size });
    });
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const u = new URL(route.request().url());
      const [status, body] = simulate(c.state, route.request().method(), u.pathname.split('/api/v1')[1]);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) external.push(r.url()); }); });
    return c;
  }
  const go = async (p, url, ready) => { await p.goto(BASE + url); await p.locator(ready).first().waitFor(); };
  const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

  // ---- A. anonymous login/register: tokens, ambient layer, overflow
  const ca = await context('anon'), a = await ca.newPage();
  await go(a, '/login', 'input[type=email]');
  await shot(a, 'login-1440');
  const tokens = await a.evaluate(() => { const s = getComputedStyle(document.documentElement); return ['--bg-night', '--violet', '--hud', '--focus', '--text'].map((k) => s.getPropertyValue(k).trim()).join(','); });
  check('System tokens are active in the built CSS', tokens === '#0a0b1a,#8b5cf6,#4cc9ff,#8be3ff,#eef1ff');
  check('Primary button: white text on violet gradient', await a.locator('.btn-primary').evaluate((e) => { const s = getComputedStyle(e); return s.color === 'rgb(255, 255, 255)' && s.backgroundImage.includes('rgb(124, 58, 237)'); }));
  check('One decorative, aria-hidden, pointer-free ambient layer with one canvas', (await a.locator('.ambient-background[aria-hidden=true]').count()) === 1
    && (await a.locator('.ambient-background').evaluate((e) => getComputedStyle(e).pointerEvents)) === 'none' && (await a.locator('canvas').count()) === 1);
  for (const width of [1440, 900, 768, 390, 320]) {
    await a.setViewportSize({ width, height: 900 });
    for (const url of ['/login', '/register']) { await go(a, url, 'input[type=email]'); check(`Anonymous ${url} ${width}px: no horizontal overflow`, await noOverflow(a)); }
  }
  await ca.close();

  // ---- B. student shell
  const cs = await context('student'), s = await cs.newPage();
  await go(s, '/dashboard', '.stat');
  await delay(150); await shot(s, 'student-dashboard-1440');
  const navNames = await s.locator('.desktop-sidebar nav a').allInnerTexts();
  check(`Student navigation labels: ${navNames.join(' · ')}`, navNames.join('|') === 'Dashboard|Missions|Rank & Mana|Badge store|Profile');
  check('Navigation targets unchanged', (await s.locator('.desktop-sidebar nav a').evaluateAll((as) => as.map((x) => new URL(x.href).pathname).join('|')))
    === '/hunter-system/dashboard|/hunter-system/topics|/hunter-system/history|/hunter-system/store|/hunter-system/profile');
  check('Sidebar 245px at desktop; active link uses violet indicator', (await s.locator('.desktop-sidebar').evaluate((e) => getComputedStyle(e).width)) === '245px'
    && (await s.locator('.desktop-sidebar a.active').evaluate((e) => getComputedStyle(e).color)) === 'rgb(182, 156, 255)');
  check('Sidebar menu eyebrow reads SYSTEM MENU', (await s.locator('.nav-label').innerText()) === 'SYSTEM MENU');
  const dash = await s.locator('main').innerText();
  check('Dashboard rank shows "E-Rank · Peasant" from {code} objects', dash.includes('E-Rank · Peasant'));
  check('"Rookie" from the API is never rendered anywhere in the page', !/Rookie/i.test(await s.locator('body').innerText()));
  await go(s, '/history', '.timeline li');
  check('Rank history (bare letter "E") shows "E-Rank · Peasant"', (await s.locator('.timeline').innerText()).includes('E-Rank · Peasant'));
  await go(s, '/topics', '.roadmap-item');
  await shot(s, 'student-topics-1440');
  check('Breadcrumb names the Missions section', (await s.locator('.breadcrumbs strong').innerText()) === 'Missions');
  await s.setViewportSize({ width: 900, height: 900 });
  check('Tablet sidebar narrows to 210px', (await s.locator('.desktop-sidebar').evaluate((e) => getComputedStyle(e).width)) === '210px');
  await s.setViewportSize({ width: 1440, height: 1000 });
  await go(s, '/dashboard', '.stat');
  let focusedNav = false;
  for (let i = 0; i < 12 && !focusedNav; i++) { await s.keyboard.press('Tab'); focusedNav = await s.evaluate(() => !!document.activeElement.closest('.desktop-sidebar nav')); }
  check('Keyboard reaches navigation with a visible 3px focus outline', focusedNav && (await s.evaluate(() => { const st = getComputedStyle(document.activeElement); return st.outlineStyle === 'solid' && st.outlineWidth === '3px'; })));
  for (const width of [1440, 900, 768, 390, 320]) {
    await s.setViewportSize({ width, height: 900 });
    for (const [url, ready] of [['/dashboard', '.stat'], ['/topics', '.roadmap-item'], ['/history', '.ledger']]) { await go(s, url, ready); check(`Student ${url} ${width}px: no horizontal overflow`, await noOverflow(s)); }
  }

  // drawer (390px)
  await s.setViewportSize({ width: 390, height: 844 });
  await go(s, '/dashboard', '.stat');
  await shot(s, 'student-dashboard-390');
  await s.getByRole('button', { name: 'Menu', exact: true }).click(); await s.locator('dialog[open]').waitFor();
  await shot(s, 'student-drawer-390');
  check('Drawer lists the five student destinations only', (await s.locator('dialog nav a').count()) === 5 && (await s.locator('dialog a[href*="/admin"]').count()) === 0);
  check('Drawer opens with focus inside', await s.evaluate(() => document.querySelector('dialog').contains(document.activeElement)));
  const close = s.getByRole('button', { name: 'Close navigation' });
  await close.focus(); await s.keyboard.press('Shift+Tab');
  check('Reverse Tab wraps to the last destination (Profile)', await s.evaluate(() => document.activeElement.textContent === 'Profile'));
  await s.keyboard.press('Tab'); check('Tab wraps back to Close', await close.evaluate((e) => e === document.activeElement));
  await s.keyboard.press('Escape'); await s.locator('dialog[open]').waitFor({ state: 'hidden' });
  check('Escape closes and returns focus to Menu; body scroll restored', (await s.getByRole('button', { name: 'Menu', exact: true }).evaluate((e) => e === document.activeElement))
    && (await s.evaluate(() => document.body.style.overflow === '')));
  await s.getByRole('button', { name: 'Menu', exact: true }).click();
  await s.locator('dialog nav').getByRole('link', { name: 'Missions', exact: true }).click(); await s.waitForURL(/\/topics$/);
  await s.waitForFunction(() => document.querySelector('h1') === document.activeElement, {}, { timeout: 3000 });
  check('Drawer "Missions" closes the overlay and focuses the next page title', (await s.locator('dialog[open]').count()) === 0);

  // ambient loop guards (same instrumentation as theme-checks.mjs)
  await s.setViewportSize({ width: 1440, height: 900 }); await go(s, '/dashboard', '.stat'); await delay(120);
  await s.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); }); await delay(100);
  check('Hidden document cancels animation frames', (await s.evaluate(() => window.__metrics())).pending === 0);
  await s.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); }); await delay(100);
  check('Visibility resume owns exactly one animation loop', (await s.evaluate(() => window.__metrics())).pending === 1);
  await s.emulateMedia({ reducedMotion: 'reduce' }); await delay(100);
  const pixels = await s.locator('canvas').evaluate((e) => e.toDataURL()); await delay(200);
  check('Reduced motion: one static frame, no pending frames', pixels === (await s.locator('canvas').evaluate((e) => e.toDataURL())) && (await s.evaluate(() => window.__metrics())).pending === 0);
  check('Reduced motion disables CSS transitions', parseFloat(await s.locator('.btn').first().evaluate((e) => getComputedStyle(e).transitionDuration)) < 0.01);
  check('Canvas backing store stays below 1.8M pixels', (await s.locator('canvas').evaluate((e) => e.width * e.height)) <= 1804000);
  const before = await s.evaluate(() => window.__metrics());
  await s.locator('.desktop-sidebar').getByRole('link', { name: 'Missions', exact: true }).click(); await s.waitForURL(/\/topics$/);
  check('SPA navigation keeps one background and one listener set', (await s.locator('.ambient-background').count()) === 1 && JSON.stringify(await s.evaluate(() => window.__metrics())) === JSON.stringify(before));
  await s.getByRole('button', { name: 'Log out', exact: true }).click(); await s.waitForURL(/\/login$/); await s.locator('input[type=email]').waitFor();
  check('Logout shell transition leaves one canvas and one listener set', (await s.locator('canvas').count()) === 1 && (await s.evaluate(() => window.__metrics())).resize === 1 && (await s.evaluate(() => window.__metrics())).visibility === 1);
  await cs.close();

  // ---- C. administrator: quiet console background, unchanged navigation
  const cad = await context('admin', { reducedMotion: 'reduce' }), ad = await cad.newPage();
  await go(ad, '/admin', '.grid-3');
  await shot(ad, 'admin-overview-1440');
  check('Admin console has no particle canvas (quiet gradient only)', (await ad.locator('canvas').count()) === 0 && (await ad.locator('.ambient-background.ambient-quiet[aria-hidden=true]').count()) === 1);
  check('Admin navigation labels unchanged', (await ad.locator('.desktop-sidebar nav a').allInnerTexts()).join('|') === 'Overview|Students|Topics|Questions|Badges|Audit');
  check('Admin sidebar eyebrow reads ADMINISTRATION', (await ad.locator('.nav-label').innerText()) === 'ADMINISTRATION');
  for (const width of [1440, 390, 320]) { await ad.setViewportSize({ width, height: 900 }); await go(ad, '/admin', '.grid-3'); check(`Admin overview ${width}px: no horizontal overflow`, await noOverflow(ad)); }
  await cad.close();

  // ---- D. canvas unavailable / DPR budget
  const cf = await context('anon', { reducedMotion: 'reduce' }); await cf.addInitScript(() => { HTMLCanvasElement.prototype.getContext = () => null; });
  const f = await cf.newPage(); await go(f, '/login', 'input[type=email]');
  check('Unavailable canvas keeps static decoration and a usable form', (await f.locator('.ambient-static').isVisible()) && (await f.getByRole('button', { name: 'Log in', exact: true }).isEnabled()));
  await cf.close();
  const cr = await context('anon', { deviceScaleFactor: 3, reducedMotion: 'reduce' }), r = await cr.newPage(); await go(r, '/login', 'input[type=email]');
  check('DPR3 desktop canvas is pixel-budget capped', await r.locator('canvas').evaluate((e) => e.width * e.height <= 1804000 && e.width < innerWidth * 1.5));
  await r.setViewportSize({ width: 390, height: 844 }); await delay(100);
  check('DPR3 mobile canvas is capped at DPR 1', await r.locator('canvas').evaluate((e) => e.width === 390 && e.height === 844));
  await cr.close();

  check('No uncaught browser errors', errors.length === 0);
  check('No external network requests (fonts, CDNs, images)', external.length === 0);
  console.log(`${checks} Phase 2 shell checks passed — production build, SIMULATED API responses (UI evidence only, not live integration).`);
} finally {
  await browser?.close();
  preview.kill();
}
