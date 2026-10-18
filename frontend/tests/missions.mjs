// Phase 5 checks: dashboard status screen, mission board, mission briefing, lesson and quiz
// results — against the REAL production build. ALL API responses are SIMULATED in-browser
// (the preview proxy points at an unreachable port; nothing reaches Apache/dev). UI evidence
// only — never reported as API integration. Uses the same locators as the updated live
// browser-journeys A9/A12/A14–A16/A23 so they are proven against the real DOM.
// Run from frontend/ after `npm run build`: node tests/missions.mjs [--shots <dir>]
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4198, BASE = `http://localhost:${PORT}/hunter-system`;
const SHOTS = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const shot = async (p, name, fullPage = false) => { if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage }); };
let checks = 0;
const check = (name, ok, detail = '') => { assert.ok(ok, `${name}${detail ? ` (${detail})` : ''}`); checks++; console.log('PASS ' + name); };

const seed = readFileSync('../database/seed.sql', 'utf8');
const SEED = [...seed.matchAll(/^\s*\(\s*(\d+),\s*\d+,\s*'[a-z0-9-]+',\s*'([^']+)',\s*'[^']*',\s*(\d)/gm)].map((m) => ({ id: +m[1], title: m[2].trim(), group: 'G' + m[3] }));
const GROUPS = Object.fromEntries([...seed.matchAll(/\(\d, '(G\d)', '([^']+)',\s+\d\)/g)].map((m) => [m[1], m[2].trim()]));

// Topic fixtures exercising every state and the precedence edge cases.
const base = (t) => ({ id: t.id, position: t.id, title: t.title, skill_group: t.group, unlocked: false, unlock_source: null, content_ready: true, status: 'locked',
  lesson_visited: false, completed_attempts: 0, best_correct: null, first_passed_at: null, open_attempt_id: null });
const TOPICS = SEED.map(base).map((t) => {
  const u = { unlocked: true, unlock_source: 'placement' };
  switch (t.id) {
    case 1: return { ...t, ...u, status: 'passed', completed_attempts: 2, best_correct: 4, first_passed_at: '2026-10-18T09:00:00.000Z', lesson_visited: true };
    case 2: return { ...t, ...u, status: 'completed', completed_attempts: 3, best_correct: 2 };                  // attempted, not cleared
    case 3: return { ...t, ...u, status: 'in_progress', open_attempt_id: 55, completed_attempts: 1, best_correct: 2 };
    case 4: return { ...t, ...u, status: 'available' };
    case 5: return { ...t, ...u, status: 'available', content_ready: false };                                    // content coming soon
    case 7: return { ...t, ...u, status: 'passed', content_ready: false, completed_attempts: 1, best_correct: 3 }; // cleared; content later withdrawn
    case 8: return { ...t, ...u, status: 'archived' };                                                          // unexpected server value
    default: return t;                                                                                          // locked
  }
});
const DASH = { display_name: 'Sim Student', onboarding_state: 'assessment_submitted', experience_level: { code: 'basic', label: 'Knows a little basic Python' },
  placement_rank: { code: 'E', name: 'E-Rank', title: 'Rookie' }, hunter_rank: { code: 'E', name: 'E-Rank', title: 'Rookie' }, assessment: { state: 'submitted', attempt_id: 41, can_start: false },
  recommended_topic: { id: 4, position: 4, title: TOPICS[3].title, ready: true }, offered_topic: null, current_topic: { id: 2, title: TOPICS[1].title, content_ready: true },
  coins: 10, topics_passed: 1, topics_total: 18, next_rank: { code: 'D', name: 'D-Rank', topics_required: 3, topics_remaining: 2 } };
const DASH_S = { ...DASH, hunter_rank: { code: 'S', name: 'S-Rank', title: null }, placement_rank: { code: 'C', name: 'C-Rank', title: null }, coins: 180, topics_passed: 18,
  current_topic: null, next_rank: null, recommended_topic: { id: 7, position: 7, title: TOPICS[6].title, ready: false }, offered_topic: { id: 2, position: 2, title: TOPICS[1].title, ready: true } };
const Q = (n, b) => Array.from({ length: n }, (_, i) => ({ position: i + 1, stem: `Simulated question ${i + 1}`, code_snippet: null,
  choices: ['A', 'B', 'C', 'D'].map((label, k) => ({ id: b + (i + 1) * 10 + k, label, text: `Option ${label}` })), selected_choice_id: null }));
const review = (nRight) => Q(5, 0).map((q) => ({ ...q, choices: q.choices.map((c, k) => ({ ...c, correct: k === 1 })), selected_choice_id: q.choices[q.position <= nRight ? 1 : 0].id,
  is_correct: q.position <= nRight, explanation: `Simulated explanation ${q.position}.` }));
const RESULTS = {
  101: { attempt_id: 101, topic_id: 4, attempt_number: 1, submitted_at: '2026-10-18T10:00:00.000Z', label: 'Quiz performance', correct_count: 2, question_count: 5, grade: 'D', percent: 40, passed: false,
    review: review(2), coins_awarded: 10, reward: { coins: 10, reason: 'topic_first_completion', balance_after: 20 }, unlocked_topic: null, promotion: null },
  102: { attempt_id: 102, topic_id: 4, attempt_number: 2, submitted_at: '2026-10-18T10:10:00.000Z', label: 'Quiz performance', correct_count: 4, question_count: 5, grade: 'B', percent: 80, passed: true,
    review: review(4), coins_awarded: 0, reward: null, unlocked_topic: { id: 5, title: TOPICS[4].title, content_ready: false }, promotion: { from: 'E', to: 'D', topics_passed: 3, policy_version: 1 } },
};
const LESSON = { topic_id: 4, title: TOPICS[3].title, revision: 3,
  body_markdown: `# ${TOPICS[3].title}\n\n## Reading input\n\nUse **input()** to read text.

## Typing values

Everything typed arrives as text.\n\n- one\n- two\n- three\n\n\`\`\`python\nname = input("Name? ")\nprint("Hello, " + name)\n\`\`\`\n\n### Converting\n\nWrap with \`int()\`.\n\n## Safety\n\n<script>window.bad = true</script>\n` };

function simulate(s, method, path, body) {
  if (path === '/auth/me') return [200, { user: { id: 1, email: 'sim@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: 'assessment_submitted' }, csrf_token: 'simulated' }];
  if (path === '/me/dashboard') return [200, s.dashboard];
  if (path === '/topics') return [200, TOPICS];
  let m = /^\/topics\/(\d+)\/quiz-attempts$/.exec(path);
  if (m && method === 'GET') return [200, +m[1] === 4 ? [{ attempt_id: 101, attempt_number: 1, status: 'submitted', started_via: 'direct', started_at: '2026-10-18T09:55:00.000Z', submitted_at: '2026-10-18T10:00:00.000Z', correct_count: 2, grade: 'D', percent: 40, passed: false }] : []];
  if (m && method === 'POST') { s.starts.push(body); return [201, { attempt_id: 104, topic_id: +m[1], attempt_number: 3, status: 'in_progress', started_via: body.started_via, question_count: 5, answered_count: 0, started_at: '2026-10-18T10:20:00.000Z', questions: s.q104, resumed: false }]; }
  if (path === '/topics/4/lesson') return [200, LESSON];
  if (path === '/topics/5/lesson') return [409, { error: { code: 'topic_content_not_available', message: 'Not available.' } }];
  m = /^\/quiz-attempts\/(\d+)$/.exec(path);
  if (m) { const id = +m[1]; if (RESULTS[id]) return [200, RESULTS[id]]; if (id === 104) return [200, s.submitted104 ?? { attempt_id: 104, topic_id: 4, attempt_number: 3, status: 'in_progress', question_count: 5, answered_count: 0, questions: s.q104 }]; return [404, { error: { code: 'not_found', message: 'Not found.' } }]; }
  m = /^\/quiz-attempts\/104\/answers\/(\d+)$/.exec(path);
  if (m && method === 'PUT') { s.q104[+m[1] - 1].selected_choice_id = body.choice_id; return [200, { position: +m[1], selected_choice_id: body.choice_id }]; }
  if (path === '/quiz-attempts/104/submit' && method === 'POST') { s.submitted104 = { ...RESULTS[101], attempt_id: 104, attempt_number: 3, reward: null, coins_awarded: 0, already_submitted: false }; return [200, s.submitted104]; }
  return [404, { error: { code: 'not_found', message: 'Simulated: not found.' } }];
}

const preview = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', 'localhost', '--port', String(PORT), '--strictPort'],
  { cwd: process.cwd(), stdio: 'ignore', env: { ...process.env, HUNTER_API_TARGET: 'http://127.0.0.1:9' } });
let browser;
const errors = [];
try {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(BASE + '/login')).ok) break; } catch { /* starting */ } await delay(100); }
  browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  async function context(dashboard = DASH, options = {}) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
    c.sim = { dashboard, starts: [], api: [], external: [], q104: Q(5, 2000), submitted104: null };
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const req = route.request(), path = new URL(req.url()).pathname.split('/api/v1')[1];
      c.sim.api.push(`${req.method()} ${path}`);
      const [status, body] = simulate(c.sim, req.method(), path, req.postData() ? JSON.parse(req.postData()) : undefined);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) c.sim.external.push(r.url()); }); });
    return c;
  }
  const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  const noCoins = async (p) => !/\bcoins?\b/i.test(await p.locator('main').innerText());

  // ==== 1. dashboard
  const c1 = await context(), p = await c1.newPage();
  await p.goto(BASE + '/dashboard'); await p.getByRole('heading', { name: 'Status' }).waitFor(); await delay(200);
  await shot(p, 'dashboard-1440');
  const dash = await p.locator('main').innerText();
  check('Dashboard: "Status" window (live-suite A16 locator) with name, ranks, missions, next rank, Mana, experience', ['Name', 'Hunter rank', 'Placement rank', 'Missions cleared', 'Next rank', 'Mana', 'Experience']
    .every((dt) => dash.toLowerCase().includes(dt.toLowerCase())) && dash.includes('Sim Student'));
  check('Dashboard: live A16 text expectations hold (10, "1 / 18", E-Rank, current mission title)', /\b10\b/.test(dash) && /1\s*\/ 18/.test(dash) && /E-Rank/.test(dash) && dash.includes(TOPICS[1].title));
  check('Dashboard: ranks mapped ("E-Rank · Peasant"), lore shown, "Rookie" never rendered', dash.includes('E-Rank · Peasant') && dash.includes('Novice Hunter') && !/Rookie/.test(dash));
  check('Dashboard: Mana from the API ("10 Mana"); no "coins" wording', dash.includes('10 Mana') && await noCoins(p));
  const bars = await p.getByRole('progressbar').evaluateAll((bs) => bs.map((b) => `${b.getAttribute('aria-label') ?? document.getElementById(b.getAttribute('aria-labelledby'))?.textContent}=${b.getAttribute('aria-valuenow')}/${b.getAttribute('aria-valuemax')}`));
  check('Dashboard: meters are plain ratios of API fields (1/18 cleared; 1/3 toward D-Rank)', JSON.stringify(bars) === JSON.stringify(['Missions cleared=1/18', 'Progress toward D-Rank · Apprentice=1/3']), bars.join(' | '));
  check('Dashboard: next rank text from next_rank (D-Rank · Apprentice at 3, 2 to go)', /D-Rank · Apprentice\s*at 3 missions cleared · 2 to go/.test(dash));
  check('Dashboard: decorative E-tier art (young) captioned "not an avatar"', (await p.locator('.dash-character img').evaluate(async (i) => { await i.decode(); return i.alt === '' && i.naturalWidth === 480; }))
    && await p.getByText('Decorative rank artwork. Not an avatar.').isVisible());
  check('Dashboard: current mission links to /topics/2 ("Continue mission"); recommendation from the API', (await p.getByRole('link', { name: 'Continue mission' }).getAttribute('href')).endsWith('/topics/2')
    && (await p.getByRole('region', { name: 'Recommended start' }).innerText()).includes(TOPICS[3].title));
  check('Dashboard: no invented stats (no STR/AGI/HP/level/streak/activity feed)', !/\b(STR|AGI|HP|Strength|Agility|Vitality|streak|Level \d+|recent activity)\b/i.test(dash));
  check('Dashboard: "Available now" panel absent when offered_topic is null', (await p.getByRole('region', { name: 'Available now' }).count()) === 0);
  check('Dashboard: exactly one API request (GET /me/dashboard) besides the session check', JSON.stringify(c1.sim.api.filter((a) => a !== 'GET /auth/me')) === '["GET /me/dashboard"]');
  for (const width of [1440, 1100, 900, 768, 390, 320]) {
    await p.setViewportSize({ width, height: 900 }); await delay(100);
    const order = await p.evaluate(() => { const n = document.querySelector('.dash-current').getBoundingClientRect().top, s = document.querySelector('.status-window').getBoundingClientRect().top; return n < s; });
    check(`Dashboard ${width}px: no horizontal overflow${width <= 760 ? '; current mission shown before the status window' : ''}`, (await noOverflow(p)) && (width > 760 || order));
  }
  await p.setViewportSize({ width: 390, height: 844 }); await shot(p, 'dashboard-390', true);
  await c1.close();
  const cS = await context(DASH_S), pS = await cS.newPage();
  await pS.goto(BASE + '/dashboard'); await pS.getByRole('heading', { name: 'Status' }).waitFor();
  const dS = await pS.locator('main').innerText();
  check('Dashboard S: "Highest rank reached", no next-rank meter, mythic lore, awakened art, no current mission', dS.includes('Highest rank reached') && (await pS.getByRole('progressbar').count()) === 1
    && dS.includes('S-Rank · Mythic') && dS.includes('Immortal · Deity') && dS.includes("You've cleared every unlocked mission.")
    && (await pS.locator('.dash-character img').evaluate(async (i) => { await i.decode(); const pr = new Image(); pr.src = i.currentSrc; await pr.decode(); return pr.naturalWidth >= 400; })));
  check('Dashboard S: "Available now" appears only because offered_topic is provided', (await pS.getByRole('region', { name: 'Available now' }).innerText()).includes(TOPICS[1].title));
  await cS.close();

  // ==== 2. mission board
  const c2 = await context(), b = await c2.newPage();
  await b.goto(BASE + '/topics'); const mission = (n) => b.getByRole('article', { name: new RegExp(`^Mission ${n}:`) }); await mission(1).waitFor();
  await shot(b, 'missions-1440', true);
  check('Board: 18 missions as named articles ("Mission N: title"), in API order', (await b.getByRole('article').count()) === 18
    && (await b.getByRole('article').evaluateAll((as) => as.map((a) => a.getAttribute('aria-label')))).every((n, i) => n === `Mission ${i + 1}: ${TOPICS[i].title}`));
  const gates = await b.locator('.gate-panel').evaluateAll((gs) => gs.map((g) => [g.querySelector('h2').textContent, g.querySelector('.gate-panel-count').textContent, g.querySelectorAll('article').length]));
  check('Board: six gates named from the seed groups, missions grouped by the API skill_group', gates.length === 6 && gates.every(([n], i) => n.endsWith(GROUPS[`G${i + 1}`]))
    && gates.map((g) => g[2]).join() === Object.keys(GROUPS).map((k) => TOPICS.filter((t) => t.skill_group === k).length).join());
  check('Board: gate counts only status "passed" (G1 "1 of 3 cleared"; G3 "1 of 3 cleared")', gates[0][1] === '1 of 3 cleared' && gates[2][1] === '1 of 3 cleared', gates.map((g) => g[1]).join(' | '));
  const label = async (n) => (await mission(n).locator('.status').innerText()).trim();
  const expect = { 1: 'Cleared', 2: 'Attempted, not cleared', 3: 'Quiz in progress', 4: 'Available', 5: 'Unlocked: content coming soon', 6: 'Locked', 7: 'Cleared', 8: 'Status unknown (archived)' };
  for (const [n, text] of Object.entries(expect)) check(`Board: mission ${n} shows "${text}"`, (await label(n)) === text, await label(n));
  check('Board: "Attempted" is never shown as cleared even with attempts and a best score (mission 2)', !/Cleared/.test(await mission(2).innerText()) && /best 2\/5/.test(await mission(2).innerText()));
  check('Board: "passed" stays "Cleared" even when content was withdrawn (overlay applies to "available" only)', (await label(7)) === 'Cleared');
  check('Live A9 expectation: mission 3 text vs mission 5/6 (content coming soon / Locked)', /content coming soon/.test(await mission(5).innerText()) && /Locked/.test(await mission(6).innerText()));
  const action = async (n) => { const l = mission(n).getByRole('link'); return (await l.count()) ? [(await l.innerText()).replace(/\n/g, ''), new URL(await l.evaluate((a) => a.href)).pathname] : null; };
  check('Board actions follow the state: cleared → Review; attempted → Retry; in progress → Resume quiz (/quiz/55); available → Start',
    JSON.stringify([await action(1), await action(2), await action(3), await action(4)]) === JSON.stringify([['Review mission: ' + TOPICS[0].title, '/hunter-system/topics/1'],
      ['Retry mission: ' + TOPICS[1].title, '/hunter-system/topics/2'], ['Resume quiz: ' + TOPICS[2].title, '/hunter-system/quiz/55'], ['Start mission: ' + TOPICS[3].title, '/hunter-system/topics/4']]));
  check('Board: locked and content-unavailable missions have no action; unknown status gets a neutral "Open mission"', (await action(5)) === null && (await action(6)) === null
    && (await action(8))?.[0].startsWith('Open mission'));
  check('Board: "Active" marks the dashboard rule (first unlocked, not passed = mission 2) only', (await b.locator('.mission.is-active').count()) === 1 && (await mission(2).getByText('Active', { exact: true }).isVisible()));
  check('Board: overall meter counts server "passed" only (2 of 18)', (await b.getByRole('progressbar', { name: 'Missions cleared' }).getAttribute('aria-valuenow')) === '2');
  check('Board: locked missions explain how to unlock; no "coins" wording', (await mission(6).innerText()).includes('Clear the previous mission to unlock.') && await noCoins(b));
  check('Board: the browser only read data (no unlock or progress writes)', c2.sim.api.every((a) => a.startsWith('GET ')));
  for (const width of [1440, 900, 768, 390, 320]) { await b.setViewportSize({ width, height: 900 }); await delay(80); check(`Board ${width}px: no horizontal overflow`, await noOverflow(b)); }
  await b.setViewportSize({ width: 390, height: 844 }); await shot(b, 'missions-390', true);

  // ==== 3. briefing, lesson, quiz runner start
  await b.setViewportSize({ width: 1440, height: 900 });
  await b.goto(BASE + '/topics/4'); await b.getByRole('heading', { level: 1 }).waitFor();
  const brief = await b.locator('main').innerText();
  check('Briefing: gate name, status, both learning choices, attempt history, Mana rule', brief.toLowerCase().includes(`gate ii · ${GROUPS.G2}`.toLowerCase()) && brief.includes('Available')
    && (await b.getByRole('link', { name: 'Read the lesson first' }).isVisible()) && (await b.getByRole('button', { name: 'Take the quiz directly' }).isVisible())
    && brief.includes('earns 10 Mana') && (await b.getByRole('region', { name: 'Your quiz attempts' }).locator('table').count()) === 1 && /D · 2\/5 · not passed/.test(brief) && await noCoins(b));
  await shot(b, 'briefing-1440', true);
  await b.getByRole('link', { name: 'Read the lesson first' }).click(); await b.locator('.prose').waitFor();
  check('Lesson (live A12 checks): safe Markdown, exact code text, ≥3 headings, ≥3 list items, one h1, script shown as text only',
    (await b.locator('.prose pre.code-block').first().innerText()).includes('print("Hello, " + name)') && (await b.locator('.prose h2, .prose h3').count()) >= 3
    && (await b.locator('.prose ul li').count()) >= 3 && (await b.locator('.prose script').count()) === 0 && (await b.locator('h1').count()) === 1
    && (await b.evaluate(() => window.bad)) === undefined && (await b.locator('.prose').innerText()).includes('<script>window.bad = true</script>'));
  check('Lesson: readable measure (≤ 780px column) with a briefing link back; no "coins" wording', (await b.locator('.lesson').evaluate((e) => e.getBoundingClientRect().width)) <= 780
    && (await b.getByRole('link', { name: '← Mission briefing' }).isVisible()) && await noCoins(b));
  await shot(b, 'lesson-1440', true);
  await b.getByRole('button', { name: 'Start the quiz' }).click(); await b.waitForURL(/\/quiz\/104$/);
  check('Lesson "Start the quiz" sends started_via "lesson_first" exactly once', JSON.stringify(c2.sim.starts) === '[{"started_via":"lesson_first"}]');
  for (let pos = 1; pos <= 5; pos++) {
    await b.locator(`input[type=radio][value="${2000 + pos * 10}"]`).check(); await b.locator('.save-saved').waitFor();
    if (pos < 5) await b.getByRole('button', { name: 'Next question' }).click();
  }
  await b.getByRole('button', { name: 'Submit quiz' }).click(); await b.getByText(/Quiz performance: D/).waitFor();
  check('Submitting shows the server result in place (Mission not cleared, no reward → explained as already paid)', (await b.getByRole('heading', { level: 1 }).innerText()) === 'Mission not cleared'
    && /already been paid/.test(await b.locator('main').innerText()));
  await b.setViewportSize({ width: 390, height: 844 }); await b.goto(BASE + '/topics/4/lesson'); await b.locator('.prose').waitFor();
  check('Lesson 390px: code blocks scroll inside, no page overflow', await noOverflow(b));
  await b.goto(BASE + '/topics/5/lesson'); await b.getByText('This lesson is not available yet.').waitFor();
  check('Live A10: lesson without content shows "This lesson is not available yet."', true);
  await c2.close();

  // ==== 4. quiz results from the server
  const c4 = await context(), r = await c4.newPage();
  await r.goto(BASE + '/quiz/101'); await r.getByText(/Quiz performance: D/).waitFor(); await shot(r, 'result-fail-1440', true);
  const fail = await r.locator('main').innerText();
  check('Fail result: "Mission not cleared" (server passed=false); live A14 text holds (not passed, +10 Mana, "Pass to unlock the next topic", 5 reviews)',
    (await r.getByRole('heading', { level: 1 }).innerText()) === 'Mission not cleared' && /not passed/.test(fail) && /\+10 Mana/.test(fail)
    && /Pass to unlock the next topic/.test(fail) && (await r.locator('.review-item').count()) === 5);
  check('Fail result: reward panel shows reward.balance_after ("Balance after: 20 Mana")', fail.replace(/\s+/g, ' ').includes('Balance after: 20 Mana'));
  check('Fail result: no "Rank up" panel without a promotion', (await r.getByRole('region', { name: 'Rank up' }).count()) === 0);
  check('Fail result: no "coins" wording', await noCoins(r), (fail.match(/.{0,40}coins?.{0,40}/i) || [''])[0]);
  await r.goto(BASE + '/quiz/102'); await r.getByText(/Quiz performance: B/).waitFor(); await shot(r, 'result-pass-1440', true);
  const pass = await r.locator('main').innerText();
  check('Pass result: "Mission cleared"; live A15 text holds ("— passed", "already been paid", "Unlocked: <title>")', (await r.getByRole('heading', { level: 1 }).innerText()) === 'Mission cleared'
    && /— passed/.test(pass) && /already been paid/.test(pass) && pass.includes(`Unlocked: ${TOPICS[4].title}`) && pass.includes('(content coming soon)'));
  check('Pass result: "Rank up" panel only because promotion was returned (E-Rank · Peasant → D-Rank · Apprentice, 3 missions)',
    (await r.getByRole('region', { name: 'Rank up' }).innerText()).search('E-Rank · Peasant') >= 0 && (await r.getByRole('region', { name: 'Rank up' }).innerText()).search('E-Rank · Peasant') < (await r.getByRole('region', { name: 'Rank up' }).innerText()).search('D-Rank · Apprentice') && pass.includes('after clearing 3 different missions'));
  check('Result actions unchanged (Try again, Read the lesson, Dashboard)', (await r.getByRole('button', { name: 'Try again' }).isVisible())
    && (await r.getByRole('link', { name: 'Read the lesson' }).isVisible()) && (await r.locator('main').getByRole('link', { name: 'Dashboard' }).isVisible()));
  for (const width of [1440, 900, 390, 320]) { await r.setViewportSize({ width, height: 900 }); await delay(80); check(`Result ${width}px: no horizontal overflow`, await noOverflow(r)); }
  await c4.close();

  // ==== 5. reduced motion
  const c5 = await context(DASH, { reducedMotion: 'reduce' }), m = await c5.newPage();
  await m.goto(BASE + '/topics'); await m.getByRole('article').first().waitFor();
  check('Reduced motion: in-progress mission marker does not animate', parseFloat(await m.locator('.mission-in_progress .mission-pos').evaluate((e) => getComputedStyle(e).animationDuration)) < 0.01);
  await c5.close();

  check('No external requests', [c1, c2, c4].every((c) => c.sim.external.length === 0));
  check('No uncaught browser errors', errors.length === 0, errors.join(' | '));
  console.log(`${checks} Phase 5 dashboard/mission checks passed — production build, SIMULATED API responses (UI evidence only, not live integration).`);
} finally {
  await browser?.close();
  preview.kill();
}
