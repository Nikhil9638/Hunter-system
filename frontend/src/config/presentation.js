// Presentation-only theme wording (docs/frontend-design-spec.md §3, §4.3, §5.1, §8.1).
// The single place to edit rank titles, experience-card characters, gate names and the
// currency label. NOTHING here is sent to the API or used to decide anything: PHP
// remains the source of truth for ranks, scores, progression, prices and balances.
import heroArt from '../assets/art/hero-awakened-hunter.webp';
import heroArtSmall from '../assets/art/hero-awakened-hunter-sm.webp';
import expE from '../assets/art/exp-e-sung-jinwoo.webp';
import expC from '../assets/art/exp-c-song-chi-yul.webp';
import expA from '../assets/art/exp-a-woo-jin-chul.webp';
import expS from '../assets/art/exp-s-cha-hae-in.webp';

/** Backend rank codes, lowest to highest (unchanged API/database values). */
export const RANK_CODES = ['E', 'D', 'C', 'B', 'A', 'S'];

/**
 * Display names for backend rank codes. `lore` is secondary theme wording only
 * (never a rank value, filter or code). The API's own name/title (e.g. "Rookie")
 * is deliberately NOT displayed.
 */
export const RANKS = {
  E: { label: 'Peasant', lore: 'Novice Hunter' },
  D: { label: 'Apprentice', lore: null },
  C: { label: 'Squire', lore: null },
  B: { label: 'Knight', lore: null },
  A: { label: 'Champion', lore: 'Heroic · Legendary' },
  S: { label: 'Mythic', lore: 'Immortal · Deity' },
};

/**
 * The rank code from any API shape: {code}, 'D' (rank history, promotions, admin
 * detail) or 'D-Rank' (admin student list). Unknown values return null so callers
 * can show the raw value instead of silently re-labelling it.
 */
export function rankCode(value) {
  const raw = value && typeof value === 'object' ? value.code : value;
  if (typeof raw !== 'string') return null;
  const m = /^([EDCBAS])(?:-Rank)?$/.exec(raw.trim());
  return m ? m[1] : null;
}

/** "D-Rank · Apprentice", built from the code only. Unknown input is returned as-is. */
export function rankDisplay(value) {
  const code = rankCode(value);
  if (!code) return value && typeof value === 'object' ? String(value.code ?? '') : String(value ?? '');
  return `${code}-Rank · ${RANKS[code].label}`;
}

/**
 * Experience self-description cards, keyed by the API's experience `code`
 * (GET /experience-levels). The id sent to PUT /me/experience always comes from
 * the API item, never from here. `themeRank` is decoration: it is NOT the
 * student's rank and never feeds any rank display or request.
 */
export const EXPERIENCE_CARDS = {
  complete_beginner: { character: 'Sung Jin-Woo', themeRank: 'E', art: expE, blurb: "I'm new to Python. Start with the fundamentals." },
  basic: { character: 'Song Chi-Yul', themeRank: 'C', art: expC, blurb: 'I know a few Python basics but need guided practice.' },
  intermediate: { character: 'Woo Jin-Chul', themeRank: 'A', art: expA, blurb: 'I understand core concepts and can solve small problems.' },
  master: { character: 'Cha Hae-In', themeRank: 'S', art: expS, blurb: "I'm confident with the basics and want to test my skills." },
};

/**
 * Curriculum skill-group names. Mirrors database/seed.sql `skill_groups`
 * (G1–G6); the student topic API returns only the code. Presentation only.
 */
export const SKILL_GROUPS = {
  G1: { numeral: 'I', name: 'Python basics' },
  G2: { numeral: 'II', name: 'Input and operators' },
  G3: { numeral: 'III', name: 'Control flow' },
  G4: { numeral: 'IV', name: 'Text and collections' },
  G5: { numeral: 'V', name: 'Functions, modules, errors' },
  G6: { numeral: 'VI', name: 'Files and objects' },
};

/** "Gate II · Input and operators"; unknown codes fall back to the raw code. */
export function gateName(code) {
  const g = SKILL_GROUPS[code];
  return g ? `Gate ${g.numeral} · ${g.name}` : String(code ?? '');
}

/** Decorative rank artwork tiers (spec §4.2). Never an avatar, never saved. */
export const ART = { hero: heroArt, heroSmall: heroArtSmall, young: expE };
export const RANK_ART = { E: 'young', D: 'young', C: 'awakened', B: 'awakened', A: 'awakened', S: 'awakened' };
export function rankArt(value) {
  const tier = RANK_ART[rankCode(value)];
  if (tier === 'young') return { src: ART.young, width: 480, height: 640 };
  if (tier === 'awakened') return { src: ART.hero, srcSmall: ART.heroSmall, width: 560, height: 735 };
  return null;
}

/** Visible name of the existing coin balance. Frontend wording only (spec §5.1). */
export const CURRENCY = { name: 'Mana' };
export const mana = (amount) => `${amount} ${CURRENCY.name}`;
