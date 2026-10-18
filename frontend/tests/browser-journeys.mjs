// Real-browser journeys for the student interface (Chrome, headless, via playwright-core).
//
//   Part A: the production build served by Apache (http://localhost/hunter-system/),
//           API on hunter_system_dev. Creates b6.<run>.*@example.invalid students and
//           one admin; never changes course content or badges.
//   Part B: the same build served by `vite preview` (port 4173) proxying to the
//           ISOLATED fixture API (127.0.0.1:8098) for purchases and the showcase.
//           Requires the two fixture servers to be running.
//
//   node tests/browser-journeys.mjs [--isolated] [--shots <dir>]
// --isolated uses a seed-profile fixture preview for Part A; Part B uses the
// preserved complete fixture. Both backends must attest their exact identities.
import { chromium } from 'playwright-core';
import { execFileSync, spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CHROME = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PHP = 'C:/xampp/php/php.exe';
const HELPER = path.join(HERE, 'db-helper.php');
const FIXTURE_DIR = process.env.HUNTER_FIXTURE_PRIVATE_DIR || 'C:\\xampp\\hunter-system-fixture-private';
const ISOLATED = process.argv.includes('--isolated');
const APACHE = ISOLATED ? 'http://localhost:4172/hunter-system' : 'http://localhost/hunter-system';
const PREVIEW = 'http://localhost:4173/hunter-system';
const RUN = new Date().toISOString().slice(11, 19).replace(/:/g, '') + Math.random().toString(16).slice(2, 6);
const PW = 'browser journey password';
const shotsArg = process.argv.indexOf('--shots');
const SHOTS = shotsArg > 0 ? process.argv[shotsArg + 1] : null;

const results = [];
function check(name, ok, detail = '') {
  results.push(!!ok);
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '   ' + detail : ''}`);
}
function helper(fixture, ...args) {
  const env = { ...process.env };
  delete env.HUNTER_TEST_DB_ADMIN_PASSWORD;
  if (fixture) env.HUNTER_PRIVATE_DIR = FIXTURE_DIR;
  return JSON.parse(execFileSync(PHP, [HELPER, ...args.map(String)], { env }).toString());
}
async function shot(page, name) {
  if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
}
/** Question position buttons of the runner (placement check and topic quizzes). */
const questionMap = (page) => page.getByRole('navigation', { name: 'Questions' }).getByRole('button');
/** API calls in the browser context (shares the cookie jar), for setup steps only. */
function apiFor(context, base) {
  let csrf = null;
  return async (method, p, body) => {
    const headers = {};
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
    const res = await context.request.fetch(`${base}/api/v1${p}`, { method, headers, data: body === undefined ? undefined : JSON.stringify(body) });
    const json = await res.json().catch(() => null);
    if (json?.data?.csrf_token) csrf = json.data.csrf_token;
    if (res.status() >= 400) throw new Error(`${method} ${p} -> ${res.status()} ${json?.error?.code}`);
    return json?.data;
  };
}
async function placeViaApi(api, fixture, email, rightGroups) {
  await api('PUT', '/me/experience', { experience_level_id: 1 });
  const a = await api('POST', '/assessment');
  const key = helper(fixture, 'answer-key', a.attempt_id);
  for (const [pos, k] of Object.entries(key)) {
    await api('PUT', `/assessment/${a.attempt_id}/answers/${pos}`, { choice_id: rightGroups.includes(k.group) ? k.right : k.wrong });
  }
  await api('POST', `/assessment/${a.attempt_id}/submit`);
}
async function answerQuizInUi(page, fixture, nCorrect) {
  const attemptId = Number(page.url().match(/quiz\/(\d+)/)[1]);
  const key = helper(fixture, 'answer-key', attemptId);
  for (let pos = 1; pos <= 5; pos++) {
    await page.locator(`input[type=radio][value="${pos <= nCorrect ? key[pos].right : key[pos].wrong}"]`).check();
    await page.locator('.save-saved').waitFor();
    if (pos < 5) await page.getByRole('button', { name: 'Next question' }).click();
  }
  return attemptId;
}
const heading = (page) => page.locator('h1').first().innerText();

let seedPreview;
if (ISOLATED) {
  const seedIdentity=helper(false,'test-identity');
  const fixtureIdentity=helper(true,'fixture-identity');
  for (const [url, expected] of [['http://127.0.0.1:8097',seedIdentity],['http://127.0.0.1:8098',fixtureIdentity]]) {
    const actual=await fetch(url+'/hunter-system/api/v1/__test/identity').then(r=>r.json());
    if (JSON.stringify(actual.data)!==JSON.stringify(expected)) throw new Error('Refusing backend/helper identity mismatch');
  }
  seedPreview=spawn(process.execPath,[path.join(HERE,'..','node_modules','vite','bin','vite.js'),'preview','--port','4172','--strictPort'],{
    cwd:path.join(HERE,'..'),env:{...process.env,HUNTER_API_TARGET:'http://127.0.0.1:8097'},stdio:'ignore',
  });
  for(let i=0;i<40;i++){try{if((await fetch(APACHE+'/api/v1/health')).ok)break;}catch{}await sleep(100);}
  const proxiedIdentity=await fetch(APACHE+'/api/v1/__test/identity').then(r=>r.json());
  if(JSON.stringify(proxiedIdentity.data)!==JSON.stringify(seedIdentity)) throw new Error('Refusing unexpected seed preview proxy');
}
const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const pageErrors = [];
const watch = (page) => page.on('pageerror', (e) => pageErrors.push(e.message));

try {
  console.log(`Hunter System browser journeys  run=${RUN}  chrome=${(await browser.version())}  target=${ISOLATED?'real seed/complete fixture APIs through Vite previews':'Apache/dev + complete fixture'}\n`);

  // =========================================================================
  // Part A — Apache + production build + hunter_system_dev
  // =========================================================================
  const ctxA = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctxA.newPage();
  watch(page);

  await page.goto(`${APACHE}/topics/1/lesson`);
  await page.waitForURL(/\/login$/);
  check(`A1 direct navigation to a protected deep link under ${ISOLATED?'the seed fixture preview':'Apache'} (refresh) loads the app and routes an anonymous visitor to /login`,
    (await heading(page)) === 'Log in');

  // registration through the UI
  const emailA = `b6.${RUN}.a@example.invalid`;
  await page.getByRole('link', { name: 'Create an account' }).click();
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.locator('.field-error').first().waitFor();
  check('A2 registration validation from the API shown next to the fields (empty form)',
    await page.locator('.field-error').count() >= 2);
  await page.getByLabel('Display name').fill('Browser A');
  await page.getByLabel('Email').fill(emailA);
  await page.getByLabel('Password').fill(PW);
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.waitForURL(/\/start$/);
  // Phase 4: themed experience cards are a native radio group named "Experience level".
  const experience = page.getByRole('group', { name: 'Experience level' }).getByRole('radio');
  await experience.first().waitFor({ state: 'attached' });
  const levels = await experience.count();
  await page.getByRole('radio', { name: /Complete beginner/ }).check();
  await page.getByRole('button', { name: 'Confirm and begin placement check' }).click();
  await page.waitForURL(/\/assessment$/);
  await page.getByRole('group', { name: /^Question \d+ of 10/ }).waitFor();
  check('A3 register -> experience (4 options) -> placement check with 10 questions', levels === 4 && (await questionMap(page).count()) === 10);
  check('A4 nothing stored in localStorage/sessionStorage; the session cookie is HttpOnly (invisible to scripts)',
    await page.evaluate(() => localStorage.length === 0 && sessionStorage.length === 0 && !document.cookie.includes('HSSESSID')));

  const aid = helper(false, 'open-assessment', emailA);
  const akey = helper(false, 'answer-key', aid);
  const pick = (pos) => (akey[pos].group === 1 ? akey[pos].right : akey[pos].wrong);   // G1 right -> E, recommended topic 4
  await page.locator(`input[type=radio][value="${pick(1)}"]`).check();
  await page.locator('.save-saved').waitFor();
  await page.reload();
  await page.getByRole('group', { name: /^Question \d+ of 10/ }).waitFor();
  const opensAt = await page.locator('.q-count').innerText();                // resumes at the first unanswered question
  await questionMap(page).first().click();
  check('A5 refresh resumes the server-owned attempt: "Welcome back", opens at question 2, question 1 still selected',
    (await page.getByText(/Welcome back/).count()) === 1 && /Question 2 of 10/.test(opensAt)
    && (await page.locator(`input[value="${pick(1)}"]`).isChecked()));

  // answer-save failure on question 2
  await questionMap(page).nth(1).click();
  await page.route('**/api/v1/assessment/*/answers/2', (route) => route.abort('failed'));
  await page.locator(`input[type=radio][value="${pick(2)}"]`).check();
  await page.locator('.save-error').waitFor();
  const nextDisabled = await page.getByRole('button', { name: 'Next question' }).isDisabled();
  const mapDisabled = await questionMap(page).nth(5).isDisabled();
  await page.unroute('**/api/v1/assessment/*/answers/2');
  await page.getByRole('button', { name: 'Retry saving' }).click();
  await page.locator('.save-saved').waitFor();
  check('A6 a failed answer save shows "Not saved", blocks Next and jumping elsewhere; Retry saves it and unblocks',
    nextDisabled && mapDisabled && !(await page.getByRole('button', { name: 'Next question' }).isDisabled()));

  for (let pos = 3; pos <= 9; pos++) {
    await questionMap(page).nth(pos - 1).click();
    await page.locator(`input[type=radio][value="${pick(pos)}"]`).check();
    await page.locator('.save-saved').waitFor();
  }
  // the last answer is slow: Submit must wait for it
  let putDone = 0;
  let submitSent = 0;
  await page.route('**/api/v1/assessment/*/answers/10', async (route) => { await sleep(1500); await route.continue(); });
  page.on('requestfinished', (r) => { if (/answers\/10$/.test(r.url())) putDone = Date.now(); });
  page.on('request', (r) => { if (/assessment\/\d+\/submit$/.test(r.url())) submitSent = Date.now(); });
  await questionMap(page).nth(9).click();
  await page.locator(`input[type=radio][value="${pick(10)}"]`).check();
  await page.getByRole('button', { name: 'Submit placement check' }).click();
  await page.waitForURL(/\/assessment\/result$/);
  await page.unroute('**/api/v1/assessment/*/answers/10');
  check('A7 Submit clicked while the last answer was still saving: the submission was sent only after that save finished',
    putDone > 0 && submitSent > 0 && submitSent >= putDone, `save done +${submitSent - putDone} ms before submit`);

  await page.getByText(/Starting point from a short placement check/).waitFor();
  const resultText = await page.locator('main').innerText();
  check('A8 placement result: E-Rank, "not proof of mastery" wording, recommended topic 4 "content coming soon", offered topic 2, 10 reviewed answers with explanations',
    /E-Rank/.test(resultText) && /not proof of mastery/i.test(resultText) && /content coming soon/.test(resultText)
    && /Available now/.test(resultText) && (await page.locator('.review-item').count()) === 10 && (await page.locator('.explanation').count()) >= 1);
  await shot(page, 'a-placement-result');

  await page.goto(`${APACHE}/topics`);
  await page.locator('.roadmap-item').first().waitFor();
  const t3 = await page.locator('.roadmap-item').nth(2).innerText();
  const t5 = await page.locator('.roadmap-item').nth(4).innerText();
  check('A9 roadmap distinguishes "Unlocked: content coming soon" (topic 3) from "Locked" (topic 5) with text, not colour only',
    /content coming soon/.test(t3) && /Locked/.test(t5));
  await page.goto(`${APACHE}/topics/3/lesson`);
  await page.getByText('This lesson is not available yet.').waitFor();
  check('A10 a lesson of an unlocked topic without content shows a clear "not available yet" message', true);
  await page.goto(`${APACHE}/store`);
  await page.getByText('No badges for sale yet').waitFor();
  check('A11 the live store is empty (valid state, explained); no fabricated badges', (await page.locator('.badge-card').count()) === 0);
  await shot(page, 'a-empty-store');

  // ---- student B: quiz journey (placed E via API: only topic 1 unlocked)
  const ctxB = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const pb = await ctxB.newPage();
  watch(pb);
  const emailB = `b6.${RUN}.b@example.invalid`;
  const apiB = apiFor(ctxB, APACHE);
  await apiB('POST', '/auth/register', { email: emailB, display_name: 'Browser B', password: PW });
  await placeViaApi(apiB, false, emailB, []);
  await pb.goto(`${APACHE}/topics/1`);
  await pb.getByRole('link', { name: 'Read the lesson first' }).click();
  await pb.locator('.prose').waitFor();
  const code = await pb.locator('.prose pre.code-block').first().innerText();
  check('A12 lesson renders Markdown safely: headings, lists and a code block with the exact Python text (no HTML injection)',
    code.includes('print("Hello, World!")') && (await pb.locator('.prose h2, .prose h3').count()) >= 3 && (await pb.locator('.prose ul li').count()) >= 3
    && (await pb.locator('.prose script').count()) === 0 && (await pb.locator('h1').count()) === 1);
  await shot(pb, 'b-lesson');
  await pb.getByRole('button', { name: 'Start the quiz' }).click();
  await pb.waitForURL(/\/quiz\/\d+$/);
  const firstQuizText = await pb.locator('main').innerText();
  check('A13 quiz before submission shows no answers or explanations', !/correct answer|Why:/i.test(firstQuizText));
  await answerQuizInUi(pb, false, 2);
  await shot(pb, 'b-quiz');
  await pb.getByRole('button', { name: 'Submit quiz' }).click();
  await pb.getByText(/Quiz performance: D/).waitFor();
  const fail = await pb.locator('main').innerText();
  check('A14 failed first completion: grade D, not passed, "+10 coins" for the first completed quiz, nothing unlocked',
    /not passed/.test(fail) && /\+10 coins/.test(fail) && /Pass to unlock the next topic/.test(fail) && (await pb.locator('.review-item').count()) === 5);
  await pb.getByRole('button', { name: 'Try again' }).click();
  await pb.waitForURL(/\/quiz\/\d+$/);
  await pb.getByRole('group', { name: /^Question \d+ of 5/ }).waitFor();
  await answerQuizInUi(pb, false, 4);
  await pb.getByRole('button', { name: 'Submit quiz' }).click();
  await pb.getByText(/Quiz performance: B/).waitFor();
  const pass = await pb.locator('main').innerText();
  check('A15 passing retry: grade B, passed, coins explained as already paid, "Unlocked: Variables and assignment"',
    /— passed/.test(pass) && /already been paid/.test(pass) && /Unlocked: Variables and assignment/.test(pass));
  await shot(pb, 'b-quiz-result');
  await pb.locator('main').getByRole('link', { name: 'Dashboard' }).click();
  await pb.getByRole('heading', { name: 'Topics passed' }).waitFor();
  const dash = await pb.locator('main').innerText();
  check('A16 dashboard refreshed from the API: 10 coins, 1 of 18 topics passed, rank E, current topic 2',
    /\b10\b/.test(dash) && /1\s*\/ 18/.test(dash) && /E-Rank/.test(dash) && /Variables and assignment/.test(dash), dash.replace(/\s+/g, ' ').slice(0, 160));
  await shot(pb, 'b-dashboard');
  await pb.goto(`${APACHE}/history`);
  await pb.getByText('Starting rank from the placement check').waitFor();
  // Rank and coin panels load independently; wait for the ledger under test.
  await pb.locator('.ledger li').first().waitFor();
  check('A17 rank & coin history: placement entry and the +10 first-completion credit',
    (await pb.locator('.ledger li').count()) === 1 && /\+10/.test(await pb.locator('.ledger').innerText()));
  await pb.goto(`${APACHE}/profile`);
  await pb.getByText("You don't own any badges yet.").waitFor();
  check('A18 profile: own ranks/coins, no badges yet (nothing fabricated)', /10 coins/.test(await pb.locator('main').innerText()));

  // session expiry mid-quiz: progress saved on the server must survive
  await pb.goto(`${APACHE}/topics/2`);
  await pb.getByRole('button', { name: 'Take the quiz directly' }).click();
  await pb.waitForURL(/\/quiz\/\d+$/);
  const quizUrl = pb.url();
  const k2 = helper(false, 'answer-key', Number(quizUrl.match(/quiz\/(\d+)/)[1]));
  await pb.locator(`input[type=radio][value="${k2[1].right}"]`).check();
  await pb.locator('.save-saved').waitFor();
  const sid = helper(false, 'idle-session', emailB);
  await ctxB.addCookies([{ name: 'HSSESSID', value: sid, domain: 'localhost', path: '/hunter-system/', httpOnly: true, sameSite: 'Strict' }]);
  await pb.getByRole('button', { name: 'Next question' }).click();
  await pb.locator(`input[type=radio][value="${k2[2].right}"]`).check();
  await pb.waitForURL(/\/login$/);
  const expiredMsg = await pb.locator('main').innerText();
  await pb.getByLabel('Email').fill(emailB);
  await pb.getByLabel('Password').fill(PW);
  await pb.getByRole('button', { name: 'Log in' }).click();
  await pb.waitForURL(quizUrl);
  await pb.getByRole('group', { name: /^Question \d+ of 5/ }).waitFor();
  check('A19 session expiry: the next save gets 401 session_expired -> login page explains saved progress is kept -> after login back on the same quiz with question 1 still answered',
    /30 minutes without activity/.test(expiredMsg) && /kept/.test(expiredMsg)
    && (await questionMap(pb).first().getAttribute('class')).includes('map-saved'));

  await pb.getByRole('button', { name: 'Log out' }).click();
  await pb.waitForURL(/\/login$/);
  await pb.goto(`${APACHE}/dashboard`);
  await pb.waitForURL(/\/login$/);
  check('A20 logout ends the session: a protected page now routes to login', true);

  // keyboard: focus moves to the page title; skip link; labels; Enter to submit
  await pb.goto(`${APACHE}/login`);
  await pb.locator('#email').waitFor();
  await pb.waitForFunction(() => document.activeElement?.tagName === 'H1');
  const titleFocused = await pb.evaluate(() => document.activeElement.textContent);
  await pb.keyboard.press('Tab');                          // title -> first field
  const afterTitle = await pb.evaluate(() => document.activeElement?.id);
  await pb.keyboard.press('Shift+Tab');                    // back: the skip link (the title is not a tab stop)
  const firstFocus = await pb.evaluate(() => document.activeElement?.textContent);
  const skipVisible = await pb.evaluate(() => document.activeElement.getBoundingClientRect().top >= 0);
  await pb.keyboard.press('Enter');                        // skip link -> main content
  await pb.keyboard.press('Tab');
  const emailFocused = afterTitle === 'email' ? await pb.evaluate(() => document.activeElement?.id) : 'x';
  await pb.keyboard.type(emailB);
  await pb.keyboard.press('Tab');
  await pb.keyboard.type(PW);
  const outline = await pb.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
  await pb.keyboard.press('Enter');
  await pb.waitForURL(/\/dashboard$/);
  check('A21 keyboard only: focus starts on the page title; Tab -> email; Shift+Tab reaches the visible skip link, Enter jumps to the content; password by Tab; visible focus outline; Enter logs in',
    titleFocused === 'Log in' && /Skip to content/.test(firstFocus) && skipVisible && emailFocused === 'email' && outline !== 'none',
    `title=${titleFocused} first=${firstFocus} field=${emailFocused} outline=${outline}`);

  // admin routing
  const adminEmail = `b6.${RUN}.admin@example.invalid`;
  helper(false, 'create-admin', adminEmail, 'Admin journey password 1');
  const ctxC = await browser.newContext();
  const pc = await ctxC.newPage();
  watch(pc);
  const navs = [];
  pc.on('framenavigated', (f) => { if (f === pc.mainFrame()) navs.push(new URL(f.url()).pathname.replace('/hunter-system', '')); });
  await pc.goto(`${APACHE}/login`);
  await pc.getByLabel('Email').fill(adminEmail);
  await pc.getByLabel('Password').fill('Admin journey password 1');
  await pc.getByRole('button', { name: 'Log in' }).click();
  await pc.waitForURL(/\/admin$/);
  await pc.goto(`${APACHE}/dashboard`);
  await pc.waitForURL(/\/admin$/);
  const before = navs.length;
  await sleep(1500);                                       // a loop would keep navigating
  const settled = navs.length === before;
  const afterDash = navs.slice(navs.lastIndexOf('/dashboard'));
  check('A22 an administrator lands on the overview; a student route redirects there exactly once and stays (no loop)',
    (await heading(pc)) === 'Administrator overview' && settled && JSON.stringify(afterDash) === '["/dashboard","/admin"]',
    navs.join(' > '));

  // mobile
  const ctxM = await browser.newContext({ viewport: { width: 375, height: 740 }, isMobile: true, hasTouch: true });
  const pm = await ctxM.newPage();
  watch(pm);
  await ctxM.addCookies(await ctxB.cookies());
  await pm.goto(`${APACHE}/dashboard`);
  await pm.getByRole('heading', { name: 'Topics passed' }).waitFor();
  const overflow = await pm.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const navHidden = !(await pm.locator('#navigation-drawer nav').isVisible());
  await pm.getByRole('button', { name: 'Menu' }).click();
    const navShown = await pm.locator('#navigation-drawer nav').isVisible();
  await shot(pm, 'm-dashboard');
  await pm.goto(`${APACHE}/topics/1/lesson`);
  await pm.locator('.prose').waitFor();
  const codeOverflow = await pm.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  await shot(pm, 'm-lesson');
  check('A23 mobile 375 px: no horizontal page overflow (dashboard, lesson with code), navigation behind a Menu button that opens it',
    overflow <= 0 && codeOverflow <= 0 && navHidden && navShown, `overflow=${overflow}/${codeOverflow}`);

  const ctxR = await browser.newContext({ reducedMotion: 'reduce' });
  const pr = await ctxR.newPage();
  await pr.goto(`${APACHE}/login`);
  await pr.locator('.btn').first().waitFor();
  const dur = await pr.evaluate(() => getComputedStyle(document.querySelector('.btn')).transitionDuration);
  check('A24 prefers-reduced-motion: transitions are effectively disabled', parseFloat(dur) < 0.01, `transition=${dur}`);

  // =========================================================================
  // Part B — fixture API through `vite preview`: purchases and showcase
  // =========================================================================
  const dbName = helper(true, 'db-name');
  if (dbName !== 'hunter_system_m3_fixture') throw new Error('fixture helper not pointing at the fixture DB');
  const preview = spawn(process.execPath, [path.join(HERE, '..', 'node_modules', 'vite', 'bin', 'vite.js'), 'preview', '--port', '4173', '--strictPort'], {
    cwd: path.join(HERE, '..'), env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:8098' }, stdio: 'ignore',
  });
  try {
    for (let i = 0; i < 40; i++) {
      try { if ((await fetch(`${PREVIEW}/api/v1/health`)).ok) break; } catch { /* starting */ }
      await sleep(250);
    }
    const previewIdentity=await fetch(`${PREVIEW}/api/v1/__test/identity`).then(r=>r.json());
    const configuredIdentity=helper(true,'fixture-identity');
    if (JSON.stringify(previewIdentity.data)!==JSON.stringify(configuredIdentity)) throw new Error('Refusing unexpected complete fixture preview proxy');
    const ids = helper(true, 'make-badges', RUN);
    const ctxF = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pf = await ctxF.newPage();
    watch(pf);
    const emailF = `b6f.${RUN}.s@example.invalid`;
    const apiF = apiFor(ctxF, PREVIEW);
    await apiF('POST', '/auth/register', { email: emailF, display_name: 'Browser F', password: PW });
    await placeViaApi(apiF, true, emailF, [1, 2, 3, 4, 5, 6]);           // placed S: every topic unlocked
    for (let t = 1; t <= 6; t++) {                                       // 6 failed first completions = 60 coins
      const q = await apiF('POST', `/topics/${t}/quiz-attempts`, { started_via: 'direct' });
      const key = helper(true, 'answer-key', q.attempt_id);
      for (const [pos, k] of Object.entries(key)) await apiF('PUT', `/quiz-attempts/${q.attempt_id}/answers/${pos}`, { choice_id: k.wrong });
      await apiF('POST', `/quiz-attempts/${q.attempt_id}/submit`);
    }
    await pf.goto(`${PREVIEW}/store`);
    await pf.locator('.badge-card').first().waitFor();
    const fixtureCards=pf.locator('.badge-card').filter({has:pf.getByRole('heading',{name:/^\[FIXTURE\] Badge [ABCH]$/})});
    const cards = await fixtureCards.count();
    const imgs = await fixtureCards.locator('img').count();
    const placeholders = await fixtureCards.locator('.badge-art-placeholder').count();
    const hDisabled = await pf.locator('.badge-card', { hasText: 'Badge H' }).getByRole('button', { name: 'Buy' }).isDisabled();
    check('B1 fixture store: approved badges listed with prices from the API, neutral placeholder art (no images invented); unaffordable badge disabled with a hint',
      cards === 4 && imgs === 0 && placeholders === cards && hDisabled && /60/.test(await pf.locator('.lead').innerText()));
    await shot(pf, 'f-store');

    const buy = async (name) => {
      const card = pf.locator('.badge-card', { hasText: name });
      await card.getByRole('button', { name: 'Buy' }).click();
      await card.getByRole('button', { name: 'Confirm' }).click();
      await pf.locator('.notice-title').first().waitFor();
    };
    let purchaseRequests = 0;
    pf.on('request', (r) => { if (/\/purchase$/.test(r.url())) purchaseRequests++; });
    await buy('Badge A');
    const msgA = await pf.locator('main .notice').first().innerText();
    await pf.locator('.badge-card', { hasText: 'Badge A' }).getByText('Owned').waitFor();
    check('B2 purchase with confirmation: one request, success message with price 10 and balance 50; refreshed store shows Owned and the new balance',
      /Badge purchased/.test(msgA) && /10 coins/.test(msgA) && /Balance: 50/.test(msgA) && purchaseRequests === 1
      && /50/.test(await pf.locator('.lead').innerText()));
    helper(true, 'badge-status', ids.c, 'off_sale');                       // taken off sale while the page is open
    await buy('Badge C');
    const msgC = await pf.locator('main .notice').first().innerText();
    check('B3 badge withdrawn while the page was open: the API answers not_on_sale, the UI explains and refreshes (badge gone, balance unchanged)',
      /no longer for sale/.test(msgC) && (await pf.locator('.badge-card', { hasText: 'Badge C' }).count()) === 0 && /50/.test(await pf.locator('.lead').innerText()));
    await buy('Badge B');
    const balance = await pf.locator('.lead').innerText();

    await pf.goto(`${PREVIEW}/profile`);
    await pf.getByText('Your badges').waitFor();
    await pf.getByRole('checkbox', { name: /Badge B/ }).check();
    await pf.getByRole('checkbox', { name: /Badge A/ }).check();
    await pf.getByRole('button', { name: 'Move [FIXTURE] Badge A up' }).click();
    await pf.getByRole('button', { name: 'Save showcase' }).click();
    await pf.getByText('Showcase saved.').waitFor();
    await pf.reload();
    await pf.locator('.showcase li').first().waitFor();
    const order = await pf.locator('.showcase li').allInnerTexts();
    check('B4 ordered showcase: picked B then A, moved A up, saved; after reload the showcase is A, B',
      /30/.test(balance) && order.length === 2 && /Badge A/.test(order[0]) && /Badge B/.test(order[1]), JSON.stringify(order));
    await shot(pf, 'f-profile');

    await pf.route('**/api/v1/me/showcase', (route) => route.fulfill({ status: 500, contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'internal_error', message: 'Something went wrong. Please try again.', details: {} } }) }));
    await pf.getByRole('checkbox', { name: /Badge B/ }).uncheck();
    await pf.getByRole('button', { name: 'Save showcase' }).click();
    await pf.getByText(/previous showcase is unchanged/).waitFor();
    await pf.unroute('**/api/v1/me/showcase');
    const stillChecked = await pf.getByRole('checkbox', { name: /Badge B/ }).isChecked();
    await pf.reload();
    await pf.locator('.showcase li').first().waitFor();
    check('B5 a failed showcase save keeps the previous showcase (selection restored in the UI and unchanged on the server)',
      stillChecked && (await pf.locator('.showcase li').count()) === 2);
    helper(true, 'badge-status', ids.a, 'retired');
    await pf.reload();
    await pf.locator('.showcase li').first().waitFor();
    check('B6 a retired owned badge stays in the showcase and is labelled "retired"', /retired/.test(await pf.locator('.showcase li').first().innerText()));
  } finally {
    helper(true, 'retire-run-badges', RUN);
    try { execFileSync('taskkill', ['/pid', String(preview.pid), '/T', '/F'], { stdio: 'ignore' }); } catch { /* already gone */ }
  }

  check('Z1 no uncaught JavaScript errors in any page', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
} catch (err) {
  check('ABORTED', false, err.stack?.split('\n').slice(0, 3).join(' '));
} finally {
  await browser.close();
  seedPreview?.kill();
}
const failed = results.filter((x) => !x).length;
console.log(`\n${results.length} checks, ${results.length - failed} passed, ${failed} failed. Accounts: b6.${RUN}.* (${ISOLATED?'seed fixture':'dev'}), b6f.${RUN}.* (complete fixture).`);
process.exit(failed === 0 ? 0 : 1);
