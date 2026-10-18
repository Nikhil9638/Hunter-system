# Hunter System: Frontend Redesign Specification (Phase 1)

**Status:** Phase 1 deliverable, awaiting approval. No application code, configuration, `dist/`, database or `.htaccess` changed in this phase.
**Date:** 2026-10-18
**Inputs:** `reference/CLAUDE_CODE_FRONTEND_REDESIGN_PROMPT.md` (master prompt), the Phase 0 audit, the user's Phase 1 decisions (2026-10-18), `docs/api-contract.md`, `docs/project-context.md`, `docs/decisions.md`, `database/seed.sql`.
**Baseline:** Git commit `459c0d9` (*Baseline: Hunter System before frontend redesign*). Backups are listed in §10.2.

The redesign is presentation only. PHP and MariaDB remain the source of truth for scores, ranks, progression, rewards, prices, ownership and unlocks. Nothing in this specification changes an endpoint, a request or response shape, a database value, or a business rule.

---

## Contents

1. Design principles, tokens, typography, layout, responsive behaviour, motion
2. Landing page and hero artwork
3. Experience cards, rank labels, interactions and API mapping
4. Student dashboard and mission board
5. Mana wallet, history, badge store and profile showcase
6. Administrator visual treatment
7. Accessibility and reduced motion
8. Components and source-asset plan
9. Routes, API dependencies and unchanged contracts
10. Testing, baseline protection, risks and implementation order

---

## 1. Design principles and system

### 1.1 Principles

| # | Principle | What it means in practice |
|---|---|---|
| P1 | **Cinematic shell, functional core** | Atmosphere (art, glow, particles) lives in the landing page, page headers and the character areas. Forms, quiz questions, tables and admin screens stay calm and highly readable. |
| P2 | **The System speaks in HUD** | Important state changes (saved, submitted, rank up, purchase, errors, confirmations) use one neon "System notification" treatment, based on reference 06. |
| P3 | **Never pretend** | Every number, rank, status, price and ownership flag on screen comes from the API. Decorative art is labelled decorative. Missing data is omitted or shown as an explicit empty state, never filled with plausible-looking values. |
| P4 | **Theme labels live in one place** | Rank titles, card characters, group names and the "Mana" wording are defined in one presentation module (`src/config/presentation.js`, §8.1). Pages never hard-code them. |
| P5 | **State never relies on colour alone** | Every state has an icon or shape, a text label and a colour. This applies to the existing rank shapes and to mission states. |
| P6 | **Restraint** | Glows are thin (1px lines plus a soft outer blur), particles are sparse, motion runs once. Nothing loops in a way that competes with reading. |

### 1.2 Colour tokens

The tokens replace the current yellow and blue Python palette in `styles.css`. The existing semantic aliases (`--text`, `--line`, `--danger` and so on) are kept, so components migrate gradually.

| Token | Value | Use |
|---|---|---|
| `--bg-void` | `#05060d` | Page base, behind everything |
| `--bg-night` | `#0a0b1a` | Main canvas |
| `--bg-navy` | `#0e1230` | Raised background bands (landing sections) |
| `--surface-1` | `rgba(16, 18, 42, .86)` | Panels and cards (glass) |
| `--surface-2` | `#151938` | Inputs, nested panels |
| `--surface-3` | `#1d2252` | Hover and selected surfaces |
| `--line` | `#272c5a` | Hairlines and dividers |
| `--line-strong` | `#5f66ad` | Input borders, frames. Raised from `#4a4f8f` in Phase 2 to meet WCAG 1.4.11: 3.64:1 on the input fill, 3.25:1 on `--surface-2`. |
| `--hud` | `#4cc9ff` | Electric blue/cyan: System HUD, Mana, focus-adjacent interactive accents |
| `--hud-strong` | `#8be3ff` | HUD text on dark, focus ring |
| `--hud-glow` | `rgba(76, 201, 255, .35)` | Outer glow (box-shadow only) |
| `--violet` | `#8b5cf6` | Primary brand accent, primary buttons, active navigation |
| `--violet-soft` | `#b69cff` | Violet text on dark |
| `--magenta` | `#d946ef` | Ambient light only (gradients), never text |
| `--gold` | `#e8b64c` | S-rank, cleared missions, premium badge frames (sparingly) |
| `--gold-soft` | `#f5d48a` | Gold text on dark |
| `--text` | `#eef1ff` | Body text |
| `--text-muted` | `#a3a9cf` | Secondary text (about 8:1 on `--bg-night`) |
| `--text-dim` | `#7c82ad` | Tertiary meta text only (about 5:1; never for essential content) |
| `--success` | `#4ade80` | Saved, passed, purchase confirmed |
| `--warning` | `#fbbf24` | Attempted but not passed, conflicts |
| `--danger` | `#ff6b81` | Errors, destructive actions |
| `--focus` | `#8be3ff` | 3px focus outline, plus a dark 2px halo for contrast on light art |
| `--link` | `#9ecbff` | Inline links |

**Rank colours.** These are always paired with the letter, the existing per-rank shape and the label.

| Rank | Token | Value | Notes |
|---|---|---|---|
| E | `--rank-E` | `#9aa3b5` | Steel |
| D | `--rank-D` | `#5fd4b0` | Jade |
| C | `--rank-C` | `#4cc9ff` | HUD cyan |
| B | `--rank-B` | `#8b5cf6` | Violet |
| A | `--rank-A` | `#ff9f43` | Amber |
| S | `--rank-S` | `#e8b64c` | Gold with a magenta ambient glow |

**Gradients.**
- `--grad-night`: radial violet at the top left (`rgba(139,92,246,.18)`), radial indigo at the bottom right (`rgba(67,56,202,.16)`), over `--bg-night`.
- `--grad-hud-frame`: a linear cyan-to-violet gradient used for 1px frame borders through `border-image` or a masked pseudo-element.

**Contrast rule.** Body text is at least 4.5:1. Large display text and UI boundaries are at least 3:1. Every text-over-art placement sits on a scrim of at least `rgba(5,6,13,.72)`. All pairs are checked in Phase 2 with a contrast calculation script, which is recorded in the report.

### 1.3 Typography

**Constraints.** The CSP is `default-src 'self'`, so Google Fonts cannot load. The project has no bundled font files, and downloading fonts is not permitted. The design therefore uses **local system fonts** that ship with Windows (the demo machine) and have sensible fallbacks.

| Role | Stack | Treatment |
|---|---|---|
| Display (hero, page titles, rank titles) | `"Bahnschrift", "Segoe UI Variable Display", "Arial Narrow", system-ui, sans-serif` | Uppercase, semi-condensed (`font-stretch: 87.5%` where supported), weight 600–700, tracking `.06–.12em` |
| Lore and fantasy accent (rank epithets, card names, section eyebrows) | `"Palatino Linotype", "Book Antiqua", Palatino, Georgia, serif` | Small caps or uppercase, used sparingly |
| UI and body | `"Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif` | 16px base, 1.6 line height |
| Code | `"Cascadia Code", Consolas, "Liberation Mono", ui-monospace, monospace` | Unchanged lesson and quiz code blocks |

**Optional upgrade (not required).** If you later supply an OFL-licensed `.woff2` (for example Cinzel or Rajdhani), it can go in `frontend/src/assets/fonts/`. It is then served as `static/*.woff2`, which Apache already allows. Without it, the system stack above is the final design, not a placeholder.

**Type scale** (fluid, `clamp()`):

| Step | Size | Use |
|---|---|---|
| Hero | `clamp(2.5rem, 6vw, 5rem)` | Landing headline only |
| Title 1 | `clamp(1.75rem, 3vw, 2.5rem)` | Page h1 |
| Title 2 | `1.375rem` | Section and panel headings |
| Title 3 | `1.125rem` | Card titles |
| Body | `1rem` | Body text |
| Small | `.875rem` | Meta |
| Eyebrow | `.75rem` | Uppercase, `.16em` tracking |

### 1.4 Layout and spacing

- **Spacing:** the existing 4px scale is kept (`--space-1` 4px through `--space-7` 48px). `--space-8` (64px) and `--space-9` (96px) are added for landing sections.
- **Radius:** HUD frames use a **2–4px radius with clipped corners** (`clip-path` bevels on decorative frames only). Content cards use 10px. Pills use 999px.
- **Maximum widths:** content up to 1200px; reading (lessons, quiz) up to 760px; forms up to 520px.
- **Application shell:** the existing structure is kept: a fixed desktop sidebar, a top bar, and a modal `<dialog>` drawer on small screens. It is restyled as a dark glass "System" frame with a violet active-route indicator and cyan focus states. The behaviour (focus management, drawer focus containment, logout guard) is unchanged.

### 1.5 Responsive breakpoints

| Name | Range | Behaviour |
|---|---|---|
| xs | under 480px | Single column. Hero art above the headline. Experience cards in one column at full width. Mission cards in one column. Tables scroll inside their region. |
| sm | 480–760px | Single column with two-column card grids where they fit (store, owned badges). Drawer navigation (existing 760px switch). |
| md | 761–1100px | Sidebar appears (existing). Experience cards 2×2. Dashboard stacks the character panel above the status panel. |
| lg | 1101–1439px | Full layouts. Experience cards in a row of four. Dashboard in two columns (Igris composition). |
| xl | 1440px and up | Same as lg with a wider gutter. The hero art grows up to its native height (see §2.3). |

**Mobile is designed, not shrunk.** Hero art is re-cropped with `object-position`, card flips become a disclosure on touch screens, and dashboard panels re-order so the next action comes first.

### 1.6 Motion

| Token | Value |
|---|---|
| `--dur-fast` | 120ms (hover, press) |
| `--dur` | 220ms (panels, notices) |
| `--dur-slow` | 480ms (card flip, hero entrance) |
| `--ease-out` | `cubic-bezier(.2, .7, .2, 1)` |

- **Entrance:** fade plus an 8px upward slide, once per mount, staggered by 40ms up to 6 items. There are no scroll-triggered effects beyond a single `IntersectionObserver` reveal on landing sections.
- **Ambient particles:** `AmbientBackground` is refactored into sparse drifting "mana motes" (cyan and violet dots, 1–2px, low opacity). There are at most 36 on desktop and 14 on mobile, capped at 30fps, and paused when the tab is hidden. It keeps the existing reduced-motion and visibility guards. The admin shell renders the static gradient only.
- **HUD notifications:** a 220ms scale-in from 0.98 with a one-time 1px border sweep. There is no infinite pulse. The in-progress mission marker uses a slow glow (3s), which is disabled under reduced motion.
- **Card flip:** a 480ms `rotateY(180deg)` with `backface-visibility: hidden` (§3.4).
- **Reduced motion:** see §7.2.

---

## 2. Landing page

### 2.1 Route behaviour

| Visitor | `/` shows |
|---|---|
| Session still loading | The existing `Loading` state ("Checking your session") |
| Logged out (`anonymous`) | **The new public landing page** |
| Logged-in student | A redirect to `homeFor(user)`, unchanged: `/start`, `/assessment` or `/dashboard` by onboarding state |
| Logged-in admin | A redirect to `/admin`, unchanged |

- "Begin Awakening" links to `/register`. "Enter Hunter Portal" links to `/login`.
- The `Public`, `Student` and `AdminOnly` guards are unchanged.
- The Apache SPA allowlist already allows `/`. **No `.htaccess` change is needed.**
- The landing page renders in its own full-bleed layout variant, not inside the minimal app layout.

### 2.2 Page structure

1. **Public header**, sticky and transparent until scroll: the brand mark ("HUNTER SYSTEM", Bahnschrift, tracked), in-page anchors (*Awakening · Missions · Ranks · Mana*), then "Log in" and "Create account" buttons. On xs the anchors collapse into a compact menu button using the existing dialog pattern.
2. **Hero** (§2.3).
3. **How awakening works.** Four HUD steps: create an account, choose your experience card, take the 10-question placement check, clear missions. The copy states that the placement check, not the card, sets the starting rank.
4. **Mission roadmap preview.** The 18 confirmed curriculum topics (C-25) in six gates (G1–G6, names from `database/seed.sql`), shown as small sealed mission tiles. There are no statuses, because a visitor has no progress. Text: "5-question missions · pass with 3 correct to unlock the next".
5. **Hunter ranks.** A rank ladder E→S with the new labels and theme wording (§3.1), the topic thresholds from policy A-04 (E 0–2, D 3–5, C 6–8, B 9–11, A 12–14, S 15–18 topics passed), and "your rank never decreases".
6. **Mana and artifacts.** "Earn 10 Mana the first time you complete each mission (pass or fail). Spend it on cosmetic badges for your profile. Badges never change your rank." The visual is an original SVG badge-frame illustration. **It shows no real badge names or prices**, because visitors cannot see the catalogue and dev currently has no badges on sale.
7. **Final call to action.** "Your awakening is waiting." Begin Awakening and Enter Hunter Portal.
8. **Footer.** "Hunter System, a college project for learning Python. Fan-inspired artwork belongs to its respective owners; this project is not affiliated with Solo Leveling's creators or publishers." Plus Log in and Create account links.

All landing copy describes approved rules (A-01 to A-04) and confirmed curriculum. It uses no invented statistics, testimonials or user counts.

### 2.3 Hero composition and artwork selection

**Composition** (lg and xl), as a 12-column grid:
- **Text, columns 1–6, vertically centred:**
  - An eyebrow reading "SYSTEM · AWAKENING AVAILABLE" in a small HUD tag.
  - The headline **AWAKEN. LEARN. LEVEL UP.**, with "LEVEL UP." in a violet-to-cyan gradient.
  - A lead sentence: "Hunter System teaches Python through 18 missions. Take a placement check, clear missions, rise from E-Rank to S-Rank."
  - Two buttons: Begin Awakening (primary, violet) and Enter Hunter Portal (secondary, HUD outline).
  - A compact decorative HUD strip with static labels only: "PYTHON · 18 MISSIONS · 6 RANKS".
- **Art, columns 6–12:** the character inside a thin HUD frame with corner brackets. Edges are feathered into the background with mask gradients. Violet and indigo radial light sits behind the figure, cyan particles drift, and a faint ground haze runs along the bottom.

**md:** text on the left, a narrower art column on the right with the art scaled to 80vh at most.

**xs and sm:** the art comes first as a 56vh band with `object-position: 50% 15%` (face and sword visible), fading into a text block below. The text never overlays the face.

**Asset decision.** Option (a) was tested on 2026-10-18 in the scratchpad only.

| Candidate | Result |
|---|---|
| **(a) ARISE poster** (`reference/1057008975069959755.jpg`, 735×1319, stored rotated 90°): crop x 0–735, y 0–560 of the stored file, then rotate 90° counter-clockwise | **Clean.** Gives 560×735: Jinwoo standing, holding a katana, Igris silhouette behind, red and violet energy. **No poster lettering is included.** The "A" of "ARISE" starts at about x 585 of the rotated image, so a 640px crop already picks it up. 560 is the maximum clean width. No watermark is visible. |
| **(b) #04 young Jinwoo** (736×1307) | Clean and higher resolution, but no weapon and a melancholic, pre-awakening mood. It doesn't fit a cinematic "awaken" hero. |
| (c) unnumbered "Solo Leveling Seung Jin Woo.jpg" | Daylight street scene with bystanders; tonally wrong. Rejected. |

**Selected:** (a) is the hero image (`hero-awakened-hunter.webp`). (b) is reused as decorative rank art for lower tiers (§4.2), which gives the story of growing from young Jinwoo to the awakened Jinwoo.

**Known quality limits of (a):**
- 560×735 is low resolution. It is sharp up to about 560 CSS px wide at 1×, and slightly soft on 2× displays. The hero art column is therefore capped at `max-width: 560px` (art `height` up to 735px), not stretched to fill wide screens. Atmosphere fills the remaining space.
- The palette leans red and crimson. It is kept as a warm energy accent, balanced by violet and indigo glows and a cyan rim light from CSS overlays (`mix-blend-mode: color` and `soft-light` gradients). **Pixels are not recoloured destructively**, so skin and eyes stay true. If the result reads as too red during Phase 3 review, an alternative is a baked hue-shifted variant, which needs your approval.
- The figure's right side (Igris and the sword tip) is cut by the crop edge. A right-edge mask gradient hides the hard edge.

---

## 3. Experience cards and rank labels

### 3.1 Rank display mapping (frontend only)

| Backend code | Primary display name | Theme wording (lore text only) | Visible format |
|---|---|---|---|
| E | Peasant | Novice Hunter | **E-Rank · Peasant** |
| D | Apprentice | — | **D-Rank · Apprentice** |
| C | Squire | — | **C-Rank · Squire** |
| B | Knight | — | **B-Rank · Knight** |
| A | Champion | Heroic / Legendary | **A-Rank · Champion** |
| S | Mythic | Immortal / Deity | **S-Rank · Mythic** |

- The display string is built only from the **rank code**: `${code}-Rank · ${label}`. The API's `rank.name` ("E-Rank") and `rank.title` ("Rookie") are **ignored for display**, so "Rookie" is never shown. API values are not altered.
- Rank codes are read from:
  - `{code}` objects (student APIs);
  - bare letters (`rank_history.to/from`, `promotion.from/to`, admin student detail `hunter_rank: "D"`);
  - `"D-Rank"` strings (admin student list). These are parsed with `/^([EDCBAS])(?:-Rank)?$/`. An unknown value renders the raw value, so nothing is silently re-labelled.
- `null` renders "Not ranked yet" (existing behaviour).
- Theme wording such as Novice Hunter, Heroic, Legendary, Immortal and Deity appears only as secondary lore text: the landing rank ladder, the dashboard rank plaque subtitle and the rank-up notification flourish. It is never a rank value, filter or code.
- The existing distinct shape per rank in `RankBadge` stays, so ranks never rely on colour alone.

### 3.2 Experience card mapping

The cards are matched to API options **by `code`**. The `id` sent to the server is always taken from the matched `GET /experience-levels` item.

| Order | Card (theme) | Matches API `code` | API label (seed, displayed as-is) | Portrait source | Card copy (back) |
|---|---|---|---|---|---|
| 1 | **E-Rank · Sung Jin-Woo** | `complete_beginner` | "Complete beginner" | `04_rank_E_sung_jinwoo_portrait.jpg` | "I'm new to Python. Start with the fundamentals." |
| 2 | **C-Rank · Song Chi-Yul** | `basic` | "Knows a little basic Python" | `03_rank_C_song_chi_yul.jpg` | "I know a few Python basics but need guided practice." |
| 3 | **A-Rank · Woo Jin-Chul** | `intermediate` | "Intermediate" | `02_rank_A_woo_jin_chul.jpg` | "I understand core concepts and can solve small problems." |
| 4 | **S-Rank · Cha Hae-In** | `master` | "Master" | `01_rank_S_cha_hae_in.jpg` | "I'm confident with the basics and want to test my skills." |

The master prompt names the second code `knows_little_basic_python`. **That code doesn't exist.** The seed and API use `basic` (id 2), and this spec maps `basic`. It follows the API.

**Matching rules:**
- Cards render in the API's order. With the current seed this matches the order above.
- An API option with no matching card config renders as a **plain fallback card** with its API label, no portrait and no theme rank. Nothing is hidden.
- A card config with no matching API option is not rendered.
- No experience ids are hard-coded. The submitted body is exactly `{ "experience_level_id": <id from API> }`.
- The card's theme rank never feeds any rank display, request or derived value.

**Required disclosure.** It sits above the cards and is repeated in each card's accessible description:
> "Choose the hunter whose experience is closest to yours. **This is your own description, not your Hunter rank.** Your real starting rank comes from the 10-question placement check that follows."

The card's rank letter is styled as a "theme" tag (an outlined chip labelled *Theme*), not as the solid `RankBadge` used for real ranks.

### 3.3 Card anatomy

- **Front:** the portrait inside a gold- or rank-tinted frame (3:4 ratio) with a dark vignette, so the C, A and S portraits with light backgrounds match the dark theme. The front also shows the character name in display type, a "Theme · E-Rank" chip, and the API label in small text.
- **Back:** the same frame on a dark HUD panel showing the API label (as the heading), the descriptive sentence, the disclosure line ("Theme only. Not your Hunter rank.") and a "Select" hint, or "Selected ✓" when selected.
- **Selected state, independent of flip:** a 2px violet border plus outer glow, a "✓ Selected" ribbon on both faces, and `aria-checked` (or `checked`) true. Flipping never changes selection, and selecting never forces a flip.

### 3.4 Interaction model

Semantics: the four cards are a **radio group** (`<fieldset>` with a legend and visually hidden native `<input type="radio" name="experience">`), so screen readers and forms get native behaviour.

| Input | Behaviour |
|---|---|
| Mouse hover (`@media (hover: hover) and (pointer: fine)`) | The card flips to the back. It flips back on leave. |
| Keyboard focus (`:focus-within`) | The card flips to the back, so keyboard users see the details. A visible focus ring sits on the card frame. |
| Click or tap on the card | Selects it (the radio is checked). |
| **Space** | Selects (native radio behaviour). |
| **Enter** | Selects. A `keydown` handler checks the focused radio and calls `preventDefault()`, so Enter never submits the form by accident. |
| Arrow keys | Move between cards and select (native radio-group behaviour). |
| Touch screens (`hover: none`) | **No hover flip.** Each card has a visible "Details" button (an `ⓘ` icon with text, at least 44×44px, `aria-expanded`) that toggles the back face. Tapping elsewhere on the card selects it. The Details button stops propagation, so revealing never selects and never deselects. |
| Submit | "Confirm and begin placement check" stays disabled until a card is selected. Below it, the existing note reads "You can't change this later." |

**Errors** (unchanged logic): `experience_already_chosen` calls `refresh()` and the guard moves on. Other errors show a HUD error notice. Loading and failure states use the shared `Loading` and `LoadError` components.

**Accessible names:**
- Each radio's label is: "Sung Jin-Woo, theme E-Rank: Complete beginner".
- `aria-describedby` points to the back-face sentence and the disclosure, which are always in the DOM. Screen readers get the details whether or not the card is flipped.

### 3.5 Placement assessment and result

- **`QuestionRunner`** is restyled as a HUD trial screen:
  - a question map of hexagonal position chips showing the existing saved, saving and error states;
  - a HUD-framed question panel;
  - choice tiles with A–D labels;
  - save status as a small System line ("✓ Saved").
- The queue, retry and submit logic is **unchanged**.
- **Result page:** a "SYSTEM NOTIFICATION · Placement complete" HUD panel shows the server's `placement_rank` in the mapped format, `correct_count` of `question_count`, and the server's `label` text ("Starting point from a short placement check, not proof of mastery").
  - The chosen experience card is shown beside it as "You described yourself as: Master (theme card: Cha Hae-In)". This makes the separation explicit.
  - Skill groups, topic evidence and the answer review are restyled but have the same content.
  - The review appears only from the result response, as today.

---

## 4. Student dashboard and mission board

### 4.1 Dashboard data (single request, `GET /me/dashboard`)

| Shown | Field | Notes |
|---|---|---|
| Name | `display_name` | |
| Hunter rank | `hunter_rank.code` | Mapped label; large rank plaque |
| Placement rank | `placement_rank.code` | "Starting point", small |
| Topics passed | `topics_passed`, `topics_total` | Bar with `role="progressbar"` |
| Next rank | `next_rank.code`, `next_rank.topics_required`, `next_rank.topics_remaining` | "3 more missions to B-Rank · Knight (9 required)". The bar is `topics_passed / topics_required`, both from the API. `null` gives "Highest rank reached" (S). |
| Mana | `coins` | Shown as "Mana", with no conversion |
| Continue | `current_topic` (`id`, `title`, `content_ready`) | "Continue mission" goes to `/topics/:id`. When content isn't ready, show "Unlocked, content coming soon". `null` shows "You've cleared every unlocked mission." |
| Recommended start | `recommended_topic` (`ready`) | |
| Available now | `offered_topic` | Only when present |
| Experience | `experience_level.label` | "Self-described" |

**Not shown, because the API doesn't provide them:**
- STR, AGI, HP or any invented RPG statistic;
- avatars;
- streaks;
- percentages beyond simple ratios of the fields above;
- a "recent activity" feed.

The last could be built later from `/me/coins` and `/me/rank-history`. That's listed as an optional enhancement (§10.5), not part of the plan.

### 4.2 Dashboard layout (Igris composition plus HUD)

**lg (two columns):**
- **Left, "Character panel" (5/12):** decorative rank art in a tall framed panel with a rank-coloured aura and corner brackets. The rank plaque (`E-Rank · Peasant`, with the lore subtitle "Novice Hunter") overlaps the bottom edge. A caption is always present: "*Decorative rank artwork. Not an avatar.*"
- **Right, "STATUS" window (7/12):** a HUD panel styled like a game status sheet, organised like the Igris reference (heading, then label/value rows with dividers):
  - **Name**
  - **Hunter rank**
  - **Placement rank**
  - **Missions cleared** X / 18 with a bar
  - **Next rank** with a bar
  - **Mana** with a crystal icon
  - **Experience** (self-described)
- **Below, full width:** a "Current mission" HUD card with the primary call to action, then two small cards: "Recommended start" and "Available now". Quick links: Missions · Rank & Mana · Badge store.

On md and smaller, the order is: the Current mission card first (the next action matters most on mobile), then STATUS, then the character panel (shorter, 40vh).

**Decorative rank art tiers.** No avatar data exists, so the art reflects the rank tier:

| Hunter rank | Art | Treatment |
|---|---|---|
| E, D | young Jinwoo (`04` portrait derivative) | Dim steel or jade aura; "before awakening" mood |
| C, B | awakened hunter (hero derivative) | Cyan or violet aura |
| A, S | awakened hunter (hero derivative) | Amber or gold aura with stronger rim light and ambient particles |
| none (null) | rank emblem SVG only | No character art |

The art is never selectable, never saved and never called "your avatar".

### 4.3 Mission board (`/topics`)

URLs stay the same. "Missions" is presentation language only.

- **Data:** `GET /topics` (18 items, API order). Nothing is unlocked or reordered on the client.
- **Grouping:** by `skill_group` into six **Gates**. The presentation mapping mirrors `database/seed.sql` `skill_groups`:

  | Code | Display |
  |---|---|
  | G1 | Gate I · Python basics |
  | G2 | Gate II · Input and operators |
  | G3 | Gate III · Control flow |
  | G4 | Gate IV · Text and collections |
  | G5 | Gate V · Functions, modules, errors |
  | G6 | Gate VI · Files and objects |

  The module carries a comment naming the seed as its source, and the names are labelled as frontend presentation, not API data. An unknown group code falls back to the raw code.
- **Gate header:** the gate name plus "X of Y cleared". The count is `status === 'passed'` within the group, computed from API items.
- **No topic descriptions.** The student API has none and no approved description source exists. Cards show the position, the title and the facts below. (The admin `summary` field could become a source later only through an API change, which is out of scope.)

**Mission card states.** `topicStatus()` stays as the single mapping. Every state has an icon, text and a frame style.

| State (API-derived) | Frame and visual | Label | Action |
|---|---|---|---|
| `locked` | Dashed dim frame, desaturated, lock sigil | "Sealed · Locked" | None (not a link). Hint: "Clear the previous mission to unlock." |
| `no_content` (unlocked, `content_ready=false`, status available) | Muted violet frame, hourglass icon | "Unlocked · Content coming soon" | None |
| `available` | Cyan HUD frame, open-gate icon | "Available" | "Start mission" goes to `/topics/:id` |
| `in_progress` (`open_attempt_id`) | Cyan frame with animated corner brackets (static under reduced motion), "◐" | "Quiz in progress" | "Resume quiz" goes to `/quiz/:open_attempt_id` |
| `completed` (not passed) | Amber frame, "!" | "Attempted · Not cleared" | "Retry mission" goes to `/topics/:id` |
| `passed` | Gold frame and check seal | "Cleared" | "Review" goes to `/topics/:id` |

- **Meta on each card:** "Best 4/5" (`best_correct`) and "Lesson read" (`lesson_visited`), only when present.
- **Active mission emphasis:** the first item in API order that is unlocked, not passed and content-ready gets an "ACTIVE" HUD tag and a larger card. This is the same rule the dashboard's `current_topic` uses. Any `in_progress` item is also emphasised.
- **Empty and limited data** (the current dev state has 2 ready topics): most unlocked items show "content coming soon". Locked gates stay visible, so the roadmap still reads as a full board.

### 4.4 Topic detail, lesson and quiz

- **Topic detail:** a mission briefing panel showing the status label, "Read the briefing (lesson)" and "Enter the trial (quiz)" (the existing two choices), plus resume when an attempt is open. The attempt history table is restyled with the same columns. The reward hint becomes "Your first completed quiz in a mission earns 10 Mana, even if you don't pass."
- **Lesson:** a calm reading layout with the existing safe Markdown renderer and the same code blocks. Decoration is limited to a slim header.
- **Quiz in progress:** the same `QuestionRunner` HUD styling as the placement check.
- **Quiz result:** a System notification:
  - **"MISSION CLEARED"** when `passed`, gold accent;
  - **"MISSION NOT CLEARED: 3 correct needed"** otherwise, amber.

  It shows the grade letter, `percent` and `correct_count`. Reward reads "+10 Mana" (from `reward.coins`) and "Balance after: N Mana" (`reward.balance_after`), or the existing "already paid" text in Mana wording. `unlocked_topic` reads "Unlocked: <title>". `promotion` shows a **Rank up** notification ("D-Rank · Apprentice → C-Rank · Squire"). Every value comes from the response.

---

## 5. Mana, history, store and profile

### 5.1 Mana terminology

- **Visible wording:** "Mana" replaces "coins" in all **student and admin** UI text. For example: "Mana balance", "+10 Mana", "Mana earned", "Mana spent", "Price in Mana", "Approve current price: 10 Mana", "Spend 10 Mana?", "Not enough Mana: you have 5, it costs 10."
- **Not changed:** API fields (`coins`, `balance`, `price`, `price_paid`, `balance_after`, `reward.coins`, `coins_awarded`), error codes (`insufficient_coins`), ledger `type` values (`topic_reward`, `badge_purchase`), endpoints (`/me/coins`), database names, amounts and rules.
- **Shared component:** a `ManaAmount` component renders the number plus a small crystal icon, with the visible text "N Mana". The accessible name is the same text.

### 5.2 Rank and Mana history (`/history`, navigation label "Rank & Mana")

- **Rank timeline:** vertical HUD timeline nodes, oldest first (API order).
  - Placement entries read "Starting rank from the placement check: D-Rank · Apprentice".
  - Promotion entries read "Promoted D → C after clearing 6 different missions".
  - Each node shows the date.
- **Mana ledger:** the balance header ("N Mana") from `balance`. Each entry shows:
  - a credit as "+10 · Mana earned", or a debit as "−15 · Mana spent";
  - a description: `topic_reward` is "Mission reward, first completion of mission #<topic_id>", and `badge_purchase` is "Badge purchase #<badge_id>";
  - "balance N Mana" and the date.

  "Show older entries" keeps the existing paging.
- **Optional, existing endpoints only:** name the mission or badge by joining with `GET /topics` titles and `GET /me/profile` `owned_badges`. If either request fails, fall back to the numbers.
- **Copy:** "Your rank never goes down. Mana never changes your rank."

### 5.3 Badge store (`/store`)

**Data:** `GET /badges` plus `GET /me/profile` (`coins` for the balance), as today. The store lists **only what the API returns** (on sale with an approved price). No other badge is assumed to exist.

**Layout:** a header with the title "Badge Store", the eyebrow "Artifact vault" and a large HUD Mana balance chip. Below it is a grid of **collectible cards** inspired by references 08 and 09: 4 columns on lg, 3 on md, 2 on sm, 1 on xs.

**Card anatomy:**
- a dark card with a thin **gold filigree frame**, drawn with layered CSS borders and corner ornaments in SVG (no raster frame);
- the badge's own art (`image_url`, a 512px hexagonal PNG) centred in a radial violet and cyan glow, or the existing neutral placeholder when `image_url` is null;
- the name (display type), the description, and the price as "N Mana";
- a state footer.

**Card states (API flags only):**

| State | Source | Display |
|---|---|---|
| Owned | `owned: true` | "✓ Owned" seal with a gold ribbon, the frame dimmed, and a link "Showcase on your profile" |
| Can buy | `affordable: true` | Primary button "Buy" |
| Insufficient Mana | `affordable: false`, not owned | Disabled button plus the text "Need N more Mana" (`price − balance`, both from the API) |

**Purchase flow (HUD confirmation, reference 06):**
1. **Buy** opens a native modal `<dialog>` styled as a System notification: "SYSTEM · Confirm purchase. Spend **10 Mana** on **Shadow Recruit**? Balance now: 40 Mana." It has two buttons: "Spend 10 Mana" (primary) and "Cancel". Focus starts on Cancel and returns to the Buy button on close. Esc cancels.
2. The confirm button calls `POST /badges/:id/purchase` once through the existing single-flight `useAction` (no retries). While the request runs, it reads "Spending…".
3. **The result shows only server data:**
   - **Success:** a HUD notice "Badge acquired: <name> for <price_paid> Mana. Balance: <balance_after> Mana." plus a link to the profile showcase.
   - **`already_owned`:** "You already own this badge (bought for N Mana). No Mana was taken." (from `details.price_paid`).
   - **`insufficient_coins`:** "Not enough Mana: you have <details.balance>, it costs <details.price>."
   - **`not_on_sale`:** "This badge is no longer for sale. The store has been refreshed."
   - **`network_error`:** "We couldn't confirm the purchase. Refresh the store and check your badges before trying again."
4. The store reloads after every attempt, exactly as now.

**Empty store:** this is the current dev state. A sealed-vault illustration (SVG) with the text: "No badges are for sale yet. Earn Mana by completing missions and spend it when the vault opens." It's an explicit empty state, never fake cards.

### 5.4 Profile and showcase (`/profile`)

- **Hunter card:** decorative rank art (same tiers as §4.2) with the caption, `display_name`, Hunter rank and placement rank (mapped), missions cleared out of 18, Mana, and experience (self-described).
- **Showcase:** three framed **slots** in order (`showcase_limit` from the API; the slot count follows the API value). Filled slots show the badge art and name, with a "Retired" or "Off sale" tag from `status`. Empty slots read "Empty slot" with a dashed frame.
- **Collection:** a grid of owned badges, each with a checkbox "Show on profile", art, name, "Paid N Mana · date" and status tags. The existing ordering controls (↑ ↓ with accessible names), "Save showcase" and "Undo changes" behave the same. Errors keep the previous showcase (existing logic).
- **Off-sale and retired owned badges** appear only here, never in the store, matching the API.

---

## 6. Administrator visual treatment

**Principle:** the admin area is a clean "command console". It shares the tokens, typography and focus styles, with **no particles, no character art and no flip or entrance animation** beyond fades. Density and clarity come first.

| Screen | Route | Treatment (all behaviour preserved) |
|---|---|---|
| Overview | `/admin` | Six stat tiles in HUD frames (ready topics, published lessons, questions, badges on sale, provisional prices, students), values from the same four requests. Link to audit. |
| Students | `/admin/students` | Filter bar panel and a dense table with sticky header and row hover. Rank shown as "D-Rank · Apprentice" (parsed from `"D-Rank"`). "Recently active" wording and the explanation kept exactly. |
| Student detail | `/admin/students/:id` | Profile summary panel, then the topic progress, rank history, login history and sessions tables. Placement shown as a readable definition list instead of raw JSON; the same fields with nothing dropped, and raw values stay available in an expandable "Raw record" block. |
| Login events | `/admin/login-events` | Filter bar plus table. Outcome shown as a text chip with an icon. |
| Topics | `/admin/topics` and `/:id` | Readiness table with explicit text chips ("Ready for students", "Needs published lesson", and so on). Editor panel unchanged. |
| Lesson editor | `/admin/topics/:id/lesson` | Monospace Markdown textarea; same fields, actions and confirmations. |
| Questions | `/admin/questions`, `/:id`, `new` | Same filters, editor, choice controls and correct-choice radio; clearer grouping into fieldsets with legends. |
| Badges | `/admin/badges`, `/:id`, `new` | Table shows "N Mana", approval and status chips, owners. Editor labels: "Price in Mana", "Approve current price: N Mana", "Publish X for sale at N Mana?". Badge art preview when `image_url` exists. |
| Audit | `/admin/audit` | Entry cards with Before/After panes side by side (stacked on mobile), monospace values. |

- **Kept exactly:**
  - optimistic concurrency fields;
  - conflict comparison and the "Your unsaved input is preserved" flow;
  - dirty-navigation blocking (`useBlocker`) and the `beforeunload` and logout guards;
  - every `window.confirm` confirmation (kept as native dialogs, so the admin tests' dialog handling stays valid);
  - the `AdminBoundary` remount on identity change;
  - all filters and pagination.
- **Status chips for publish, retire and approval** gain icons and text. Destructive buttons keep `btn-danger`.

---

## 7. Accessibility and reduced motion

### 7.1 Requirements (all pages)

- **Semantics and focus:**
  - Landmarks are kept: skip link, `header`, `nav[aria-label="Main"]`, `main#main`, `footer`.
  - Exactly one `h1` per page, focused on route change (existing behaviour kept).
  - The landing page uses sections with `aria-labelledby`.
- **Focus:** a visible 3px `--focus` outline plus a 2px dark halo, on everything, including flipped cards and dialog buttons. There's no `outline: none` without a replacement.
- **Targets:** at least 44×44px for interactive controls, including the card "Details" button, mission actions and the showcase arrows.
- **Contrast:** AA, as in §1.2. Text over art always sits on a scrim.
- **State is never colour-only** (P5).
- **Images:**
  - The hero, decorative rank art and card portraits use `alt=""`, because adjacent text names them.
  - The decorative-art caption is visible text.
  - Badge art uses `alt=""` (the name is the heading), as today.
- **Live regions** are kept:
  - answer-save announcements (polite);
  - errors use `role="alert"`, other notices use `role="status"`;
  - purchase results are announced.
- **Dialogs:** the native `<dialog>` with `showModal()` provides inertness. It has a labelled heading, initial focus on Cancel for purchases, Esc to close and focus return.
- **Experience cards** are covered in §3.4: native radios, full description available regardless of flip, and Enter and Space both select.
- **Text and layout:**
  - Text resizes to 200% without loss.
  - No horizontal page scroll from 320px.
  - Tables scroll inside labelled focusable regions (existing `TableScroll`).
- **Forms:** labels, hints and errors are kept with `aria-describedby` and `aria-invalid` (existing behaviour).

### 7.2 `prefers-reduced-motion: reduce`

| Feature | Reduced-motion behaviour |
|---|---|
| Ambient particles | Not started; static gradient only (existing guard reused) |
| Entrance animations | None; content appears immediately |
| Experience card flip | No 3D rotation. A 0ms or short opacity cross-fade between faces (≤120ms). Hover and focus reveal still work. |
| In-progress mission glow, HUD border sweep | Static |
| Landing reveal on scroll | Disabled; sections are visible from the start |
| Smooth scrolling to anchors | `scroll-behavior: auto` |

---

## 8. Components and source assets

### 8.1 Presentation module: `frontend/src/config/presentation.js` (new)

The single editable source for theme wording:

```js
export const RANKS = {
  E: { label: 'Peasant',    lore: 'Novice Hunter' },
  D: { label: 'Apprentice', lore: null },
  C: { label: 'Squire',     lore: null },
  B: { label: 'Knight',     lore: null },
  A: { label: 'Champion',   lore: 'Heroic · Legendary' },
  S: { label: 'Mythic',     lore: 'Immortal · Deity' },
};
export function rankCode(value) {}       // {code} | 'D' | 'D-Rank' -> 'D' | null
export function rankDisplay(value) {}    // 'D-Rank · Apprentice' (never uses API name/title)
export const EXPERIENCE_CARDS = {        // keyed by API experience code
  complete_beginner: { character: 'Sung Jin-Woo', themeRank: 'E', art: …, blurb: … },
  basic:             { character: 'Song Chi-Yul', themeRank: 'C', art: …, blurb: … },
  intermediate:      { character: 'Woo Jin-Chul', themeRank: 'A', art: …, blurb: … },
  master:            { character: 'Cha Hae-In',   themeRank: 'S', art: …, blurb: … },
};
export const SKILL_GROUPS = { G1: 'Python basics', … };   // mirrors database/seed.sql skill_groups
export const RANK_ART = { E: 'young', D: 'young', C: 'awakened', … };
export const CURRENCY = { name: 'Mana' };
```

### 8.2 Component inventory

| Component | New or changed | Purpose |
|---|---|---|
| `tokens.css`, `base.css`, `components.css`, `pages/*.css` | Split from `styles.css` | Tokens and primitives once; page-specific styles separate. Vite still bundles one hashed CSS file. |
| `HudPanel` | New | Framed panel (variants: `status`, `notice`, `quiet`), optional eyebrow and corner brackets |
| `SystemNotice` | Restyle of `Notice` | Same props and roles; HUD treatment (reference 06) |
| `ConfirmDialog` | New | Native `<dialog>` HUD confirmation (store purchase; available to students only) |
| `RankBadge` | Changed | Uses `rankDisplay()`; keeps shapes; adds `variant="theme"` outlined chip for experience cards |
| `RankEmblem` | New | Original inline SVG sigil per rank (null-rank art fallback, landing ladder) |
| `ManaAmount` | New | "N Mana" with crystal icon |
| `ProgressMeter` | New (from dashboard bar) | `role="progressbar"` with min, max, now and a visible text ratio |
| `StatusWindow` / `StatRow` | New | Igris-style label and value list |
| `CharacterArt` | New | Decorative framed art with mandatory caption and aura by rank |
| `ExperienceCard`, `ExperienceCardGroup` | New | §3 |
| `MissionGate`, `MissionCard` | New | §4.3; uses the existing `topicStatus()` |
| `BadgeCard` | New | §5.3 collectible frame; uses the existing `BadgeArt` |
| `ShowcaseSlots` | New | §5.4 |
| `ParticleField` | Refactor of `AmbientBackground` | Mana motes; same guards; `variant="off"` for admin |
| `PublicLayout`, `LandingHeader`, `Hero`, `LandingSection`, `SiteFooter` | New | §2 |
| `Layout` (app shell) | Restyle | Same structure and behaviour; nav labels: Dashboard · Missions · Rank & Mana · Badge store · Profile |
| `Icon` | Extended | New original line icons: mana crystal, gate, seal, hourglass, sword, shield, info |
| `QuestionRunner`, `Review` | Restyle only | No logic changes |

### 8.3 Source-asset plan

**Location:** `frontend/src/assets/art/`, imported from JSX or CSS so Vite emits hashed `dist/static/<name>-<hash>.webp`. The existing Apache rules already allow `static/*.webp` at both layers. **`frontend/public/` is not used for art**, because only `index.html`, `favicon.svg` and `static/` are reachable.

| Output file | Source (`reference/…`) | Operation | Size target | Used in |
|---|---|---|---|---|
| `hero-awakened-hunter.webp` | `1057008975069959755.jpg` | Crop x0 y0 w735 h560 → rotate 90° CCW → 560×735 | ≤ 90 KB | Landing hero; dashboard and profile art for C–S |
| `hero-awakened-hunter-sm.webp` | same | Above, scaled to 400×525 | ≤ 45 KB | `srcset` for xs/sm |
| `exp-e-sung-jinwoo.webp` | `reference/04_rank_E_sung_jinwoo_portrait.jpg` | 3:4 crop centred on the face and upper body, 480×640 | ≤ 60 KB | Experience card E; dashboard and profile art for E–D |
| `exp-c-song-chi-yul.webp` | `reference/03_rank_C_song_chi_yul.jpg` | 3:4 crop, 480×640 | ≤ 60 KB | Experience card C |
| `exp-a-woo-jin-chul.webp` | `reference/02_rank_A_woo_jin_chul.jpg` | 3:4 crop (face and suit, top ~75%), 480×640 | ≤ 60 KB | Experience card A |
| `exp-s-cha-hae-in.webp` | `reference/01_rank_S_cha_hae_in.jpg` | 3:4 crop (face to sword hilt), 480×640 | ≤ 60 KB | Experience card S |

- **Conversion with no new dependencies:**
  1. Crop and rotate with Windows' built-in `System.Drawing` (PowerShell) into lossless PNG intermediates in the scratchpad.
  2. Encode WebP (quality ~0.86) with the already-installed Chrome through the existing `playwright-core` dev dependency (`canvas.toBlob('image/webp')`).
  3. Visually inspect each file before committing.
- **Provenance:** `frontend/src/assets/art/README.md` records each output's source file, the source SHA-256, the crop rectangle and rotation, dimensions, where it is used, and the rights note ("Fan reference art for a local college demo; replace with original or licensed art before any public deployment").
- **Not shipped:** the video, reference 05, 06, 07, 08, 09 and 11, the unnumbered daylight Jinwoo, and the full ARISE poster. HUD frames, gold card frames and emblems are **original CSS and SVG** built to resemble the references, not cut from them.
- **Colour grading** happens in CSS overlays, not in the pixels (§2.3).
- **Existing badge PNGs** (`/hunter-system/assets/badges/…`) are used unchanged through the API's `image_url`.

### 8.4 Reference folder: protection and path-correction plan

**Protection (Phase 2):**
1. Add `reference/.htaccess` containing `Require all denied`. This is a second layer: the root rewrite already returns 404.
2. Verify with HTTP requests that `/hunter-system/reference/…` is refused for the prompt, the README, a JPG, the MP4 and a nested path.
3. Add these probes to `tests/apache-probes.mjs`.

No runtime import from `reference/` is allowed. All art is copied into `src/assets/art/` (§8.3).

**Current state (verified by SHA-256, 2026-10-18):**
- `reference/` holds the prompt, the setup notes and 12 originally named media files.
- `reference/reference/` holds the 11 numbered assets and the README.
- 10 of the 12 originally named files are **byte-identical duplicates** of numbered files.
- Two are unique:
  - `1057008975069959755.jpg` (ARISE poster, the hero source);
  - `Solo Leveling Seung Jin Woo.jpg` (young Jinwoo in daylight, unused).

| Originally named file | Identical to |
|---|---|
| `Cha hae-in.jpg` | `01_rank_S_cha_hae_in.jpg` |
| `CHIEF WOO JIN-CHUL.jpg` | `02_rank_A_woo_jin_chul.jpg` |
| `Song Chi-Yul _ Solo Leveling.jpg` | `03_rank_C_song_chi_yul.jpg` |
| `download (1).jpg` | `04_rank_E_sung_jinwoo_portrait.jpg` |
| `𝐒𝐮𝐧𝐠 𝐉𝐢𝐧-𝐰𝐨𝐨 _ 𝐒𝐨𝐥𝐨 𝐋𝐞𝐯𝐞𝐥𝐢𝐧𝐠 _ Wallpaper.jpg` | `05_landing_page_solo_leveling_ui_inspiration.jpg` |
| `Solo Leveling _ Ore dake Level Up na Ken.jpg` | `06_neon_notification_hud.jpg` |
| `Igris – Shadow Knight Stats _ Solo Leveling Character Evolution.jpg` | `07_igris_stats_dashboard_reference.jpg` |
| `download.jpg` | `08_shadow_roster_badge_grid.jpg` |
| `Solo Leveling All Main Characters Tier List _ … (1).jpg` | `09_character_card_collection_reference.jpg` |
| `From Klickpin.com- … .mp4` | `10_gaming_landing_page_style_reference.mp4` |

**Non-destructive correction (copy only, Phase 2):**
1. Copy `reference/reference/README.md` and the 11 numbered files **up** into `reference/`. The nested folder is left untouched.
2. Copy the two unique files to numbered names in `reference/`: `12_hero_arise_jinwoo_weapon.jpg` and `13_young_jinwoo_daylight_unused.jpg`. The originals stay.
3. Update the top-level `reference/README.md` (the copy) with entries 12 and 13, the duplicate table above, and a note that `reference/reference/` and the originally named files are retained pending your decision.
4. Verify all copies by SHA-256 and commit. Git stores identical contents once, so the copies add almost nothing to the repository.
5. **Removing** the nested folder and the duplicate originals is a separate step that needs your approval. Even then it stays recoverable from Git and the external backups.

---

## 9. Routes, API dependencies and unchanged contracts

### 9.1 Routes

| Route | Change |
|---|---|
| `/` (index) | **Changed:** anonymous visitors get `LandingPage`. Loading and authenticated behaviour unchanged (§2.1). |
| All other routes | **Unchanged** paths, guards and onboarding-state rules (`App.jsx`). The Apache SPA allowlist is unchanged. |

### 9.2 API dependencies by screen (unchanged)

| Screen | Endpoints |
|---|---|
| Landing | None (static copy; no anonymous API calls) |
| Login and register | `POST /auth/login`, `POST /auth/register`, `GET /auth/me` |
| Experience cards | `GET /experience-levels`, `PUT /me/experience` `{experience_level_id}` |
| Placement | `POST /assessment`, `PUT /assessment/{id}/answers/{pos}` `{choice_id}`, `POST /assessment/{id}/submit`, `GET /assessment/result` |
| Dashboard | `GET /me/dashboard` |
| Missions and topic | `GET /topics`, `GET /topics/{id}/quiz-attempts`, `POST /topics/{id}/quiz-attempts` `{started_via}` |
| Lesson | `GET /topics/{id}/lesson` |
| Quiz | `GET /quiz-attempts/{id}`, `PUT …/answers/{pos}`, `POST …/submit` |
| Rank and Mana history | `GET /me/rank-history`, `GET /me/coins?limit&before_id` (optionally `GET /topics`, `GET /me/profile` for names) |
| Store | `GET /badges`, `GET /me/profile`, `POST /badges/{id}/purchase` |
| Profile | `GET /me/profile`, `PUT /me/showcase` `{badge_ids}` |
| Admin | All §9–§10 endpoints exactly as today |

### 9.3 Contracts and rules that must not change

- `src/api/client.js`: the base URL, CSRF header, same-origin cookies, error normalisation, no automatic retries and the session-loss listeners.
- `AuthContext`: session authority stays in the HttpOnly cookie. Nothing auth-related goes into `localStorage`. The only permitted browser storage is none, because no new persistence is planned.
- **Request bodies:** keys and types exactly as in `api-contract.md`. No price is ever sent with a purchase.
- **No correct answers before submission.** The review comes only from result responses.
- **Lesson and question text** is rendered only as text or through the safe Markdown renderer. There is no `dangerouslySetInnerHTML`.
- **Outside the scope of the redesign:** backend, SQL schema, seeds, migrations, database records, the `.htaccess` route allowlist and CSP (the only addition is the `reference/.htaccess` deny file), grading, placement, progression, rewards, ledger, badge pricing and approval.

---

## 10. Testing, baseline, risks and implementation order

### 10.1 Commands (from `frontend/package.json` and existing scripts only)

| Command | Writes to a database? | When |
|---|---|---|
| `npm run build` | No (replaces the live `frontend/dist/`) | Every implementation phase (2–8). The pre-redesign `dist` is backed up (§10.2). |
| `npm run test:admin:ui` (`admin-journeys.mjs --mock`) | No (**simulated** responses) | Phases 2 and 7, and any change touching the shell or admin |
| `node tests/apache-probes.mjs` | No (read-only HTTP) | Phase 2 (plus new `reference/` probes) and Phase 8 |
| `node tests/course-review-render.mjs` | No (component render) | Phases 5 and 8 (lesson and code rendering) |
| `npm run test:browser:isolated` | Fixture databases only | Phases 4–6 and 8, **only if** the fixture servers and protected configs under `%TEMP%\HunterSystem-M7-Verification` are confirmed available |
| `node tests/course-journeys.mjs`, `npm run test:admin` | Fixture databases only | Phase 8 (same precondition) |
| `npm run test:browser` (non-isolated, Part A) | **Yes: creates students and an admin in `hunter_system_dev`** | **Not run** without your separate approval |

### 10.2 Baseline protection (done in Phase 1)

| Item | Status |
|---|---|
| External full-project backup | `C:\Users\Nikhil Modi\Downloads\hunter-system.zip` (2026-10-18 08:13). **CRC-verified: 1,049 files identical, 0 different.** Only the 26 later-added reference-pack files are absent. |
| Separate copy of the served `dist/` and the reference pack | `C:\xampp\hunter-system-backups\2026-10-18-pre-redesign\` (`frontend-dist/`, `reference/`), verified identical with `diff -r`. Outside htdocs, so not web-served. |
| Parent-repository check | No Git repository in `hunter-system`, `htdocs` or `C:\xampp`. |
| `.gitignore` | New. Excludes `*.env` (keeps `*.env.example`), keys, `node_modules/`, `.npm-audit-cache/`, `frontend/dist/`, `*.log`, `logs/`, `sessions/`, database dump patterns and Python caches. |
| Secret scan before commit | No real credentials found. `hunter.env.example` holds placeholders only, and the grant SQL holds templates only. `C:\xampp\hunter-system-private\hunter.env` is outside the repository and was not read or copied. |
| Repository settings | `core.autocrlf=false` (repository-local), so Git never rewrites line endings in served files. |
| Baseline commit | `459c0d9`, 342 files. Working tree clean. |
| Web exposure | `/hunter-system/.git/HEAD`, `/.git/config` and `/.gitignore` return **404**. Health still 200. |

Each implementation phase ends with one commit, so any phase can be reverted with `git revert`.

### 10.3 Test-update policy (Phases 2–8)

- Tests are updated **in the same phase** as the UI change they cover. No phase ends with knowingly broken tests.
- **Preferred selectors:** roles and accessible names (`getByRole('radio', {name: /Sung Jin-Woo/})`, `getByRole('heading', {name: 'Badge Store'})`, `getByRole('button', {name: /^Spend \d+ Mana$/})`, `getByRole('dialog')`), labels and landmark scoping. CSS-class selectors (`.badge-card`, `.roadmap-item`, `.map-btn`, `.stat`) are replaced where touched. `data-testid` is used only where no semantic hook exists, and the reason is documented.
- **Wording changes keep the numbers:** `/\+10 coins/` becomes `/\+10 Mana/`, and `/Balance: 50/` keeps its numeric check. Purchase-count, single-request, no-retry, save-state, grading, unlock, role-guard and authorization assertions stay **at least as strict**.
- **Simulated and live are kept apart:**
  - A new `tests/redesign-visual.mjs` uses `page.route` mocks labelled SIMULATED to capture screenshots at 390, 820, 1280 and 1440px plus reduced-motion emulation.
  - It covers the landing page, all four card states, every mission state, full and empty stores, and the dashboard at E and S.
  - Its results are never counted as live.
- **New focused checks:**
  - Rank mapping: all six codes and the three input formats; "Rookie" is never rendered.
  - Experience matching: `basic` maps to Song Chi-Yul; an unknown code gets the fallback card; the submitted id comes from the API.
  - Enter and Space select a card, and flipping doesn't deselect.
  - No horizontal overflow at 320 and 390px.
  - Focus is visible on every interactive element.
  - `reference/` is denied.

### 10.4 Implementation order and phase scopes

| Phase | Scope | Exit checks |
|---|---|---|
| **2: Asset plan, design system, app shell** | See §10.6 (exact scope) | Build passes; Apache probes include `reference/`; simulated admin UI test passes; shell screenshots at four widths; commit |
| 3: Landing page | `PublicLayout`, hero, five sections, footer; `/` behaviour (§2.1); anchors; responsive art cropping | Links go to `/register` and `/login`; authenticated `/` still redirects (student and admin); 320–1440px without overflow; reduced motion; commit |
| 4: Experience cards and assessment UI | `ExperienceCardGroup` and the result page; `QuestionRunner` restyle; tests updated (`input[name=experience]` and others) | id taken from API by code; keyboard and touch behaviour; server placement shown; isolated live suite if available; commit |
| 5: Dashboard and mission board | Status window, rank art tiers, gates and mission cards, topic, lesson and quiz result; Mana wording on these screens | Empty and limited states (dev: 2 ready topics); real-data check; course render test; commit |
| 6: Store, wallet, history, profile | `BadgeCard`, `ConfirmDialog`, ledger, showcase; Mana wording | Purchase outcomes per §5.3 (fixture or simulated); empty store; commit |
| 7: Admin and remaining pages | Admin console restyle, "Price in Mana", rank parsing; auth pages and not-found page | Simulated admin test plus live admin suite if available; every admin capability still present; commit |
| 8: Integration QA | All available commands; manual browser checks with the installed Chrome; verification report | A report that states exactly which checks ran live, which were simulated and which were not run |

### 10.5 Optional enhancements (not in scope unless approved)

- Dashboard "recent activity" built from `/me/coins?limit=3` and `/me/rank-history`. These are existing endpoints, but it adds a request.
- History page names (§5.2 optional join).
- A self-hosted OFL display font, if you supply the file.
- A baked violet colour grade of the hero art.
- Removing the duplicate reference files (§8.4 step 5).

### 10.6 Exact Phase 2 scope (for approval)

1. **Reference protection and paths:** add `reference/.htaccess` (`Require all denied`), carry out the §8.4 copy-only correction, verify with SHA-256, and add the `reference/` probes to `tests/apache-probes.mjs`.
2. **Art assets:** produce the six WebP files in §8.3 into `frontend/src/assets/art/`, with a provenance `README.md`, using no new dependencies. Visually inspect each one.
3. **Presentation module:** `src/config/presentation.js` (§8.1).
4. **Design tokens and base styles:** split `styles.css` into tokens, base and components (§1.2–1.6, §8.2). Every existing page keeps working under the new tokens.
5. **Shared primitives:** `HudPanel`, `SystemNotice` (restyled `Notice`, same API), `ConfirmDialog`, `RankBadge` (new labels; "Rookie" removed from display everywhere at once), `RankEmblem`, `ManaAmount`, `ProgressMeter`, `CharacterArt`, `ParticleField`, extended `Icon`.
6. **App shell restyle:** sidebar, top bar, drawer and footer; navigation labels *Dashboard · Missions · Rank & Mana · Badge store · Profile*; admin variant without particles. No route or guard changes.
7. **Tests:** update selectors and expected text affected by items 5–6 (navigation link names, rank text), run `npm run build`, `node tests/apache-probes.mjs` and `npm run test:admin:ui`, and run the new rank-mapping check.
8. **Commit** the phase.

**Not in Phase 2:** the landing route, the page-level redesigns and the page-level Mana wording.

Between Phases 2 and 6, page bodies keep "coins" wording while the shell says "Mana". This mixed state is temporary and visible on the live build, because every build replaces the served `dist/`.

### 10.7 Risks and limitations

| # | Risk | Mitigation |
|---|---|---|
| R1 | **Low-resolution art** (hero 560×735, portraits 736px wide) looks soft on large or 2× screens | Cap the art at its native size; CSS atmosphere fills the space; never upscale beyond 1× on desktop |
| R2 | **Copyrighted character art** | Local college demo only; footer disclaimer; provenance README; replace before any public deployment (deployment is out of scope) |
| R3 | **Hero palette is red-heavy** against the violet and blue brief | CSS colour overlays; optional baked grade with your approval |
| R4 | **Each build replaces the live `dist/`** | Backup kept; one commit per phase; rebuild from any commit |
| R5 | **Live suites need fixture servers** and protected `%TEMP%` configs that may no longer exist | Check availability in Phase 4; otherwise run simulated plus build plus probes and report live tests as **not run** |
| R6 | **Dev data is sparse** (2 ready topics, 0 badges on sale), so full visual states can't be seen against dev | Simulated visual suite (labelled), fixture suites if available; the dev database is never mutated without separate approval |
| R7 | **System fonts render differently** off Windows | The demo machine is Windows; the stacks fall back cleanly; optional OFL font |
| R8 | **Test drift** while the wording and markup change | Same-phase test updates, semantic selectors, assertion strength preserved (§10.3) |
| R9 | **CSP blocks `data:` URIs and external resources** | All art is imported as hashed files over 4 KB (Vite's inline limit is 4,096 bytes); icons are inline SVG elements; no external URLs |
| R10 | **Mixed coins and Mana wording** between phases | Temporary; resolved by Phases 5–7 |
| R11 | **Unknown future API values** (a new experience code or rank) | Fallback cards and raw-value rendering; nothing silently re-labelled |
| R12 | **The reference video ends with an unrelated advert** | Video excluded from the build; used only as inspiration |
