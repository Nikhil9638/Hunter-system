// Phase 7 checks: login/registration/not-found pages and the administrator presentation
// (ranks, placement record, Mana wording, chips), against the REAL production build.
// ALL API responses are SIMULATED in-browser; the preview proxy points at an unreachable
// port, so nothing reaches Apache/dev. UI evidence only — never reported as API integration.
// Run from frontend/ after `npm run build`: node tests/admin-auth.mjs [--shots <dir>]
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4200, BASE = `http://localhost:${PORT}/hunter-system`;
const SHOTS = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const shot = async (p, name, fullPage = false) => { if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage }); };
let checks = 0;
const check = (name, ok, detail = '') => { assert.ok(ok, `${name}${detail ? ` (${detail})` : ''}`); checks++; console.log('PASS ' + name); };

const seed = readFileSync('../database/seed.sql', 'utf8');
const TITLES = [...seed.matchAll(/^\s*\(\s*(\d+),\s*\d+,\s*'[a-z0-9-]+',\s*'([^']+)'/gm)].map((m) => m[2].trim());
const NOW = '2026-10-18T10:00:00.000Z';
const STUDENT = { id: 1, email: 'sim.student@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: 'assessment_submitted' };
const NEW_STUDENT = { ...STUDENT, id: 9, onboarding_state: 'not_started' };
const ADMIN = { id: 2, email: 'sim.admin@example.invalid', display_name: 'Sim Admin', role: 'admin' };
const PLACEMENT = { rank: 'C', correct_count: 6, submitted_at: NOW, recommended_topic: { id: 7, title: TITLES[6] }, offered_topic: null, policy_version: 2 };   // policy_version: an extra field

function simulate(s, method, path, search, body) {
  if (path === '/auth/me') return s.user ? [200, { user: s.user, csrf_token: 'simulated' }] : [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (path === '/auth/login') { s.posts.push({ path, body }); if (s.loginReply) return s.loginReply; s.user = STUDENT; return [200, { user: STUDENT, csrf_token: 'simulated' }]; }   // success starts a session
  if (path === '/auth/register') { s.posts.push({ path, body }); if (s.registerReply) return s.registerReply; s.user = NEW_STUDENT; return [201, { user: NEW_STUDENT, csrf_token: 'simulated' }]; }
  if (path === '/auth/logout') { s.user = null; return [200, { logged_out: true }]; }
  if (!s.user) return [401, { error: { code: 'not_authenticated', message: 'Not logged in.' } }];
  if (path === '/experience-levels') return [200, [{ id: 1, code: 'complete_beginner', label: 'Complete beginner' }]];
  if (path === '/me/dashboard') return s.expireDashboard ? [401, { error: { code: 'session_expired', message: 'Session expired.' } }]
    : [200, { display_name: 'Sim Student', onboarding_state: 'assessment_submitted', experience_level: null, placement_rank: { code: 'E' }, hunter_rank: { code: 'E' }, assessment: { state: 'submitted' },
      recommended_topic: null, offered_topic: null, current_topic: null, coins: 0, topics_passed: 0, topics_total: 18, next_rank: null }];
  if (path === '/quiz-attempts/77') return [200, { attempt_id: 77, topic_id: 2, attempt_number: 1, status: 'in_progress', question_count: 5, answered_count: 0, questions: [
    { position: 1, stem: 'Simulated?', code_snippet: null, choices: [{ id: 1, label: 'A', text: 'One' }, { id: 2, label: 'B', text: 'Two' }], selected_choice_id: null }] }];
  // ---- administrator
  if (path === '/admin/topics') return [200, TITLES.map((title, i) => ({ id: i + 1, position: i + 1, slug: `t${i + 1}`, title, summary: '', skill_group: 'G1', status: i < 2 ? 'available' : 'draft', version: 'v1',
    lesson: i < 2 ? { status: 'published', revision: 1 } : null, lesson_published: i < 2, quiz_pool_size: i < 2 ? 12 : 0, required_pool_size: 10, content_complete: i < 2, is_ready: i < 2, students_unlocked: 1 }))];
  if (path === '/admin/badges') return [200, [{ id: 9, slug: 'shadow-recruit', name: 'Shadow Recruit', description: 'Simulated.', image_ref: 'badges/m8b-shadow-recruit-v2.png', image_url: '/hunter-system/assets/badges/m8b-shadow-recruit-v2.png',
    price: 10, price_status: 'provisional', status: 'off_sale', version: 'v9', owners: 3, price_approval: null }]];
  if (path === '/admin/questions') return [200, { questions: [], page: 1, per_page: 1, total: 216 }];
  if (path === '/admin/students') return [200, { students: [
    { user_id: 4, display_name: 'Placed Student', email: 'p@example.invalid', account_status: 'active', last_successful_login_at: NOW, last_activity_at: NOW, recently_active: true, hunter_rank: 'D-Rank', current_topic: TITLES[3], topics_passed: 3, topics_total: 18 },
    { user_id: 5, display_name: 'New Student', email: 'n@example.invalid', account_status: 'active', last_successful_login_at: NOW, last_activity_at: NOW, recently_active: false, hunter_rank: null, current_topic: null, topics_passed: 0, topics_total: 18 }],
    page: 1, per_page: 20, total: 2 }];
  const m = /^\/admin\/students\/(\d+)(\/logins)?$/.exec(path);
  if (m && m[2]) return [200, { events: [{ at: NOW, outcome: 'success' }, { at: NOW, outcome: 'wrong_password' }], events_total: 2, sessions: [{ started_at: NOW, last_activity_at: NOW, ended_at: null, end_reason: null }], sessions_total: 1, page: 1, per_page: 20 }];
  if (m) return [200, { user_id: +m[1], display_name: +m[1] === 4 ? 'Placed Student' : 'New Student', email: 'x@example.invalid', account_status: 'active', registered_at: NOW, last_successful_login_at: NOW, last_activity_at: NOW,
    recently_active: false, onboarding_state: +m[1] === 4 ? 'assessment_submitted' : 'not_started', experience_level: +m[1] === 4 ? { code: 'basic', label: 'Knows a little basic Python' } : null,
    placement: +m[1] === 4 ? PLACEMENT : null, hunter_rank: +m[1] === 4 ? 'D' : null, topics_passed: +m[1] === 4 ? 3 : 0, topics_total: 18,
    topics: [{ id: 1, position: 1, title: TITLES[0], status: 'passed', unlocked: true, unlock_source: 'placement', lesson_visits: 2, completed_attempts: 1, best_correct: 4, first_passed_at: NOW }],
    rank_history: +m[1] === 4 ? [{ at: NOW, reason: 'placement', from: null, to: 'C', topics_passed: null }, { at: NOW, reason: 'promotion', from: 'C', to: 'D', topics_passed: 3 }] : [] }];
  if (path === '/admin/login-events') return [200, { events: [{ id: 2, at: NOW, outcome: 'unknown_account', user: null }, { id: 1, at: NOW, outcome: 'account_deactivated', user: { id: 4, display_name: 'Placed Student', role: 'student' } }], page: 1, per_page: 20, total: 2 }];
  if (path === '/admin/audit-log') return [200, { entries: [{ id: 51, at: NOW, admin: { id: 2, display_name: 'Sim Admin' }, action: 'update', operation: 'approve_price', entity_type: 'badge', entity_id: 9,
    before: { price_coins: 10, price_status: 'provisional' }, after: { operation: 'approve_price', price_coins: 10, price_status: 'approved' } }], page: 1, per_page: 20, total: 1 }];
  if (method !== 'GET') { s.writes.push(`${method} ${path}`); return [409, { error: { code: 'conflict', message: 'Simulated: writes are not exercised here.' } }]; }
  return [404, { error: { code: 'not_found', message: 'Simulated: not found.' } }];
}

const preview = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', 'localhost', '--port', String(PORT), '--strictPort'],
  { cwd: process.cwd(), stdio: 'ignore', env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:9' } });
let browser;
const errors = [], external = [];
try {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(BASE + '/login')).ok) break; } catch { /* starting */ } await delay(100); }
  browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  async function context(user, options = {}) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
    c.sim = { user, posts: [], writes: [] };
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const req = route.request(), u = new URL(req.url()), path = u.pathname.split('/api/v1')[1];
      const [status, body] = simulate(c.sim, req.method(), path, u.search, req.postData() ? JSON.parse(req.postData()) : undefined);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    await c.route('**/hunter-system/assets/badges/*.png', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: readFileSync(`../assets/badges/${new URL(route.request().url()).pathname.split('/').pop()}`) }));
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) external.push(r.url()); }); });
    return c;
  }
  const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  const flat = (t) => t.replace(/\s+/g, ' ');

  // ==== 1. login
  const c1 = await context(null), l = await c1.newPage();
  await l.goto(BASE + '/login'); await l.getByRole('heading', { name: 'Log in', exact: true }).waitFor(); await shot(l, 'login-1440');
  check('Login: heading, lead, labelled email/password with autocomplete, password masked', (await l.getByLabel('Email').getAttribute('autocomplete')) === 'email'
    && (await l.getByLabel('Password').getAttribute('type')) === 'password' && (await l.getByLabel('Password').getAttribute('autocomplete')) === 'current-password'
    && /Hunter Portal/.test(await l.locator('main').innerText()));
  for (let i = 0; i < 6 && (await l.evaluate(() => document.activeElement.id)) !== 'email'; i++) await l.keyboard.press('Tab');   // the shell focuses the h1 first
  const order = [];
  for (let i = 0; i < 3; i++) { order.push(await l.evaluate(() => document.activeElement.id || document.activeElement.textContent)); await l.keyboard.press('Tab'); }
  check(`Login keyboard order: ${order.join(' → ')}`, order.join('|') === 'email|password|Log in');
  c1.sim.loginReply = [401, { error: { code: 'invalid_credentials', message: 'Email or password is incorrect.' } }];
  await l.getByLabel('Email').fill('sim@example.invalid'); await l.getByLabel('Password').fill('wrong password here');
  await l.getByRole('button', { name: 'Log in', exact: true }).click(); await l.getByRole('alert').filter({ hasText: 'Email or password is incorrect.' }).waitFor();
  check('Login: server message shown for invalid credentials; request body is exactly {email, password}', JSON.stringify(Object.keys(c1.sim.posts[0].body)) === '["email","password"]');
  c1.sim.loginReply = [429, { error: { code: 'too_many_attempts', message: 'Too many.', details: { retry_after_seconds: 900 } } }];
  await l.getByRole('button', { name: 'Log in', exact: true }).click(); await l.getByText('Too many attempts. Try again in about 15 minutes.').waitFor();
  check('Login: throttling message unchanged ("about 15 minutes")', true);
  c1.sim.loginReply = null;
  await l.getByRole('button', { name: 'Log in', exact: true }).click(); await l.waitForURL(/\/dashboard$/);
  check('Login success: Public guard routes the student to /dashboard', true);
  c1.sim.expireDashboard = true; c1.sim.user = STUDENT;
  await l.goto(BASE + '/dashboard'); await l.waitForURL(/\/login$/); await l.getByText('Session ended').waitFor();
  check('Session expiry: protected page returns to /login with the unchanged 30-minute notice', /30 minutes without activity/.test(await l.locator('main').innerText()));
  for (const width of [1440, 390, 320]) { await l.setViewportSize({ width, height: 900 }); await delay(80); check(`Login ${width}px: no horizontal overflow`, await noOverflow(l)); }
  await c1.close();

  // ==== 2. registration
  const c2 = await context(null), r = await c2.newPage();
  await r.goto(BASE + '/register'); await r.getByRole('heading', { name: 'Create your account', exact: true }).waitFor(); await shot(r, 'register-1440');
  c2.sim.registerReply = [400, { error: { code: 'validation_failed', message: 'Invalid.', details: { fields: { display_name: 'required', email: 'invalid_format', password: 'too_short' } } } }];
  await r.getByRole('button', { name: 'Create account', exact: true }).click(); await r.locator('.field-error').nth(2).waitFor();
  check('Register: validation messages next to each field (unchanged texts)', (await r.locator('.field-error').allInnerTexts()).join('|') === 'This field is required.|Enter a valid email address.|Use at least 12 characters.'
    && (await r.getByLabel('Password').getAttribute('aria-invalid')) === 'true');
  c2.sim.registerReply = [409, { error: { code: 'email_taken', message: 'Taken.' } }];
  await r.getByLabel('Display name').fill('Sim'); await r.getByLabel('Email').fill('sim@example.invalid'); await r.getByLabel('Password').fill('a long enough password');
  await r.getByRole('button', { name: 'Create account', exact: true }).click(); await r.getByText('An account with this email already exists.').waitFor();
  check('Register: duplicate email message unchanged; password hint and autocomplete kept', (await r.getByLabel('Password').getAttribute('autocomplete')) === 'new-password'
    && /At least 12 characters/.test(await r.locator('main').innerText()));
  c2.sim.registerReply = null;
  await r.getByRole('button', { name: 'Create account', exact: true }).click(); await r.waitForURL(/\/start$/);
  check('Register success: body is exactly {email, display_name, password}; guard continues to /start', JSON.stringify(Object.keys(c2.sim.posts.at(-1).body)) === '["email","display_name","password"]');
  await r.goto(BASE + '/login'); await r.waitForURL(/\/start$/);
  check('Signed-in visitor opening /login is redirected by the unchanged Public guard', true);
  await c2.close();

  // ==== 3. not found + quiz breadcrumb
  const c3 = await context(STUDENT), n = await c3.newPage();
  await n.goto(BASE + '/no-such-page'); await n.getByRole('heading', { name: 'Page not found', exact: true }).waitFor(); await shot(n, 'not-found-1440');
  const startHref = await n.getByRole('link', { name: 'Go to the start' }).evaluate((x) => x.pathname);
  check('Not found: themed notice with the unchanged "Go to the start" link to the app root', ['/hunter-system', '/hunter-system/'].includes(startHref) && /404/.test(await n.locator('main').innerText()), startHref);
  for (const width of [390, 320]) { await n.setViewportSize({ width, height: 800 }); await delay(80); check(`Not found ${width}px: no horizontal overflow`, await noOverflow(n)); }
  await n.setViewportSize({ width: 1440, height: 900 });
  await n.goto(BASE + '/quiz/77'); await n.getByRole('group', { name: /^Question 1 of/ }).waitFor();
  check('Quiz breadcrumb reads "Missions" (was "Python learning")', (await n.locator('.breadcrumbs strong').innerText()) === 'Missions');
  await c3.close();

  // ==== 4. administrator presentation
  const c4 = await context(ADMIN, { reducedMotion: 'reduce' }), a = await c4.newPage();
  await a.goto(BASE + '/admin'); await a.getByRole('heading', { name: 'Administrator overview', exact: true }).waitFor(); await shot(a, 'admin-overview-1440');
  check('Overview: six tiles, readiness exact text "2 / 18" kept (admin-readonly locator)', (await a.locator('.admin-tile').count()) === 6 && await a.getByText('2 / 18', { exact: true }).isVisible());
  check('Admin keeps the quiet console: no particle canvas', (await a.locator('canvas').count()) === 0);
  await a.goto(BASE + '/admin/students'); await a.locator('tbody tr').first().waitFor(); await shot(a, 'admin-students-1440');
  const rows = await a.locator('tbody tr').allInnerTexts();
  check('Students list: "D-Rank" (API list format) shown as "D-Rank · Apprentice"; null rank "Unranked"; activity chips', flat(rows[0]).includes('D-Rank · Apprentice') && flat(rows[1]).includes('Unranked')
    && flat(rows[0]).includes('Recently active') && flat(rows[1]).includes('Not recently active'));
  await a.locator('tbody a').first().click(); await a.getByRole('heading', { name: 'Student details', exact: true }).waitFor(); await a.getByRole('heading', { name: 'Sessions', exact: true }).waitFor();
  await shot(a, 'admin-student-1440', true);
  const det = flat(await a.locator('.admin-student').innerText()).toLowerCase();   // <dt> labels are CSS-uppercased; compare case-insensitively
  check('Student detail: "D" (API detail format) shown as "D-Rank · Apprentice"', det.includes('hunter rank') && det.includes('d-rank · apprentice'));
  check('Placement as labelled fields: rank C-Rank · Squire, 6 correct, recommended "7. <title>", offered "—"', det.includes('placement rank c c-rank · squire') && det.includes('correct answers 6')
    && det.includes(`recommended topic 7. ${TITLES[6]}`.toLowerCase()) && det.includes('offered topic —'));
  check('Unknown placement field is still listed (policy version 2), not discarded', det.includes('policy version 2'));
  await a.locator('.admin-raw summary').click();
  const raw = JSON.parse(await a.locator('.admin-raw .admin-values').innerText());
  check('"Raw record" contains every original placement value unchanged', JSON.stringify(raw) === JSON.stringify(PLACEMENT));
  const main = flat(await a.locator('main').innerText());
  check('Rank history uses mapped ranks; login outcomes readable; session labels unchanged', main.includes('C-Rank · Squire') && /Success/.test(main) && /Wrong password/.test(main) && /Valid session/.test(main));
  await a.goto(BASE + '/admin/students/5'); await a.getByRole('heading', { name: 'Student details', exact: true }).waitFor();
  check('No placement: "Not placed yet" (no fabricated values); unranked student', flat(await a.locator('.admin-student').innerText()).includes('Not placed yet') && flat(await a.locator('.admin-student').innerText()).includes('Unranked'));
  await a.goto(BASE + '/admin/login-events'); await a.locator('table').waitFor();
  check('Login events: outcome chips "Unknown account" / "Account deactivated"', /Unknown account/.test(await a.locator('tbody').innerText()) && /Account deactivated/.test(await a.locator('tbody').innerText()));
  await a.goto(BASE + '/admin/badges'); await a.getByRole('heading', { name: 'Badge management' }).waitFor();
  check('Badges list: price "10 Mana", approval and status chips', /10 Mana/.test(await a.locator('tbody').innerText()) && (await a.locator('tbody .admin-chip').count()) === 2);
  await a.goto(BASE + '/admin/badges/9'); await a.getByLabel('Badge name', { exact: true }).waitFor(); await shot(a, 'admin-badge-1440', true);
  check('Badge editor: "Price in Mana" field, summary in Mana, real art preview, raw status text kept ("off_sale")', (await a.getByLabel('Price in Mana').inputValue()) === '10'
    && /Price: 10 Mana/.test(await a.locator('main').innerText()) && /off_sale/.test(await a.locator('main').innerText())
    && (await a.locator('.admin-badge-summary img').evaluate(async (i) => { await i.decode(); return i.naturalWidth === 512; })));
  let confirmText = null;
  a.once('dialog', (d) => { confirmText = d.message(); d.dismiss(); });
  await a.getByRole('button', { name: 'Approve current price: 10 Mana' }).click(); await delay(200);
  check('Approval keeps the native confirmation, now in Mana; dismissing sends no request', confirmText === 'Explicitly approve the current price of 10 Mana for Shadow Recruit?' && c4.sim.writes.length === 0, confirmText);
  for (const url of ['/admin', '/admin/students', '/admin/students/4', '/admin/topics', '/admin/badges', '/admin/badges/9']) {
    await a.goto(BASE + url); await a.locator('main h1').waitFor(); await delay(150);
    check(`${url}: no visible "coins" wording`, !/\bcoins?\b/i.test(await a.locator('main').innerText()));
  }
  await a.goto(BASE + '/admin/audit'); await a.locator('article').first().waitFor();
  check('Audit: entries keep literal before/after values (API field names such as price_coins are data, unchanged)', /price_coins/.test(await a.locator('article').innerText()));
  for (const width of [390, 320]) {
    await a.setViewportSize({ width, height: 900 });
    for (const url of ['/admin', '/admin/students/4', '/admin/badges/9', '/admin/audit']) { await a.goto(BASE + url); await a.locator('main h1').waitFor(); await delay(100); check(`Admin ${url} ${width}px: no horizontal overflow`, await noOverflow(a)); }
  }
  await c4.close();

  check('No external requests', external.length === 0, external.join(' | '));
  check('No uncaught browser errors', errors.length === 0, errors.join(' | '));
  console.log(`${checks} Phase 7 auth/admin presentation checks passed — production build, SIMULATED API responses (UI evidence only, not live integration).`);
} finally {
  await browser?.close();
  preview.kill();
}
