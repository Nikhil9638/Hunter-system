# Hunter System — Claude Code Frontend Redesign Master Prompt

You are working directly in the existing local repository:

`C:\xampp\htdocs\hunter-system`

Your task is to redesign the **entire frontend of Hunter System** in clearly separated, reviewable phases. Do not rebuild the project from scratch and do not assume the current UI or API details without inspecting the repository.

## 0. Existing project and non-negotiable boundaries

- Frontend: React + Vite. Confirm the exact version, router, scripts, component conventions, and current source tree by inspecting the repository.
- Backend: PHP + PDO, served by Apache/XAMPP.
- Database: MariaDB. The intended live local app database is `hunter_system_dev`.
- Current health endpoint has returned HTTP 200 with `database`, `schema`, `intl`, and `argon2id` checks all `true`.
- The existing authentication and session flow works.
- The existing project already has API routes, backend business rules, SQL schema, seed data, and administrator account. Treat those as the existing source of truth.

### Do not do these things

1. Do **not** rewrite or replace the backend.
2. Do **not** change SQL schema, seeds, migrations, database records, authentication, session behavior, quiz grading, placement logic, progression, coin ledger, badge pricing/approval, or API routes as part of this frontend redesign.
3. Do **not** generate a new fake API or hardcode mock data into the real screens.
4. Do **not** edit generated `frontend/dist/` files as source. Edit the actual React source and run the project's existing build command.
5. Do **not** expose answer keys, private configuration, `hunter.env`, logs, tests, or the `reference/` folder over Apache.
6. Do **not** use external hotlinked assets or add dependencies before checking whether the current project already has an appropriate solution.
7. Do **not** automatically proceed through all phases in one run. **Complete only the current phase, report results, then stop and wait for my explicit approval.**
8. Do not reset/recreate the database, drop tables, or change the working XAMPP setup.

If an API response doesn't provide a value a design seems to need, don't invent the value. Identify the gap and propose a small, explicit follow-up decision instead of silently changing the API.

## 1. First read the reference folder and project documents

The project root should contain a `reference/` folder with the supplied images and video. Inspect `reference/README.md` first, then inspect the actual files visually if tooling supports it. The asset descriptions below explain intent; use the actual artwork and video as visual references rather than guessing from filenames.

Read these project documents if present:
- `project_Context.md`
- `database_requirement.md`
- `backend_design.md`
- `api-contract.md` or any API contract document already in the repository
- `docs/` architecture/design/decisions/progress/acceptance notes

If a named file doesn't exist, find the corresponding source-of-truth document and report the gap. Do not invent its contents.

## 2. Design direction

Create a polished, responsive **Solo Leveling-inspired fantasy RPG / hunter system interface for learning Python**.

### Visual language

- Main atmosphere: cinematic, dark, premium, mysterious.
- Base: midnight black/navy; use deep royal purple and restrained indigo gradients.
- Accents: electric blue/cyan for system HUD, mana, focus and interactive states; violet/magenta ambient light; limited warm gold for rare/S-rank or premium badge emphasis.
- Surfaces: layered dark panels, thin luminous borders, subtle glass/metal effects, generous spacing, readable contrast.
- Typography: strong display heading for cinematic moments, clean sans-serif for all functional UI. Keep text readable; avoid excessive decorative fonts.
- Shapes: crisp HUD frames, thin dividers, framed character art, compact stat blocks, quest/misson cards, rank emblems, controlled glow.
- Motion: restrained entrance effects, ambient particles and card transitions. Support `prefers-reduced-motion`; avoid heavy loops that harm performance or usability.
- Responsive: desktop, tablet, and mobile. Do not simply shrink desktop elements.
- The supplied short video shows a dark purple/magenta fantasy gaming site with a cinematic hero and distinct About/Mission/Games/Crew-style sections and card-based content. Reuse its *site-flow and atmospheric inspiration*, not its brand names, exact copy, or screenshot layout.
- The fifth photo, `05_landing_page_solo_leveling_ui_inspiration.jpg`, is the main reference for a character-centered Solo Leveling interface: a large Jinwoo-with-weapon visual, editorial text area, dark-purple environment, and restrained HUD navigation. Recreate an original responsive web layout inspired by it. **Do not place the entire reference screenshot as a picture inside the finished landing page.**
- `06_neon_notification_hud.jpg` is an inspiration for system notifications, confirmation dialogs, alerts, and quiz/mission state changes.
- `07_igris_stats_dashboard_reference.jpg` is the main inspiration for a hunter profile/status dashboard: character/status presentation and organized stat panels.
- **Reference-numbering ambiguity to resolve during Phase 0:** your request says the dashboard should look like the “6th image,” but the sixth supplied still is a neon notification HUD; the Igris stat-sheet immediately after it is the more direct dashboard/status reference. Tentatively use the neon HUD for alerts/mission overlays and Igris for dashboard organization, then ask me to confirm in the Phase 0 report before implementing those screens.
- `08_shadow_roster_badge_grid.jpg` and `09_character_card_collection_reference.jpg` are inspiration for an illustrated grid/card treatment in the badge/artifact store. They are references, not a command to add those depicted characters as new database products.

## 3. Rank names and assessment-card mapping

Keep backend rank codes and experience option codes unchanged. Put presentation labels in one frontend configuration/constant module rather than scattering strings across pages.

### Hunter rank display labels

Use these primary UI labels:

| Stored rank code | Primary display title | Optional epithet/alternate labels |
|---|---|---|
| E | Peasant | Novice |
| D | Apprentice | — |
| C | Squire | — |
| B | Knight | — |
| A | Champion | Hero, Legend |
| S | Mythic | Immortal, Deity |

Display the stored letter code alongside the label, e.g. **S-Rank · Mythic**. Do not add new rank codes. The words in the last column are alternate presentation/lore terms, not separate ranks or database values. The mapping must be easy to edit later.

### Four experience self-assessment cards

The onboarding/assessment entry screen should show four large character cards using the first four portrait references, with these UI labels and existing experience option mappings:

1. **E-Rank — Sung Jinwoo** → existing code `complete_beginner` → “I'm new to Python. Start with the fundamentals.”
2. **C-Rank — Song Chi-Yul** → existing code `knows_little_basic_python` → “I know a few Python basics but need guided practice.”
3. **A-Rank — Woo Jin-Chul** → existing code `intermediate` → “I understand core concepts and can solve small problems.”
4. **S-Rank — Cha Hae-In** → existing code `master` → “I'm confident with the basics and want to test my skills.”

Use these reference files:
- `04_rank_E_sung_jinwoo_portrait.jpg`
- `03_rank_C_song_chi_yul.jpg`
- `02_rank_A_woo_jin_chul.jpg`
- `01_rank_S_cha_hae_in.jpg`

These are **self-reported experience choices only**. Clearly communicate that the card does not grant the student that actual Hunter rank. The server's placement assessment determines the actual placement rank. Keep the current experience option IDs/codes and API contract exactly as they are.

Card interaction requirements:
- On desktop hover/focus: a smooth flip/reveal gives the explanatory text, with front-side name/rank visible.
- Clicking selects the card and displays a clear selected state; selection must not be accidentally toggled off by the flip.
- On touch devices, users must be able to reveal details and select the card without hover.
- Keyboard accessible, visible focus state, meaningful accessible label, reduced-motion fallback.
- Keep selection based on the current API's expected experience code, not the label.

## 4. “Mana” terminology

The frontend should display **Mana** where the current product UI says **Coins**:
- wallet balance → “Mana”
- reward history/ledger presentation → “Mana earned” / “Mana spent”
- badge-store prices → “Mana”
- purchase confirmation → “Spend X Mana?”

This is a **frontend terminology change only for this task**. Keep existing database names, field names, ledger semantics, IDs, API routes, and payload shapes intact (e.g. don't rename `coin_ledger`, `balance_coins`, or response fields). Map the existing backend balance safely into the UI label “Mana”. Do not create a second currency or alter purchase/reward amounts.

## 5. Page-specific expectations

### A. Landing page

Build a cinematic hero with:
- large character-led composition inspired by the fifth reference, preferably Jinwoo wielding a weapon;
- dark-purple/night-sky background with restrained blue/violet energy effects;
- left/primary text: an original headline such as **“AWAKEN. LEARN. LEVEL UP.”** and a brief explanation that Hunter System teaches Python through missions;
- clear CTAs: **Begin Awakening** / **Enter Hunter Portal** (use existing routes);
- subtle navigation and compact HUD-like status decoration;
- responsive composition where the character and text remain legible on phones.

Inspect the provided image assets. The fifth reference is a UI design screenshot, not a clean standalone hero asset. Do not use that whole screenshot as hero artwork. If an image crop is technically possible and visually clean, create a documented, appropriately sized derivative in the frontend's public assets; otherwise use an appropriate supplied character image and recreate the weapon/cinematic atmosphere through composition and other supplied art. Do not pretend a poor crop is clean. Do not fetch assets from the internet.

Below the hero, use the video's broad storytelling structure adapted for this project:
1. “How awakening works” / a short explanation;
2. Python topic mission/roadmap preview;
3. Hunter ranks/progression explanation;
4. Mana and cosmetic artifacts/badges;
5. final signup/login CTA.

Use functioning existing links. Do not invent unrelated “games” or “crew recruitment” features from the reference video.

### B. Onboarding and placement assessment

Use the four flip/reveal experience cards described above. Preserve the current assessment questions, answers, scoring, and API flow. Do not place correct answer keys in frontend source. Display the actual placement result returned by PHP after submission, not a client-calculated rank.

### C. Student dashboard

Use `07_igris_stats_dashboard_reference.jpg` for the **visual organization** of a hunter status/profile panel:
- prominent character/profile area;
- actual Hunter rank code and mapped display title;
- Mana balance from the actual API;
- actual topic/mission completion counts and available progress data;
- visible “continue learning” / next available mission action;
- recent activity only if the API provides it;
- rank/progression history if already supported.

Do not invent RPG Strength/Agility/HP/Mana-regeneration stats unless the existing backend actually provides them. Never hardcode pretend balances, ranks, quiz history, or completion percentages. If a metric is unsupported, omit it or flag it as a future backend/API proposal.

### D. Missions / quest roadmap

Use “Missions” or “Quests” as the presentation language for the Python curriculum. Preserve the actual 18-topic order, unlock/pass rules, and server statuses. Create a strong mission-board visual with:
- topic/group progress and available/locked/completed state based on API data;
- readable topic names and concise descriptions;
- active mission emphasis;
- clear start/continue/review actions connected to existing routes;
- subtle HUD borders and notification treatments inspired by the reference set.

Never unlock a mission based on frontend-only state. PHP remains authoritative.

### E. Mana and badge/artifact store

Use the shadow-grid and gold-framed character-card images as layout inspiration:
- premium dark cards, artwork, title, actual price in Mana, and owned/on-sale/off-sale state from the backend;
- purchase confirmation before submitting;
- existing badge purchase endpoint and returned result only;
- owned badges should clearly differ from purchasable badges;
- showcase/equip interaction only if the existing API supports it;
- loading, failure, insufficient Mana, and already-owned states must follow real API responses.

Do not add character cards as new products, change badge prices, or make badges unlock curriculum/rank. Keep the current six badge records and the existing purchase/security rules.

### F. Profile, rank/history, and administration

Inspect existing routes before redesigning them. Apply the same design system to supported profile, rank history, wallet/ledger, and administrator pages, but preserve all existing behavior. Do not remove admin content publishing/editing, price-approval, audit log, or student-management capabilities that already exist. Admin interface prioritizes clear forms, status visibility, and safe confirmation over cinematic effects.

## 6. Engineering and testing requirements

- Use existing React router, API client, session conventions, and shared components.
- Read the existing API contract/client before modifying a page. Follow its real request fields, response envelope, CSRF requirements, and error conventions.
- Keep PHP and MariaDB as the source of truth.
- Use reusable tokens and components rather than duplicated per-page CSS.
- Keep functional UI states: loading, empty, validation, network failure, unauthorized, and success.
- Do not store session/authentication authority in `localStorage`.
- Do not inject untrusted HTML for lesson/question content.
- Do not expose correct answers until the backend's authorized review response permits them.
- Respect focus order, semantic HTML, accessible labels, color contrast, touch targets, and reduced motion.
- Check desktop and narrow mobile layouts.
- Do not add libraries without explaining the need and checking package compatibility.
- Run the existing lint/test/build commands discovered in `package.json`; do not invent npm script names.
- After each phase, report files changed, commands run, pass/fail results, unresolved issues, and manual browser checks still needed.

### References folder security and asset policy

The `reference/` folder exists for development-only inspiration. Do not import files from it via runtime paths or expose it in the built app. Add an appropriate Apache 2.4 access-denial rule for `/reference/` (e.g. a local `reference/.htaccess` using `Require all denied`) and verify that a direct browser request cannot serve reference files. Do not delete the source references. Copy only selected, actually used artwork into the frontend's public asset directory, and document where it is used. The images/video are supplied design references; for any public deployment, confirm usage rights or replace copyrighted character artwork with original/licensed assets. Keep the reference video out of production bundles unless the user expressly approves using it in the page.

## 7. Required phases and stop gates

**Do only Phase 0 now. Do not start implementing the redesign yet.** Complete a read-only audit, report findings, and stop. Wait for my explicit message `Proceed to Phase 1`.

- **Phase 0 — Read-only audit:** inspect source structure, current routes/pages, API client, design assets, package scripts, backend/API contract, docs, working tree status, and current behavior. Identify which screens exist and which API data each uses. Check the reference assets. Make no source-code changes. Report risks and questions.
- **Phase 1 — Design specification:** create a concise design plan/spec (design tokens, page map, rank and experience mapping, component inventory, asset use, accessibility and testing checklist). No app-code edits. Stop for approval.
- **Phase 2 — Asset plan + design system/app shell:** protect `reference/`, create only necessary selected app assets, define tokens and reusable primitives, build/maintain shared navigation/layout. Verify build. Stop for approval.
- **Phase 3 — Landing page:** implement cinematic hero and lower landing sections. Verify existing links and responsive layout. Stop for approval.
- **Phase 4 — Experience cards and assessment UI:** implement four flip/reveal cards and integrate with real existing API flow; verify selected experience codes and backend-owned result. Stop for approval.
- **Phase 5 — Student dashboard and mission board:** use real API-backed rank/progress/next-mission data. Add Mana terminology where applicable. Verify responsive states. Stop for approval.
- **Phase 6 — Badge/artifact store, wallet and showcase:** preserve live prices, ownership, API payloads, transaction outcomes, and approval rules. Stop for approval.
- **Phase 7 — Remaining supported pages and admin:** apply theme to profile/history/admin pages without deleting functionality. Stop for approval.
- **Phase 8 — Integration and regression QA:** run the existing test/build commands, fix frontend regressions, manually verify primary student/admin flows where local browser tooling is available, and produce a verification report. Do not claim browser flows passed unless actually tested.

## 8. Phase 0 output format

Return:
1. Existing architecture/source map;
2. Route/page inventory;
3. API/data dependencies per screen;
4. Current design/theme observations;
5. Reference asset mapping and feasibility notes;
6. Commands/tests available;
7. Risks, missing documents, and questions needing user decisions;
8. Exact recommended next phase.

**No code changes in Phase 0. Stop after the report and wait for approval.**
