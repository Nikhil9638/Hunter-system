// Phase 2 component rendering: the REAL presentation module and primitives rendered in the
// installed Chrome. No API simulation and no live-integration claim.
// Run from frontend/: node tests/redesign-primitives.mjs
import { build } from 'vite';
import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import path from 'node:path';
import { readAppCss } from './app-css.mjs';

const result = await build({ root: process.cwd(), logLevel: 'error', define: { 'process.env.NODE_ENV': '"production"' },
  build: { write: false, minify: false, lib: { entry: path.resolve('tests/redesign-primitives-entry.jsx'), name: 'Primitives', formats: ['iife'] } } });
const bundle = (Array.isArray(result) ? result[0].output : result.output).find((o) => o.type === 'chunk').code;
const css = readAppCss();
const seed = readFileSync('../database/seed.sql', 'utf8');
let checks = 0;
const check = (name, ok) => { assert.ok(ok, name); checks++; console.log('PASS ' + name); };

const browser = await chromium.launch({ executablePath: process.env.HUNTER_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.setContent(`<style>${css}</style><main id="root" style="padding:16px"></main>`);
  await page.addScriptTag({ content: bundle });
  const text = () => page.locator('#root').innerText();

  // ---- rank labels (spec §3.1)
  const expected = { E: 'E-Rank · Peasant', D: 'D-Rank · Apprentice', C: 'C-Rank · Squire', B: 'B-Rank · Knight', A: 'A-Rank · Champion', S: 'S-Rank · Mythic' };
  for (const [code, label] of Object.entries(expected)) {
    check(`rankDisplay ${code} from {code}, '${code}' and '${code}-Rank'`, await page.evaluate(([c, l]) => {
      const p = window.presentation;
      return p.rankDisplay({ code: c, name: `${c}-Rank`, title: c === 'E' ? 'Rookie' : null }) === l && p.rankDisplay(c) === l && p.rankDisplay(`${c}-Rank`) === l;
    }, [code, label]));
  }
  check('Unknown rank values are shown raw, not re-labelled', await page.evaluate(() => window.presentation.rankCode('X') === null && window.presentation.rankDisplay('X') === 'X' && window.presentation.rankCode(null) === null));
  await page.evaluate(() => window.show.ranks([{ code: 'E', name: 'E-Rank', title: 'Rookie' }, 'D', 'C-Rank', { code: 'B' }, 'A', 'S', null, 'Z']));
  const ranksText = await text();
  check('RankBadge renders all six mapped labels', Object.values(expected).every((l) => ranksText.includes(l)));
  check('API title "Rookie" is never displayed', !/Rookie/i.test(ranksText) && !/Rookie/.test(await page.locator('#root').innerHTML()));
  check('Null rank keeps "Not ranked yet"; unknown code shown raw', ranksText.includes('Not ranked yet') && /\bZ\b/.test(ranksText));
  check('Each rank keeps its distinct shape class and an aria-hidden letter', (await page.locator('.rank-letter[aria-hidden=true]').count()) === 6
    && (await page.locator('.rank-S .rank-letter').evaluate((e) => getComputedStyle(e).clipPath)).startsWith('polygon'));
  check('Theme wording is lore only (not a rank code)', await page.evaluate(() => Object.keys(window.presentation.RANKS).join('') === 'EDCBAS'
    && window.presentation.RANKS.E.lore === 'Novice Hunter' && window.presentation.RANKS.S.lore.includes('Deity')));
  await page.evaluate(() => window.show.themeRank('S'));
  check('Theme rank variant is visibly marked "Theme" and outlined', (await text()).includes('Theme · S-Rank · Mythic') && (await page.locator('.rank-theme').count()) === 1);

  // ---- experience mapping (spec §3.2): keyed by real seed codes
  const seedCodes = [...seed.matchAll(/\(\d+, '([a-z_]+)',\s+'[^']+',\s+\d+\)/g)].map((m) => m[1]);
  check(`Seed experience codes found (${seedCodes.join(', ')})`, seedCodes.length === 4);
  check('Experience cards key exactly the seed codes (incl. "basic")', await page.evaluate((codes) => {
    const cards = window.presentation.EXPERIENCE_CARDS;
    return Object.keys(cards).sort().join() === [...codes].sort().join() && !('knows_little_basic_python' in cards)
      && cards.basic.character === 'Song Chi-Yul' && cards.complete_beginner.themeRank === 'E' && cards.master.character === 'Cha Hae-In';
  }, seedCodes));
  check('Experience config carries no API ids', await page.evaluate(() => Object.values(window.presentation.EXPERIENCE_CARDS).every((c) => !('id' in c))));

  // ---- gate names mirror database/seed.sql skill_groups (spec §4.3)
  const seedGroups = Object.fromEntries([...seed.matchAll(/\(\d, '(G\d)', '([^']+)',\s+\d\)/g)].map((m) => [m[1], m[2].trim()]));
  check('Six seed skill groups found', Object.keys(seedGroups).length === 6);
  check('SKILL_GROUPS mirror seed names exactly', await page.evaluate((g) => Object.entries(g).every(([k, v]) => window.presentation.SKILL_GROUPS[k]?.name === v), seedGroups));
  check('gateName formats and falls back to raw code', await page.evaluate(() => window.presentation.gateName('G2') === 'Gate II · Input and operators' && window.presentation.gateName('G9') === 'G9'));

  // ---- Mana wording (spec §5.1)
  await page.evaluate(() => window.show.mana(40));
  check('ManaAmount shows "40 Mana" with a decorative icon', (await text()).trim() === '40 Mana' && (await page.locator('.mana svg[aria-hidden=true]').count()) === 1);

  // ---- ProgressMeter
  await page.evaluate(() => window.show.meter({ value: 3, max: 9, label: 'Missions toward B-Rank' }));
  const bar = page.getByRole('progressbar', { name: 'Missions toward B-Rank' });
  check('ProgressMeter exposes labelled progressbar with API values', (await bar.getAttribute('aria-valuenow')) === '3' && (await bar.getAttribute('aria-valuemax')) === '9' && (await text()).includes('3 / 9'));
  await page.evaluate(() => window.show.meter({ value: 12, max: 9, label: 'Clamp' }));
  check('ProgressMeter clamps out-of-range values', (await page.getByRole('progressbar').getAttribute('aria-valuenow')) === '9');

  // ---- HudPanel, RankEmblem
  await page.evaluate(() => window.show.panel());
  check('HudPanel is a region named by its heading; corners are decorative', (await page.getByRole('region', { name: 'Status' }).count()) === 1 && (await page.locator('.hud-corner[aria-hidden=true]').count()) === 4);
  await page.evaluate(() => window.show.emblem('A'));
  check('RankEmblem is decorative by default', (await page.locator('svg.rank-emblem[aria-hidden=true]').count()) === 1);
  await page.evaluate(() => window.show.emblem('S', 'S-Rank · Mythic'));
  check('RankEmblem with title is an image with that name', (await page.getByRole('img', { name: 'S-Rank · Mythic' }).count()) === 1);

  // ---- CharacterArt (spec §4.2): tiers, decorative alt, mandatory caption
  for (const [rank, tier] of [['E', 'young'], ['D', 'young'], ['C', 'hero'], ['S', 'hero']]) {
    await page.evaluate((r) => window.show.art(r), rank);
    const img = page.locator('.character-frame img');
    await img.evaluate((i) => i.complete || new Promise((r) => { i.onload = r; i.onerror = r; }));
    // naturalWidth is density-corrected for srcset "w" candidates, so measure the pixel
    // width of the file the browser actually selected (currentSrc).
    const info = await img.evaluate(async (i) => {
      const probe = new Image(); probe.src = i.currentSrc; await probe.decode();
      return { alt: i.getAttribute('alt'), w: probe.naturalWidth, srcset: i.getAttribute('srcset') };
    });
    check(`CharacterArt ${rank}: ${tier} art (${info.w}px file), alt="", decoded, caption visible`, info.alt === '' && info.w === (tier === 'young' ? 480 : 560)
      && (tier === 'hero') === !!info.srcset && (await page.getByText('Decorative rank artwork. Not an avatar.').isVisible()));
  }
  await page.evaluate(() => window.show.art(null));
  check('CharacterArt without a rank shows an emblem, no character image', (await page.locator('.character-frame').count()) === 0 && (await page.locator('svg.rank-emblem').count()) === 1);

  // ---- ConfirmDialog (spec §5.3)
  await page.evaluate(() => { window.__confirmed = 0; window.__cancelled = 0; window.show.dialog(false); });
  await page.locator('#opener').focus();
  await page.keyboard.press('Enter');
  await page.locator('dialog[open]').waitFor();
  check('Dialog is modal, named, described, focus starts on Cancel', (await page.getByRole('dialog', { name: 'Confirm purchase' }).count()) === 1
    && (await page.evaluate(() => document.activeElement.textContent)) === 'Cancel'
    && (await page.getByRole('dialog').getAttribute('aria-describedby')) !== null);
  await page.keyboard.press('Escape');
  await page.locator('dialog[open]').waitFor({ state: 'detached' }).catch(() => {});
  check('Escape cancels once and returns focus to the opener', (await page.locator('dialog[open]').count()) === 0
    && (await page.evaluate(() => window.__cancelled)) === 1 && (await page.evaluate(() => document.activeElement.id)) === 'opener');
  await page.locator('#opener').click();
  await page.getByRole('button', { name: 'Spend 10 Mana' }).click();
  check('Confirm calls onConfirm exactly once and closes', (await page.evaluate(() => window.__confirmed)) === 1 && (await page.locator('dialog[open]').count()) === 0);
  await page.evaluate(() => { window.__cancelled = 0; window.show.dialog(true); });
  await page.locator('#opener').click();
  await page.keyboard.press('Escape');
  check('While pending: buttons disabled and Escape does not cancel', (await page.locator('dialog[open]').count()) === 1 && (await page.evaluate(() => window.__cancelled)) === 0
    && (await page.getByRole('button', { name: 'Working…' }).isDisabled()) && (await page.getByRole('button', { name: 'Cancel' }).isDisabled()));

  // ---- focus ring and narrow layout
  await page.evaluate(() => window.show.dialog(false));
  await page.locator('#opener').focus();
  await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');
  const ring = await page.locator('#opener').evaluate((e) => { const s = getComputedStyle(e); return [s.outlineStyle, s.outlineWidth, s.outlineColor]; });
  check('Keyboard focus shows a 3px cyan outline', ring.join('|') === 'solid|3px|rgb(139, 227, 255)');
  await page.setViewportSize({ width: 320, height: 800 });
  for (const fn of ['panel', 'meter', 'art']) {
    await page.evaluate((f) => window.show[f](f === 'meter' ? { value: 1, max: 18, label: 'Missions cleared' } : f === 'art' ? 'S' : undefined), fn);
    check(`${fn} has no horizontal overflow at 320px`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  }
  check('No uncaught page errors', errors.length === 0);
  console.log(`${checks} Phase 2 primitive rendering checks passed (Chrome ${await browser.version()}; component rendering only, not live API evidence).`);
} finally {
  await browser.close();
}
