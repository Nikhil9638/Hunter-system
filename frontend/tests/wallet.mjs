// Phase 6 checks: Rank & Mana history, badge store and profile/showcase — against the REAL
// production build. ALL API responses are SIMULATED in-browser by a stateful mock (purchases
// change ownership and balance; showcase saves change the showcase). The preview proxy points
// at an unreachable port, so nothing reaches Apache/dev and no real ownership or showcase is
// touched. UI evidence only — never reported as API integration.
// Run from frontend/ after `npm run build`: node tests/wallet.mjs [--shots <dir>]
import { chromium } from 'playwright-core';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import assert from 'node:assert/strict';

const PORT = 4199, BASE = `http://localhost:${PORT}/hunter-system`;
const SHOTS = process.argv.includes('--shots') ? process.argv[process.argv.indexOf('--shots') + 1] : null;
const shot = async (p, name, fullPage = false) => { if (SHOTS) await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage }); };
let checks = 0;
const check = (name, ok, detail = '') => { assert.ok(ok, `${name}${detail ? ` (${detail})` : ''}`); checks++; console.log('PASS ' + name); };

const seed = readFileSync('../database/seed.sql', 'utf8');
const TOPICS = [...seed.matchAll(/^\s*\(\s*(\d+),\s*\d+,\s*'[a-z0-9-]+',\s*'([^']+)',\s*'[^']*',\s*(\d)/gm)]
  .map((m) => ({ id: +m[1], position: +m[1], title: m[2].trim(), skill_group: 'G' + m[3], unlocked: true, content_ready: true, status: 'available' }));
const ART = (n) => `/hunter-system/assets/badges/m8b-shadow-${n}-v2.png`;          // real local PNGs, served by Apache in production

function fresh(opts = {}) {
  const catalogue = [
    { id: 11, name: 'Shadow Recruit', description: 'Simulated badge.', image_url: ART('recruit'), price: 10 },
    { id: 12, name: 'Shadow Soldier', description: 'Simulated badge.', image_url: ART('soldier'), price: 15 },
    { id: 13, name: 'Shadow Knight', description: 'Simulated badge.', image_url: null, price: 20 },
    { id: 14, name: 'Shadow Monarch', description: 'Simulated badge.', image_url: ART('monarch'), price: 60 },
  ];
  // 25 ledger entries, newest first (ids 25..1): rewards and one purchase of badge 11.
  const ledger = Array.from({ length: 25 }, (_, i) => {
    const id = 25 - i;
    return id === 25 ? { id, at: '2026-10-18T12:00:00.000Z', direction: 'debit', amount: 10, balance_after: 40, type: 'badge_purchase', topic_id: null, badge_id: 11 }
      : { id, at: `2026-10-${String(1 + (id % 17)).padStart(2, '0')}T09:00:00.000Z`, direction: 'credit', amount: 10, balance_after: 10 * id, type: 'topic_reward', topic_id: ((id - 1) % 18) + 1, badge_id: null };
  });
  return { balance: 40, catalogue: opts.emptyStore ? [] : catalogue, owned: opts.noBadges ? [] : [
      { badge_id: 11, name: 'Shadow Recruit', description: 'Simulated badge.', image_url: ART('recruit'), price_paid: 10, purchased_at: '2026-10-18T12:00:00.000Z', status: 'on_sale' },
      { badge_id: 15, name: 'Old Sigil', description: 'Simulated retired badge.', image_url: null, price_paid: 5, purchased_at: '2026-10-10T12:00:00.000Z', status: 'retired' },
      { badge_id: 16, name: 'Night Crest', description: 'Simulated off-sale badge.', image_url: ART('knight'), price_paid: 8, purchased_at: '2026-10-11T12:00:00.000Z', status: 'off_sale' },
      { badge_id: 17, name: 'Code Scout', description: 'Simulated badge.', image_url: '/hunter-system/assets/badges/m8b-code-scout-v1.png', price_paid: 7, purchased_at: '2026-10-12T12:00:00.000Z', status: 'on_sale' }],
    showcase: opts.noBadges ? [] : [15], ledger: opts.emptyLedger ? [] : ledger, purchases: [], showcasePuts: [], coinsQueries: [], api: [], purchaseOutcome: null, showcaseFail: false, topicsFail: false };
}
function simulate(s, method, path, search, body) {
  const ownedIds = s.owned.map((b) => b.badge_id);
  if (path === '/auth/me') return [200, { user: { id: 1, email: 'sim@example.invalid', display_name: 'Sim Student', role: 'student', onboarding_state: 'assessment_submitted' }, csrf_token: 'simulated' }];
  if (path === '/badges') return [200, s.catalogue.map((b) => ({ ...b, status: 'on_sale', owned: ownedIds.includes(b.id), affordable: !ownedIds.includes(b.id) && b.price <= s.balance }))];
  if (path === '/me/profile') return [200, { display_name: 'Sim Student', experience_level: { code: 'master', label: 'Master' }, placement_rank: { code: 'C', name: 'C-Rank', title: null },
    hunter_rank: { code: 'B', name: 'B-Rank', title: null }, topics_passed: 9, topics_total: 18, coins: s.balance, showcase_limit: 3,
    showcase: s.showcase.map((id, i) => { const b = s.owned.find((o) => o.badge_id === id); return { position: i + 1, badge_id: id, name: b.name, image_url: b.image_url, status: b.status }; }),
    owned_badges: s.owned.map((b) => ({ ...b, showcased: s.showcase.includes(b.badge_id) })) }];
  if (path === '/topics') return s.topicsFail ? [500, { error: { code: 'internal_error', message: 'Simulated failure.' } }] : [200, TOPICS];
  if (path === '/me/rank-history') return [200, [{ at: '2026-10-01T09:00:00.000Z', reason: 'placement', from: null, to: 'C' },
    { at: '2026-10-15T09:00:00.000Z', reason: 'promotion', from: 'C', to: 'B', topics_passed: 9, evidence_attempt_id: 90, policy_version: 1 }]];
  if (path === '/me/coins') {
    s.coinsQueries.push(search);
    const q = new URLSearchParams(search), limit = +q.get('limit') || 50, before = q.get('before_id');
    const list = before ? s.ledger.filter((e) => e.id < +before) : s.ledger;
    return [200, { balance: s.balance, entries: list.slice(0, limit) }];
  }
  const m = /^\/badges\/(\d+)\/purchase$/.exec(path);
  if (m && method === 'POST') {
    const id = +m[1], b = s.catalogue.find((x) => x.id === id);
    s.purchases.push({ id, body });
    if (s.purchaseOutcome) { const o = s.purchaseOutcome; s.purchaseOutcome = null; if (o.mutate) o.mutate(s); return o.response; }
    if (ownedIds.includes(id)) { const o = s.owned.find((x) => x.badge_id === id); return [409, { error: { code: 'already_owned', message: 'Already owned.', details: { badge_id: id, price_paid: o.price_paid, purchased_at: o.purchased_at } } }]; }
    if (b.price > s.balance) return [409, { error: { code: 'insufficient_coins', message: 'Insufficient.', details: { balance: s.balance, price: b.price } } }];
    s.balance -= b.price;
    s.owned.push({ badge_id: id, name: b.name, description: b.description, image_url: b.image_url, price_paid: b.price, purchased_at: '2026-10-18T13:00:00.000Z', status: 'on_sale' });
    return [200, { badge_id: id, name: b.name, price_paid: b.price, balance_after: s.balance, purchased_at: '2026-10-18T13:00:00.000Z' }];
  }
  if (path === '/me/showcase' && method === 'PUT') {
    s.showcasePuts.push(body);
    if (s.showcaseFail) return [500, { error: { code: 'internal_error', message: 'Simulated server failure.' } }];
    s.showcase = body.badge_ids;
    return [200, { showcase: s.showcase.map((id, i) => { const b = s.owned.find((o) => o.badge_id === id); return { position: i + 1, badge_id: id, name: b.name, image_url: b.image_url, status: b.status }; }) }];
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
  async function context(state, options = {}) {
    const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
    c.sim = state;
    await c.route('**/hunter-system/api/v1/**', async (route) => {
      const req = route.request(), u = new URL(req.url()), path = u.pathname.split('/api/v1')[1];
      c.sim.api.push(`${req.method()} ${path}`);
      if (c.sim.abortNext && c.sim.abortNext.test(path)) { c.sim.abortNext = null; if (path.endsWith('/purchase')) c.sim.purchases.push({ id: +path.split('/')[2], aborted: true }); await route.abort('failed'); return; }
      const [status, body] = simulate(c.sim, req.method(), path, u.search, req.postData() ? JSON.parse(req.postData()) : undefined);
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(status < 300 ? { data: body } : body) });
    });
    // Badge PNGs are real local files; serve them from disk (no Apache needed in this simulated run).
    await c.route('**/hunter-system/assets/badges/*.png', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: readFileSync(`../assets/badges/${new URL(route.request().url()).pathname.split('/').pop()}`) }));
    c.on('page', (p) => { p.on('pageerror', (e) => errors.push(e.message)); p.on('request', (r) => { if (!['localhost', '127.0.0.1'].includes(new URL(r.url()).hostname)) external.push(r.url()); }); });
    return c;
  }
  const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  const noCoins = async (p) => !/\bcoins?\b/i.test(await p.locator('main').innerText());
  const flat = (t) => t.replace(/\s+/g, ' ');

  // ==== 1. Rank & Mana history
  const ch = await context(fresh()), h = await ch.newPage();
  await h.goto(BASE + '/history'); await h.locator('.ledger li').first().waitFor(); await h.getByText('Starting rank from the placement check').waitFor(); await delay(150);
  await shot(h, 'history-1440', true);
  const tl = flat(await h.locator('.timeline').innerText());
  check('Timeline: placement then promotion with mapped ranks (C-Rank · Squire → B-Rank · Knight)', /C-Rank · Squire.*Starting rank from the placement check.*B-Rank · Knight.*Promoted from C-Rank after clearing 9 different missions/.test(tl), tl);
  check('History separates quiz grades (A–F) from the Hunter rank (E–S)', /Quiz grades \(A–F\) belong to single attempts and never change your rank/.test(await h.locator('main').innerText()));
  check('Ledger: balance and first page of 20 entries (limit=20)', (await h.locator('.ledger li').count()) === 20 && flat(await h.locator('.wallet-balance').innerText()).includes('40 Mana') && ch.sim.coinsQueries[0] === '?limit=20');
  const first = flat(await h.locator('.ledger li').first().innerText()), second = flat(await h.locator('.ledger li').nth(1).innerText());
  check('Ledger debit: "Mana spent · Badge purchase · Badge: Shadow Recruit", amount −10, balance 40 Mana', first.includes('−10') && first.includes('Mana spent · Badge purchase · Badge: Shadow Recruit') && first.includes('balance 40 Mana'), first);
  check(`Ledger credit: "Mana earned · Mission reward · First completion of mission 6: ${TOPICS[5].title}"`, second.includes('+10') && second.includes(`Mana earned · Mission reward · First completion of mission 6: ${TOPICS[5].title}`), second);
  await h.getByRole('button', { name: 'Show older entries' }).click(); await h.waitForFunction(() => document.querySelectorAll('.ledger li').length === 25);
  check('"Show older entries" requests before_id of the last entry and appends the remaining 5', ch.sim.coinsQueries.at(-1) === '?limit=20&before_id=6' && (await h.locator('.ledger li').count()) === 25);
  check('The button disappears when the server returns fewer than 20', (await h.getByRole('button', { name: 'Show older entries' }).count()) === 0);
  check('History uses Mana wording only', await noCoins(h));
  for (const width of [1440, 900, 390, 320]) { await h.setViewportSize({ width, height: 900 }); await delay(80); check(`History ${width}px: no horizontal overflow`, await noOverflow(h)); }
  await ch.close();
  const st = fresh(); st.topicsFail = true;
  const cf = await context(st), hf = await cf.newPage();
  await hf.goto(BASE + '/history'); await hf.locator('.ledger li').nth(1).waitFor(); await delay(200);
  check('Lookup failure: mission names fall back to ids ("mission #6"); ledger still shown; badge name still resolved', flat(await hf.locator('.ledger li').nth(1).innerText()).includes('First completion of mission #6')
    && flat(await hf.locator('.ledger li').first().innerText()).includes('Badge: Shadow Recruit') && (await hf.getByRole('alert').count()) === 0);
  await cf.close();
  const ce = await context(fresh({ emptyLedger: true })), he = await ce.newPage();
  await he.goto(BASE + '/history'); await he.getByText(/No Mana activity yet/).waitFor();
  check('Empty ledger: explains how to earn 10 Mana; no "Show older entries"', (await he.locator('.ledger li').count()) === 0 && (await he.getByRole('button', { name: 'Show older entries' }).count()) === 0);
  await ce.close();

  // ==== 2. Badge store
  const cs = await context(fresh()), s = await cs.newPage();
  const card = (name) => s.locator('.badge-card').filter({ has: s.getByRole('heading', { name, exact: true }) });
  await s.goto(BASE + '/store'); await s.getByRole('heading', { name: 'Badge store', exact: true }).waitFor(); await card('Shadow Recruit').waitFor();
  await s.locator('.badge-card img').first().evaluate((i) => i.decode()); await shot(s, 'store-1440', true);
  check('Store: four API badges as collectible cards; balance in the lead ("40 Mana")', (await s.locator('.badge-card').count()) === 4 && flat(await s.locator('.lead').innerText()).includes('Your balance: 40 Mana'));
  check('Owned (API flag): "Owned" seal, no Buy, profile link', (await card('Shadow Recruit').getByText('Owned', { exact: true }).isVisible()) && (await card('Shadow Recruit').getByRole('button', { name: 'Buy' }).count()) === 0);
  check('Affordable (API flag): Buy enabled; unaffordable: Buy disabled with "need 20 Mana more" (price 60 − balance 40)', (await card('Shadow Soldier').getByRole('button', { name: 'Buy' }).isEnabled())
    && (await card('Shadow Monarch').getByRole('button', { name: 'Buy' }).isDisabled()) && (await card('Shadow Monarch').innerText()).includes('you need 20 Mana more'));
  check('Real local PNG art (512px) and a neutral placeholder when image_url is null', (await card('Shadow Soldier').locator('img').evaluate(async (i) => { await i.decode(); return i.naturalWidth === 512 && i.alt === ''; }))
    && (await card('Shadow Knight').locator('.badge-art-placeholder').count()) === 1);
  check('Prices shown as Mana from the API ("15 Mana"); no "coins" wording', (await card('Shadow Soldier').locator('.price').innerText()).includes('15 Mana') && await noCoins(s));
  // confirmation dialog: cancel paths send nothing
  await card('Shadow Soldier').getByRole('button', { name: 'Buy' }).click(); await s.locator('dialog[open]').waitFor();
  if (SHOTS) { await delay(400); await shot(s, 'store-confirm-1440'); }   // after the 220ms entrance animation
  const dlg = s.getByRole('dialog', { name: 'Confirm purchase' });
  check('Buy opens a modal "Confirm purchase" dialog: "Spend 15 Mana on Shadow Soldier?", focus on Cancel', (await dlg.count()) === 1 && flat(await dlg.innerText()).includes('Spend 15 Mana on Shadow Soldier?')
    && (await s.evaluate(() => document.activeElement.textContent)) === 'Cancel');
  await s.keyboard.press('Escape'); await s.locator('dialog[open]').waitFor({ state: 'detached' }).catch(() => {});
  check('Escape cancels: no purchase request; focus returns to Buy', cs.sim.purchases.length === 0 && (await s.evaluate(() => document.activeElement.textContent)) === 'Buy');
  await card('Shadow Soldier').getByRole('button', { name: 'Buy' }).click(); await dlg.getByRole('button', { name: 'Cancel' }).click();
  check('Cancel: no purchase request', cs.sim.purchases.length === 0 && (await s.locator('dialog[open]').count()) === 0);
  // success (keyboard: Enter on Buy, Tab to Spend, Enter)
  await card('Shadow Soldier').getByRole('button', { name: 'Buy' }).focus(); await s.keyboard.press('Enter'); await s.locator('dialog[open]').waitFor();
  await s.keyboard.press('Tab');
  check('Keyboard: Tab moves from Cancel to "Spend 15 Mana" inside the modal', (await s.evaluate(() => document.activeElement.textContent)) === 'Spend 15 Mana');
  await s.keyboard.press('Enter'); await s.locator('main .notice').first().waitFor(); await card('Shadow Soldier').getByText('Owned', { exact: true }).waitFor();
  const msg = flat(await s.locator('main .notice').first().innerText());
  check('Success: exactly one POST /badges/12/purchase with no body; message from the response; refreshed card shows Owned; balance 25 Mana',
    cs.sim.purchases.length === 1 && cs.sim.purchases[0].id === 12 && cs.sim.purchases[0].body === undefined && /Badge purchased/.test(msg) && msg.includes('Shadow Soldier for 15 Mana. Balance: 25 Mana.')
    && flat(await s.locator('.lead').innerText()).includes('25 Mana'), msg);
  // duplicate ownership (server says already_owned)
  cs.sim.purchaseOutcome = { response: [409, { error: { code: 'already_owned', message: 'Already owned.', details: { badge_id: 13, price_paid: 20, purchased_at: '2026-10-18T13:30:00.000Z' } } }],
    mutate: (x) => x.owned.push({ badge_id: 13, name: 'Shadow Knight', description: 'x', image_url: null, price_paid: 20, purchased_at: '2026-10-18T13:30:00.000Z', status: 'on_sale' }) };
  await card('Shadow Knight').getByRole('button', { name: 'Buy' }).click(); await card('Shadow Knight').getByRole('button', { name: /^Spend \d+ Mana$/ }).click();
  await card('Shadow Knight').getByText('Owned', { exact: true }).waitFor();
  check('Already owned (409): explains no Mana was taken (price_paid from details); store refreshed to Owned; balance unchanged',
    flat(await s.locator('main .notice').first().innerText()).includes('You already own this badge (bought for 20 Mana). No Mana was taken.') && flat(await s.locator('.lead').innerText()).includes('25 Mana'));
  await cs.close();

  const s2 = fresh(); s2.catalogue[3].price = 30;     // UI thinks affordable; server disagrees
  const c2 = await context(s2), q = await c2.newPage();
  const card2 = (name) => q.locator('.badge-card').filter({ has: q.getByRole('heading', { name, exact: true }) });
  await q.goto(BASE + '/store'); await card2('Shadow Monarch').waitFor();
  c2.sim.purchaseOutcome = { response: [409, { error: { code: 'insufficient_coins', message: 'Insufficient.', details: { balance: 40, price: 30 + 25 } } }] };
  await card2('Shadow Monarch').getByRole('button', { name: 'Buy' }).click(); await card2('Shadow Monarch').getByRole('button', { name: /^Spend \d+ Mana$/ }).click();
  await q.locator('main .notice').first().waitFor();
  check('Insufficient Mana (409): message uses the server details ("you have 40, it costs 55"), not the UI price', flat(await q.locator('main .notice').first().innerText()).includes('Not enough Mana: you have 40, it costs 55.'));
  c2.sim.purchaseOutcome = { response: [409, { error: { code: 'not_on_sale', message: 'Not on sale.' } }], mutate: (x) => { x.catalogue = x.catalogue.filter((b) => b.id !== 12); } };
  await card2('Shadow Soldier').getByRole('button', { name: 'Buy' }).click(); await card2('Shadow Soldier').getByRole('button', { name: /^Spend \d+ Mana$/ }).click();
  await q.getByText(/no longer for sale/).waitFor(); await q.waitForFunction(() => document.querySelectorAll('.badge-card').length === 3);
  check('Off sale (409 not_on_sale): explained; store refreshed (badge gone); balance unchanged', (await card2('Shadow Soldier').count()) === 0 && flat(await q.locator('.lead').innerText()).includes('40 Mana'));
  c2.sim.purchaseOutcome = { response: [500, { error: { code: 'internal_error', message: 'Simulated server failure.', details: { error_id: 'sim' } } }] };
  await card2('Shadow Knight').getByRole('button', { name: 'Buy' }).click(); await card2('Shadow Knight').getByRole('button', { name: /^Spend \d+ Mana$/ }).click();
  await q.getByText('Simulated server failure.').waitFor();
  check('Server error (500): shows the server message; nothing marked owned', (await card2('Shadow Knight').getByText('Owned', { exact: true }).count()) === 0);
  const before = c2.sim.purchases.length;
  c2.sim.abortNext = /\/badges\/13\/purchase$/;
  await card2('Shadow Knight').getByRole('button', { name: 'Buy' }).click(); await card2('Shadow Knight').getByRole('button', { name: /^Spend \d+ Mana$/ }).click();
  await q.getByText(/could not confirm the purchase/).waitFor(); await delay(800);
  check('Network failure: "could not confirm… refresh before trying again"; exactly one attempt, never retried; no success invented',
    c2.sim.purchases.length === before + 1 && c2.sim.purchases.at(-1).aborted === true && !/Badge purchased/.test(await q.locator('main').innerText()));
  // double activation is single-flight
  const n0 = c2.sim.purchases.length;
  await card2('Shadow Knight').getByRole('button', { name: 'Buy' }).click();
  const spend = card2('Shadow Knight').getByRole('button', { name: /^Spend \d+ Mana$/ });
  await spend.evaluate((b) => { b.click(); b.click(); });
  await q.locator('main .notice').first().waitFor(); await delay(500);
  check('Double activation of "Spend" sends one purchase request', c2.sim.purchases.length === n0 + 1);
  for (const width of [1440, 900, 768, 390, 320]) { await q.setViewportSize({ width, height: 900 }); await delay(80); check(`Store ${width}px: no horizontal overflow`, await noOverflow(q)); }
  await q.setViewportSize({ width: 390, height: 844 }); await shot(q, 'store-390', true);
  await c2.close();

  const c3 = await context(fresh({ emptyStore: true })), e = await c3.newPage();
  await e.goto(BASE + '/store'); await e.getByText('No badges for sale yet').waitFor(); await shot(e, 'store-empty-1440');
  check('Empty store: deliberate sealed-vault state, no cards, balance kept', (await e.locator('.badge-card').count()) === 0 && (await e.getByRole('region', { name: 'No badges for sale yet' }).count()) === 1
    && flat(await e.locator('.vault-empty').innerText()).includes('40 Mana'));
  await c3.close();

  const c4 = await context(fresh(), { reducedMotion: 'reduce' }), rm = await c4.newPage();
  await rm.goto(BASE + '/store'); await rm.locator('.badge-card').first().waitFor();
  await rm.getByRole('button', { name: 'Buy' }).first().click(); await rm.locator('dialog[open]').waitFor();
  check('Reduced motion: the confirmation dialog appears without animation', parseFloat(await rm.locator('dialog[open]').evaluate((d) => getComputedStyle(d).animationDuration)) < 0.01);
  await c4.close();

  // ==== 3. Profile and showcase
  const cp = await context(fresh()), p = await cp.newPage();
  await p.goto(BASE + '/profile'); await p.getByRole('heading', { name: 'Your badges', exact: true }).waitFor(); await delay(150);
  await shot(p, 'profile-1440', true);
  const top = flat(await p.locator('main').innerText());
  check('Profile card: mapped ranks (B-Rank · Knight; C-Rank · Squire), 9 / 18, Mana, experience, decorative art captioned', top.includes('B-Rank · Knight') && top.includes('C-Rank · Squire')
    && top.includes('9 / 18') && top.includes('40 Mana') && top.includes('Master') && await p.getByText('Decorative rank artwork. Not an avatar.').isVisible() && await noCoins(p));
  check('Showcase: 1 filled slot (retired badge, tagged) + 2 empty slots; ".showcase li" counts filled slots only', (await p.locator('.showcase li').count()) === 1
    && /retired/.test(await p.locator('.showcase li').first().innerText()) && (await p.getByRole('list', { name: '2 empty showcase slots' }).count()) === 1);
  check('Collection: statuses from the API ("retired", "off sale"); paid amounts in Mana', /Old Sigil\s*retired/.test(await p.locator('.owned-item', { hasText: 'Old Sigil' }).innerText())
    && /off sale/.test(await p.locator('.owned-item', { hasText: 'Night Crest' }).innerText()) && /paid 10 Mana/.test(await p.locator('.owned-item', { hasText: 'Shadow Recruit' }).innerText()));
  await p.getByRole('checkbox', { name: /Shadow Recruit/ }).check(); await p.getByRole('checkbox', { name: /Night Crest/ }).check();
  check('Limit: with 3 chosen the 4th checkbox is disabled', await p.getByRole('checkbox', { name: /Code Scout/ }).isDisabled());
  await p.getByRole('button', { name: 'Move Night Crest up' }).click();
  check('Order list follows the draft (Old Sigil, Night Crest, Shadow Recruit)', (await p.locator('.order li span').allInnerTexts()).join('|') === '1. Old Sigil|2. Night Crest|3. Shadow Recruit');
  await p.getByRole('button', { name: 'Undo changes' }).click();
  check('Undo restores the saved selection', (await p.locator('.order li').count()) === 1 && !(await p.getByRole('checkbox', { name: /Night Crest/ }).isChecked()));
  await p.getByRole('checkbox', { name: /Night Crest/ }).check(); await p.getByRole('checkbox', { name: /Shadow Recruit/ }).check();
  await p.getByRole('button', { name: 'Move Shadow Recruit up' }).click();
  await p.getByRole('button', { name: 'Save showcase' }).click(); await p.getByText('Showcase saved.', { exact: true }).waitFor();
  check('Save sends PUT /me/showcase with exactly {badge_ids} in the chosen order [15, 11, 16]', JSON.stringify(cp.sim.showcasePuts) === '[{"badge_ids":[15,11,16]}]');
  check('Showcase reflects the server response (3 filled slots, no empty slots)', (await p.locator('.showcase li').allInnerTexts()).map((t) => t.split('\n')[0]).join('|') === 'Old Sigil|Shadow Recruit|Night Crest'
    && (await p.locator('.showcase-empty').count()) === 0);
  cp.sim.showcaseFail = true;
  await p.getByRole('checkbox', { name: /Night Crest/ }).uncheck(); await p.getByRole('button', { name: 'Save showcase' }).click();
  await p.getByText(/previous showcase is unchanged/).waitFor();
  check('Failed save: error shown, draft restored to the saved showcase (checkbox re-checked), showcase unchanged', (await p.getByRole('checkbox', { name: /Night Crest/ }).isChecked())
    && (await p.locator('.showcase li').count()) === 3);
  for (const width of [1440, 900, 390, 320]) { await p.setViewportSize({ width, height: 900 }); await delay(80); check(`Profile ${width}px: no horizontal overflow`, await noOverflow(p)); }
  await p.setViewportSize({ width: 390, height: 844 }); await shot(p, 'profile-390', true);
  await cp.close();
  const cn = await context(fresh({ noBadges: true })), pn = await cn.newPage();
  await pn.goto(BASE + '/profile'); await pn.getByText("You don't own any badges yet.").waitFor();
  check('No badges: explained with a store link; three empty slots; "No badges showcased."', (await pn.getByRole('list', { name: '3 empty showcase slots' }).count()) === 1
    && (await pn.getByText('No badges showcased.').isVisible()) && (await pn.getByRole('button', { name: 'Save showcase' }).count()) === 0);
  await cn.close();

  check('No external requests', external.length === 0, external.join(' | '));
  check('No uncaught browser errors', errors.length === 0, errors.join(' | '));
  console.log(`${checks} Phase 6 history/store/profile checks passed — production build, SIMULATED API responses (UI evidence only, not live integration).`);
} finally {
  await browser?.close();
  preview.kill();
}
