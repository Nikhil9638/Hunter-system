// Phase 4 onboarding checks: experience cards (/start), question runner (placement check
// and topic quiz) and the placement result — against the REAL production build.
// ALL API responses are SIMULATED in-browser (stateful mock below). The preview proxy points
// at an unreachable port, so nothing can reach Apache/dev. UI evidence only — never reported
// as API integration. The runner part mirrors live browser-journeys A3/A5–A8 with the same
// locators, proving them against the real DOM while the live fixture is unavailable.
// Run from frontend/ after `npm run build`: node tests/onboarding.mjs
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4197, BASE = `http://localhost:${PORT}/hunter-system`;
// Optional: --shots <dir> saves review screenshots.
const SHOTS = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const shot = async (p, name, fullPage = false) => { if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage }); };
let checks = 0;
const check = (name, ok, detail = '') => { assert.ok(ok, `${name}${detail ? ` (${detail})` : ''}`); checks++; console.log('PASS ' + name); };

// Seed names keep the simulated result realistic (groups G1–G6, 18 topics).
const seed = readFileSync('../database/seed.sql', 'utf8');
const GROUPS = [...seed.matchAll(/\(\d, '(G\d)', '([^']+)',\s+\d\)/g)].map((m) => ({ code: m[1], name: m[2].trim() }));
const TOPICS = [...seed.matchAll(/^\s*\(\s*(\d+),\s*\d+,\s*'[a-z0-9-]+',\s*'([^']+)'/gm)].map((m) => ({ id: +m[1], title: m[2].trim() }));

// Deliberately NOT the seed ids: proves the submitted id comes from the API item.
const LEVELS = [{ id: 7, code: 'complete_beginner', label: 'Complete beginner' }, { id: 3, code: 'basic', label: 'Knows a little basic Python' },
  { id: 9, code: 'intermediate', label: 'Intermediate' }, { id: 5, code: 'master', label: 'Master' }];
const QUESTIONS = (n, idBase) => Array.from({ length: n }, (_, i) => ({ position: i + 1, skill_group: `G${Math.min(6, Math.floor(i / 2) + 1)}`,
  stem: `Simulated question ${i + 1}: what does this print?`, code_snippet: i % 3 === 0 ? `x = ${i}\nprint(x + 1)` : null,
  choices: ['A', 'B', 'C', 'D'].map((label, k) => ({ id: idBase + (i + 1) * 10 + k, label, text: `Option ${label}${k === 1 ? ' (simulated right answer)' : ''}` })), selected_choice_id: null }));
function result() {
  return { attempt_id: 41, submitted_at: '2026-10-18T10:00:00.000Z', correct_count: 4, question_count: 10, placement_rank: { code: 'E', name: 'E-Rank', title: 'Rookie' },
    label: 'Starting point from a short placement check, not proof of mastery',
    recommended_topic: { id: 4, position: 4, title: TOPICS[3].title, ready: false }, offered_topic: { id: 2, position: 2, title: TOPICS[1].title, ready: true },
    groups: GROUPS.map((g, i) => ({ ...g, asked: i < 4 ? 2 : 1, correct: i === 0 ? 2 : i === 1 ? 1 : 0 })),
    topics: TOPICS.map((t, i) => ({ ...t, evidence: i % 3 === 2 ? 'not_assessed' : 'sampled', asked: i % 3 === 2 ? 0 : 1, correct: i < 2 ? 1 : 0 })),
    experience_level: { code: 'master', label: 'Master' }, coins_awarded: 0,
    review: QUESTIONS(10, 0).map((q) => ({ ...q, topic: TOPICS[q.position - 1].title, choices: q.choices.map((c, k) => ({ ...c, correct: k === 1 })),
      selected_choice_id: q.choices[q.position <= 4 ? 1 : 0].id, is_correct: q.position <= 4, explanation: `Simulated explanation ${q.position}.` })) };
}

function simulate(s, method, path, body) {
  const json = (status, data) => [status, data];
  if (path === '/auth/me') return json(200, { user: { id: 1, email: 'sim@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: s.onboarding }, csrf_token: 'simulated' });
  if (path === '/experience-levels') return json(200, s.levels);
  if (path === '/me/experience' && method === 'PUT') {
    s.experiencePuts.push(body);
    if (s.experienceError) return json(...s.experienceError);
    s.onboarding = 'experience_chosen'; return json(200, { onboarding_state: 'experience_chosen' });
  }
  if (path === '/assessment' && method === 'POST') {
    if (s.onboarding === 'assessment_submitted') return json(409, { error: { code: 'placement_already_recorded', message: 'Already recorded.' } });
    const resumed = !!s.attempt; s.attempt ??= QUESTIONS(10, 0);
    return json(resumed ? 200 : 201, { attempt_id: 41, status: 'in_progress', question_count: 10, answered_count: s.attempt.filter((q) => q.selected_choice_id).length,
      started_at: '2026-10-18T09:50:00.000Z', resumed, questions: s.attempt });
  }
  let m = /^\/(assessment|quiz-attempts)\/(\d+)\/answers\/(\d+)$/.exec(path);
  if (m && method === 'PUT') {
    s.answerPuts.push({ path, body, at: Date.now() });
    const qs = m[1] === 'assessment' ? s.attempt : s.quiz;
    const q = qs.find((x) => x.position === +m[3]);
    if (!q.choices.some((c) => c.id === body.choice_id)) return json(400, { error: { code: 'invalid_choice', message: 'Invalid choice.' } });
    q.selected_choice_id = body.choice_id; return json(200, { position: q.position, selected_choice_id: body.choice_id });
  }
  if (path === '/assessment/41/submit' && method === 'POST') {
    s.submitAt = Date.now();
    if (s.attempt.some((q) => !q.selected_choice_id)) return json(409, { error: { code: 'attempt_incomplete', message: 'Incomplete.', details: { unanswered_positions: s.attempt.filter((q) => !q.selected_choice_id).map((q) => q.position) } } });
    s.onboarding = 'assessment_submitted'; return json(200, { ...result(), already_submitted: false });
  }
  if (path === '/assessment/result') return s.onboarding === 'assessment_submitted' ? json(200, result()) : json(404, { error: { code: 'not_found', message: 'No result.' } });
  if (path === '/quiz-attempts/77') return json(200, { attempt_id: 77, topic_id: 2, attempt_number: 3, status: 'in_progress', started_via: 'direct', question_count: 5, answered_count: 0, started_at: '2026-10-18T09:00:00.000Z', questions: s.quiz });
  return json(404, { error: { code: 'not_found', message: 'Simulated: not found.' } });
}

const preview = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', 'localhost', '--port', String(PORT), '--strictPort'],
  { cwd: process.cwd(), stdio: 'ignore', env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:9' } });
let browser;
const errors = [];
try {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(BASE + '/login')).ok) break; } catch { /* starting */ } await delay(100); }
  browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });

  async function context(onboarding, options = {}, levels = LEVELS) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
    c.sim = { onboarding, levels, experiencePuts: [], answerPuts: [], api: [], external: [], attempt: null, quiz: QUESTIONS(5, 1000), abort: null, slow: null, experienceError: null };
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const req = route.request(), path = new URL(req.url()).pathname.split('/api/v1')[1];
      c.sim.api.push(`${req.method()} ${path}`);
      if (c.sim.abort && c.sim.abort.test(path)) { await route.abort('failed'); return; }
      if (c.sim.slow && c.sim.slow.test(path)) await delay(1200);
      const [status, body] = simulate(c.sim, req.method(), path, req.postData() ? JSON.parse(req.postData()) : undefined);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) c.sim.external.push(r.url()); }); });
    return c;
  }
  const radios = (p) => p.getByRole('group', { name: 'Experience level' }).getByRole('radio');
  const flipped = (card) => card.locator('.xp-inner').evaluate((e) => getComputedStyle(e).transform !== 'none');
  const description = (radio) => radio.evaluate((el) => el.getAttribute('aria-describedby').split(' ').map((id) => document.getElementById(id)?.textContent ?? '').join(' '));
  const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

  // ==== 1. experience cards: content and API mapping (desktop, mouse)
  const c1 = await context('not_started'), p1 = await c1.newPage();
  await p1.goto(BASE + '/start'); await radios(p1).first().waitFor({ state: 'attached' });
  const names = await radios(p1).evaluateAll((rs) => rs.map((r) => r.getAttribute('aria-label')));
  check('Four radios in the "Experience level" group, in API order, named character + theme + API label', JSON.stringify(names) === JSON.stringify([
    'Sung Jin-Woo, theme E-Rank: Complete beginner', 'Song Chi-Yul, theme C-Rank: Knows a little basic Python', 'Woo Jin-Chul, theme A-Rank: Intermediate', 'Cha Hae-In, theme S-Rank: Master']), names.join(' | '));
  check('Radio values are the API ids (7, 3, 9, 5), not hard-coded seed ids', (await radios(p1).evaluateAll((rs) => rs.map((r) => r.value).join(','))) === '7,3,9,5');
  check('Native radios share name="experience"', (await p1.locator('input[type=radio][name=experience]').count()) === 4);
  check('Disclosure: "This is your own description, not your Hunter rank." is visible', await p1.getByText('This is your own description, not your Hunter rank.').isVisible());
  const desc = await description(radios(p1).nth(1));
  check('Each card is described by its back text and the disclosure', desc.includes('I know a few Python basics but need guided practice.') && desc.includes('not your Hunter rank'), desc);
  check('Card fronts show theme chips "Theme · E/C/A/S-Rank" (outlined theme style, not real-rank labels)',
    (await p1.locator('.xp-card .rank-theme .rank-text').allInnerTexts()).join('|') === 'Theme · E-Rank|Theme · C-Rank|Theme · A-Rank|Theme · S-Rank'
    && !/Peasant|Squire|Champion|Mythic|Rookie/.test(await p1.locator('.xp-grid').innerText()));
  check('Portraits load as decorative images (alt="")', await p1.locator('.xp-art img').evaluateAll(async (imgs) => { await Promise.all(imgs.map((i) => i.decode())); return imgs.length === 4 && imgs.every((i) => i.alt === '' && i.naturalWidth === 480); }));
  const submit = p1.getByRole('button', { name: 'Confirm and begin placement check' });
  check('Submit is disabled until a card is selected', await submit.isDisabled());
  check('Details buttons are hidden for mouse users (hover devices flip on hover)', (await p1.locator('.xp-details:visible').count()) === 0);

  // hover flips without selecting; selection is independent of flipping
  const card = (i) => p1.locator('.xp-card').nth(i);
  await card(0).hover(); await delay(650);
  check('Hover flips the card to its back face without selecting it', (await flipped(card(0))) && !(await radios(p1).nth(0).isChecked()));
  await p1.mouse.move(5, 5); await delay(650);
  check('Moving away flips it back', !(await flipped(card(0))));
  await card(1).locator('.xp-hit').click(); await delay(650);
  check('Click selects the card (native radio checked, ribbon shown) while it is flipped by hover',
    (await radios(p1).nth(1).isChecked()) && (await card(1).locator('.xp-back .xp-ribbon').isVisible()) && await flipped(card(1)));
  await p1.mouse.move(5, 5); await delay(650);
  check('Flipping back does not deselect; ribbon shows on the front too', (await radios(p1).nth(1).isChecked()) && (await card(1).locator('.xp-front .xp-ribbon').isVisible()) && !(await flipped(card(1))));
  await shot(p1, 'start-1440-selected');
  check('Selection summary announces the chosen card', (await p1.locator('.xp-selection').innerText()).includes('Song Chi-Yul · Knows a little basic Python'));
  check('Submit enabled after selection', await submit.isEnabled());

  // keyboard: focus flips, Enter and Space select, arrows move, Enter never submits
  await p1.keyboard.press('Tab'); await p1.keyboard.press('Shift+Tab');      // real keyboard focus back into the group
  check('Keyboard focus lands on the checked radio and flips that card', (await p1.evaluate(() => document.activeElement.value)) === '3' && (await delay(650), await flipped(card(1))));
  await radios(p1).nth(3).focus(); await delay(650);
  check('Keyboard focus flips the card and shows a 3px focus ring on the card frame', (await flipped(card(3)))
    && (await card(3).locator('.xp-hit').evaluate((e) => { const s = getComputedStyle(e); return s.outlineStyle === 'solid' && s.outlineWidth === '3px'; })));
  await p1.keyboard.press('Enter');
  check('Enter selects the focused card and does not submit', (await radios(p1).nth(3).isChecked()) && c1.sim.experiencePuts.length === 0 && /\/start$/.test(p1.url()));
  await radios(p1).nth(0).focus(); await p1.keyboard.press('Space');
  check('Space selects the focused card', (await radios(p1).nth(0).isChecked()) && !(await radios(p1).nth(3).isChecked()));
  await p1.keyboard.press('ArrowRight');
  check('Arrow keys move selection like a native radio group', (await radios(p1).nth(1).isChecked()) && (await p1.evaluate(() => document.activeElement.value)) === '3');
  check('Theme selection triggered no request', c1.sim.experiencePuts.length === 0);
  // the live suite's locator (browser-journeys A3) must work on this DOM
  await p1.getByRole('radio', { name: /Complete beginner/ }).check();
  check('Live-suite locator getByRole("radio", { name: /Complete beginner/ }).check() works', await radios(p1).nth(0).isChecked());
  await submit.click(); await p1.waitForURL(/\/assessment$/);
  check('Submit sends exactly one PUT /me/experience with only {experience_level_id: <API id 7>} and continues to /assessment',
    c1.sim.experiencePuts.length === 1 && JSON.stringify(c1.sim.experiencePuts[0]) === '{"experience_level_id":7}', JSON.stringify(c1.sim.experiencePuts));
  await c1.close();

  // ==== 2. touch: Details control instead of hover
  const c2 = await context('not_started', { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 }), p2 = await c2.newPage();
  await p2.goto(BASE + '/start'); await radios(p2).first().waitFor({ state: 'attached' });
  const details = p2.getByRole('button', { name: /^Details: Woo Jin-Chul$/ });
  check('Touch screens show a Details button per card (>= 44px)', (await p2.locator('.xp-details:visible').count()) === 4
    && (await details.evaluate((b) => b.getBoundingClientRect().height)) >= 44);
  await shot(p2, 'start-390', true);
  await details.tap(); await delay(650);
  await shot(p2, 'start-390-details');
  const tcard = p2.locator('.xp-card').nth(2);
  check('Details reveals the back face (aria-expanded=true) without selecting', (await flipped(tcard)) && !(await radios(p2).nth(2).isChecked())
    && (await p2.getByRole('button', { name: /Woo Jin-Chul$/ }).getAttribute('aria-expanded')) === 'true');
  await tcard.locator('.xp-hit').tap({ position: { x: 40, y: 60 } });
  check('Tapping the card selects it while revealed', await radios(p2).nth(2).isChecked());
  await p2.getByRole('button', { name: /^Hide details: Woo Jin-Chul$/ }).tap(); await delay(650);
  check('Hiding details does not deselect', (await radios(p2).nth(2).isChecked()) && !(await flipped(tcard)));
  await p2.locator('.xp-card').nth(0).locator('.xp-hit').tap({ position: { x: 40, y: 60 } });
  check('Tapping another card moves the selection', (await radios(p2).nth(0).isChecked()) && !(await radios(p2).nth(2).isChecked()));
  await c2.close();

  // ==== 3. reduced motion: cross-fade instead of 3D flip
  const c3 = await context('not_started', { reducedMotion: 'reduce' }), p3 = await c3.newPage();
  await p3.goto(BASE + '/start'); await radios(p3).first().waitFor({ state: 'attached' });
  const back = p3.locator('.xp-card').nth(1).locator('.xp-back');
  check('Reduced motion: no rotation and the back face is hidden at rest', (await p3.locator('.xp-inner').evaluateAll((els) => els.every((e) => getComputedStyle(e).transform === 'none')))
    && (await back.evaluate((e) => getComputedStyle(e).opacity)) === '0');
  await radios(p3).nth(1).focus(); await delay(250);
  check('Reduced motion: focus reveals the back face by fading, still without rotation', (await back.evaluate((e) => getComputedStyle(e).opacity)) === '1'
    && !(await flipped(p3.locator('.xp-card').nth(1))));
  await c3.close();

  // ==== 4. API-driven matching: unknown code -> plain card; config without API option -> absent
  const c4 = await context('not_started', {}, [LEVELS[0], LEVELS[1], LEVELS[3], { id: 21, code: 'guild_veteran', label: 'Guild veteran' }]), p4 = await c4.newPage();
  await p4.goto(BASE + '/start'); await radios(p4).first().waitFor({ state: 'attached' });
  const names4 = await radios(p4).evaluateAll((rs) => rs.map((r) => r.getAttribute('aria-label')));
  check('Unknown API code renders a plain card with its API label; missing API option renders no card', names4.length === 4 && names4[3] === 'Guild veteran'
    && !names4.some((n) => n.includes('Woo Jin-Chul')) && (await p4.locator('.xp-card-plain img').count()) === 0);
  await p4.getByRole('radio', { name: 'Guild veteran' }).check(); await p4.getByRole('button', { name: 'Confirm and begin placement check' }).click();
  await p4.waitForURL(/\/assessment$/);
  check('Plain card submits its API id (21)', JSON.stringify(c4.sim.experiencePuts) === '[{"experience_level_id":21}]');
  await c4.close();

  // ==== 5. error handling unchanged
  const c5 = await context('not_started'), p5 = await c5.newPage();
  c5.sim.experienceError = [500, { error: { code: 'internal_error', message: 'Simulated server failure.', details: { error_id: 'sim' } } }];
  await p5.goto(BASE + '/start'); await radios(p5).first().waitFor({ state: 'attached' });
  await p5.getByRole('radio', { name: /Master/ }).check(); await p5.getByRole('button', { name: 'Confirm and begin placement check' }).click();
  await p5.getByRole('alert').filter({ hasText: 'Simulated server failure.' }).waitFor();
  check('A failed save shows the server message, stays on /start and keeps the selection', /\/start$/.test(p5.url()) && (await p5.getByRole('radio', { name: /Master/ }).isChecked())
    && (await p5.getByRole('button', { name: 'Confirm and begin placement check' }).isEnabled()));
  c5.sim.experienceError = [409, { error: { code: 'experience_already_chosen', message: 'Already chosen.' } }]; c5.sim.onboarding = 'experience_chosen';
  await p5.getByRole('button', { name: 'Confirm and begin placement check' }).click(); await p5.waitForURL(/\/assessment$/);
  check('experience_already_chosen refreshes the session and continues to /assessment', c5.sim.api.filter((a) => a === 'GET /auth/me').length >= 2);
  await c5.close();

  // ==== 6. layout across widths
  const c6 = await context('not_started'), p6 = await c6.newPage();
  for (const [width, perRow] of [[1440, 4], [1280, 4], [1100, 2], [900, 2], [768, 2], [560, 1], [390, 1], [320, 1]]) {
    await p6.setViewportSize({ width, height: 900 }); await p6.goto(BASE + '/start'); await radios(p6).first().waitFor({ state: 'attached' });
    const tops = await p6.locator('.xp-card').evaluateAll((cs) => cs.map((c) => Math.round(c.getBoundingClientRect().top)));
    check(`/start ${width}px: ${perRow} card(s) per row, no horizontal overflow`, (await noOverflow(p6)) && tops.filter((t) => t === tops[0]).length === perRow, tops.join(','));
  }
  await c6.close();

  // ==== 7. placement runner: mirrors live browser-journeys A3, A5–A7 with the same locators
  const c7 = await context('experience_chosen'), p7 = await c7.newPage();
  const map = () => p7.getByRole('navigation', { name: 'Questions' }).getByRole('button');
  const pick = (pos) => pos * 10 + 1;                     // option B of each simulated question
  await p7.goto(BASE + '/assessment'); await p7.getByRole('group', { name: /^Question 1 of 10/ }).waitFor();
  check('Runner: 10 position buttons in the "Questions" navigation; question is a named group', (await map().count()) === 10);
  check('Runner: answered count starts at "0 of 10 answered"; HUD corners are decorative', (await p7.locator('.runner-progress').innerText()) === '0 of 10 answered'
    && (await p7.locator('.question .hud-corner[aria-hidden=true]').count()) === 4);
  await p7.locator(`input[type=radio][value="${pick(1)}"]`).check(); await p7.locator('.save-saved').waitFor();
  check('Choosing an answer sends one PUT with only {choice_id} and shows Saved', c7.sim.answerPuts.length === 1 && c7.sim.answerPuts[0].path === '/assessment/41/answers/1'
    && JSON.stringify(c7.sim.answerPuts[0].body) === `{"choice_id":${pick(1)}}`);
  await p7.reload(); await p7.getByRole('group', { name: /^Question 2 of 10/ }).waitFor();
  const opensAt = await p7.locator('.q-count').innerText();
  await map().first().click();
  check('Refresh resumes: "Welcome back", opens at question 2, question 1 still selected', (await p7.getByText(/Welcome back/).count()) === 1 && /Question 2 of 10/i.test(opensAt)
    && (await p7.locator(`input[value="${pick(1)}"]`).isChecked()));
  await shot(p7, 'runner-1440');
  await map().nth(1).click();
  c7.sim.abort = /answers\/2$/;
  await p7.locator(`input[type=radio][value="${pick(2)}"]`).check(); await p7.locator('.save-error').waitFor();
  const nextDisabled = await p7.getByRole('button', { name: 'Next question' }).isDisabled(), mapDisabled = await map().nth(5).isDisabled();
  c7.sim.abort = null;
  await p7.getByRole('button', { name: 'Retry saving' }).click(); await p7.locator('.save-saved').waitFor();
  check('Failed save: "Not saved" blocks Next and the map; Retry saves and unblocks', nextDisabled && mapDisabled && !(await p7.getByRole('button', { name: 'Next question' }).isDisabled()));
  for (let pos = 3; pos <= 9; pos++) { await map().nth(pos - 1).click(); await p7.locator(`input[type=radio][value="${pick(pos)}"]`).check(); await p7.locator('.save-saved').waitFor(); }
  check('Answered count reflects saved answers ("9 of 10 answered")', (await p7.locator('.runner-progress').innerText()) === '9 of 10 answered');
  c7.sim.slow = /answers\/10$/;
  await map().nth(9).click(); await p7.locator(`input[type=radio][value="${pick(10)}"]`).check();
  await p7.getByRole('button', { name: 'Submit placement check' }).click(); await p7.waitForURL(/\/assessment\/result$/);
  const lastPut = c7.sim.answerPuts.filter((a) => a.path.endsWith('/answers/10')).at(-1);
  check('Submit clicked during the slow last save is sent only after it (save queue unchanged)', !!lastPut && c7.sim.submitAt >= lastPut.at, `${c7.sim.submitAt - lastPut.at} ms`);
  check('Only the expected requests were made (no extra writes)', c7.sim.api.filter((a) => /^(PUT|POST)/.test(a)).every((a) => /^(POST \/assessment|PUT \/assessment\/41\/answers\/\d+|POST \/assessment\/41\/submit)$/.test(a)));

  // ==== 8. placement result: server values, experience shown separately
  await p7.getByText(/Starting point from a short placement check/).waitFor();
  await shot(p7, 'result-1440', true);
  const main = await p7.locator('main').innerText();
  check('Result: "Placement complete" System panel with the server rank mapped to "E-Rank · Peasant"; "Rookie" never shown',
    (await p7.getByRole('region', { name: 'Placement complete' }).count()) === 1 && main.includes('E-Rank · Peasant') && !/Rookie/.test(main));
  const score = p7.getByRole('progressbar', { name: 'Placement check score' });
  check('Result: score meter shows the server values (4 of 10)', (await score.getAttribute('aria-valuenow')) === '4' && (await score.getAttribute('aria-valuemax')) === '10' && main.includes('4 of 10 correct'));
  const self = await p7.getByRole('region', { name: 'Self-reported experience' }).innerText();
  check('Result: self-reported experience is a separate panel ("Master (theme card: Cha Hae-In)", not used for the result)',
    self.includes('Master (theme card: Cha Hae-In)') && /not used for your result/.test(self) && !/E-Rank/.test(self));
  check('Result: recommended topic "content coming soon" and "Available now" from the server', /content coming soon/.test(main) && /Available now/.test(main));
  const groupBars = await p7.locator('.group-meters [role=progressbar]').evaluateAll((bs) => bs.map((b) => b.getAttribute('aria-valuenow') + '/' + b.getAttribute('aria-valuemax')));
  check('Result: six skill-group meters with server counts and seed names', groupBars.join(',') === '2/2,1/2,0/2,0/2,0/1,0/1'
    && GROUPS.every((g) => main.includes(`${g.code} · ${g.name}`)));
  check('Result: 18 topic evidence rows incl. "not checked"', (await p7.locator('.evidence li').count()) === 18 && main.includes('not checked'));
  check('Result: 10 review items with explanations and marked correct answers (same Review component)', (await p7.locator('.review-item').count()) === 10
    && (await p7.locator('.explanation').count()) === 10 && (await p7.getByText('correct answer', { exact: true }).count()) === 10);
  check('Result: dashboard link unchanged', (await p7.getByRole('link', { name: 'Go to your dashboard' }).getAttribute('href')).endsWith('/dashboard'));
  for (const width of [1440, 900, 390, 320]) { await p7.setViewportSize({ width, height: 900 }); await delay(80); check(`Result ${width}px: no horizontal overflow`, await noOverflow(p7)); }
  await c7.close();

  // ==== 9. topic quiz uses the same restyled runner
  const c9 = await context('assessment_submitted'), p9 = await c9.newPage();
  await p9.goto(BASE + '/quiz/77'); await p9.getByRole('group', { name: /^Question 1 of 5/ }).waitFor();
  check('Topic quiz: same runner (5 position buttons, "0 of 5 answered") under the existing title', (await p9.getByRole('navigation', { name: 'Questions' }).getByRole('button').count()) === 5
    && (await p9.locator('.runner-progress').innerText()) === '0 of 5 answered' && (await p9.getByRole('heading', { level: 1 }).innerText()) === 'Topic quiz · attempt 3');
  await p9.locator('input[type=radio][value="1011"]').check(); await p9.locator('.save-saved').waitFor();
  check('Topic quiz answer saves to /quiz-attempts/77/answers/1', c9.sim.answerPuts.length === 1 && c9.sim.answerPuts[0].path === '/quiz-attempts/77/answers/1');
  for (const width of [1440, 390, 320]) { await p9.setViewportSize({ width, height: 900 }); await delay(80); check(`Quiz runner ${width}px: no horizontal overflow`, await noOverflow(p9)); }
  await p9.keyboard.press('ArrowDown'); await p9.locator('.save-saved').waitFor();   // real keyboard: next choice in the native radio group
  check('Choice radios stay native: arrow keys move/select and show a visible 3px focus outline', await p9.evaluate(() => { const s = getComputedStyle(document.activeElement);
    return document.activeElement.type === 'radio' && document.activeElement.value === '1012' && s.outlineStyle === 'solid' && s.outlineWidth === '3px'; }));
  await c9.close();

  check('No uncaught browser errors', errors.length === 0, errors.join(' | '));
  console.log(`${checks} Phase 4 onboarding checks passed — production build, SIMULATED API responses (UI evidence only, not live integration).`);
} finally {
  await browser?.close();
  preview.kill();
}
