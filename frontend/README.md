# Hunter System — student and administrator interface (React + Vite)

Final acceptance (2026-10-15): live fixture student31, full-course52, admin52 and concurrency9
checks pass. Dev activation awaits authentic administrator setup by the user. Detailed evidence
and limitations: ../docs/final-local-live-results.md; startup: ../docs/local-startup-demo.md.

Use explicit HUNTER_FIXTURE_PRIVATE_DIR (complete) and HUNTER_SEED_PRIVATE_DIR (seed):
`node tests/admin-journeys.mjs --full-content`, `node tests/browser-journeys.mjs --isolated`,
`node tests/course-journeys.mjs` from frontend. `node frontend/tests/final-concurrency.mjs`
runs from project root. Identity guards refuse dev fallback. Run auth throttle tests separately
from other login-dependent suites. Default legacy admin runner requires its compatible pool
profile; do not reshape reviewed content to satisfy historical assumptions. Mock49 evidence
remains UI-only/historical. Failed logs retained. No production build change this resumed run.

Private folder: Apache serves only the build output (`dist/`) through the allowlisted
rewrites in `/hunter-system/.htaccess`; everything else here returns 403/404.

| Item | Value |
|---|---|
| Tooling | Node 24.21, npm 11.19 (as installed), Vite 8.3.4, `@vitejs/plugin-react` 6.1.2 |
| Runtime dependencies | `react` 19.3.1, `react-dom` 19.3.1, `react-router-dom` 7.18.4 (nothing else) |
| Test-only | `playwright-core` 1.64.1 (drives the installed Chrome; downloads no browser) |
| Router base | `/hunter-system` (`createBrowserRouter` basename), Vite `base: '/hunter-system/'` |
| Build output | `frontend/dist/` — `index.html`, `favicon.svg`, `.htaccess` (from `public/`), hashed files in `dist/static/` |
| Production URL | `http://localhost/hunter-system/` (Apache; API at `/hunter-system/api/v1`) |
| Dev URL | `http://localhost:5173/hunter-system/` (API proxied to Apache) |

## Commands (run in `frontend/`)

```powershell
npm install            # once
npm run dev            # dev server with the API proxy (Apache and MariaDB must be running)
npm run build          # production build into dist/ (Apache serves it immediately)
npm run test:browser   # real-browser journeys (see below)
```

`npm run dev` proxies `/hunter-system/api` to `http://localhost` and presents dev requests
with the Apache origin (the API refuses unknown `Origin`s). Set `HUNTER_API_TARGET` to proxy
elsewhere (the browser tests use it to reach the isolated fixture API).

## Browser journeys

`node tests/browser-journeys.mjs [--shots <dir>]` (31 checks). Part A uses the Apache build with
`hunter_system_dev` (creates `b6.<run>.*@example.invalid` students and one admin; never changes
content or badges). Part B serves the same build with `vite preview` proxied to the fixture API
on `127.0.0.1:8098` (start the two fixture servers first, see `docs/architecture.md` §8) and
creates/retires `[FIXTURE]` badges in `hunter_system_m3_fixture` only. `tests/db-helper.php` is
the test-only database helper (answer keys, an idle-expired session, a test admin, fixture badges).

## Structure

```
src/api/client.js          the only API client (cookies, CSRF, errors, no retries)
src/auth/AuthContext.jsx   /auth/me on load; loading / anonymous / authenticated; session loss
src/lib/                   useApi (abortable loads), useAction (no double submit), safe Markdown
src/components/            Layout, QuestionRunner + Review, shared UI (ranks, notices, badge art)
src/pages/                 Auth, Experience, Assessment, Dashboard, Topics, Lesson, Quiz,
                           History, Store, Profile, Admin (all administration), Misc (not found)
src/styles.css             design tokens and components (dark navy, cyan/violet, E–S shapes)
```

## Administrator interface (Milestone 7, 2026-10-15)

`/admin`: overview; `/admin/students` and `/:id`: tracking; `/admin/login-events`:
global login history; `/admin/topics` and `/:id/lesson`: readiness, topic and lesson
editing; `/admin/questions` and `/:id` (`new` to create): question pools;
`/admin/badges` and `/:id` (`new` to create): catalogue, approval and sale;
`/admin/audit`: filtered audit history. Old `/admin-pending` links redirect.

`src/pages/Admin.jsx` uses the existing API client, authentication, page-local
abortable loading, single-flight actions and design tokens. Original revisions/versions
are retained until a successful response or deliberate conflict reload. Unsaved fields
survive validation, network and conflict errors. Conflict comparison displays draft
and server record side by side without changing either. Router navigation, history,
refresh/close and logout warn on dirty forms. Availability, retirement and exact-price
approval require explicit confirmation. Correct choices and audit values stay within
admin role guards; logout/account changes unmount role data. No uploads or extra powers.

The router now uses React Router's data router to support dirty-navigation blocking;
basename, student routes and API contract are unchanged. Apache allowlists `admin`.

### Additional checks (PowerShell: use `npm.cmd` if npm.ps1 is blocked)

```powershell
npm.cmd run test:admin       # REAL fixture mutations; requires existing fixture configuration and API
npm.cmd run test:browser:isolated # full student regression on real seed/complete fixtures
npm.cmd run test:admin:ui    # Chrome with SIMULATED responses; no database writes
node tests/admin-readonly.mjs # real Apache/dev, existing b6 test accounts; admin writes blocked
node tests/apache-probes.mjs  # private URLs, deep links, anonymous admin API rejection
```

**Milestone 7 verification is complete.** Exact provisioning/start/run/cleanup instructions:
[milestone-7-verification.md](../docs/milestone-7-verification.md). Run them from the project
root under the same non-elevated identity as the test servers.

Original private folders remain protected. Test-only setup creates
`%TEMP%\HunterSystem-M7-Verification` outside htdocs with an ACL for the actual executing
SID, SYSTEM and Administrators only. `seed/` uses one-time seeded
`hunter_system_m7_seed_fixture` as `hunter_m7_seed`; `complete/` reuses
`hunter_system_m3_fixture` as `hunter_m7_fixture`. No production credentials/root passwords
are copied into these configs. Browser helpers now use restricted config credentials.

Set `HUNTER_SEED_PRIVATE_DIR` to `seed/` and `HUNTER_FIXTURE_PRIVATE_DIR` to `complete/`.
The seed API runs on fixed loopback port 8097; complete API on 8098/8099. Runners and
previews attest the actual database/account before writes; dev or unexpected URLs/identities
are refused. The real admin runner creates lessons in unused seed-fixture slots and performs
all remaining mutations in the complete fixture. It uses **no simulated responses** in live
mode: actual pool refusal/override, validation, stale conflict, exact audit matching and idle
session expiry are verified. `--mock` remains separate. Fixture history is retained; no reset.

Student `--isolated` preserves all existing 31 journeys using previews 4172/4173 and real
seed/complete APIs. Its established controlled network-fault scenarios remain explicit.
Complete fixture readiness is restored through audited API actions/new `[FIXTURE]` content;
retired questions are not reactivated. Badge helper cleanup touches only the current run's prefix.

Latest Codex results: API integration **15/15**, API fixture **32/32**, real admin Chrome
**58/58**, student Chrome **31/31**; separate simulated admin **49/49**. Build and Apache
protection pass, including the new test provisioning sources and absence of the test identity
endpoint from the normal API. Six unsafe/mismatched targets refused; three exact live fixture
identities accepted. Dev content/audit fingerprints match; IR-1 remains unchanged.

Evidence: `tests/results/m7-live-*.log`, `m7-simulation-final.log`, `m7-preservation.json`,
`m7-target-guards.log`, `m7-apache-verification-final.log`. Initial screenshots/read-only results
remain preserved. General cross-browser/device/load/full-application/security review is later
work, after final course and badge content completion.

## Milestone 8A content verification

Private course sources and answer banks live in `../content/`, never frontend public/dist.
With the complete fixture server on 8098 and the existing restricted private configuration:

```powershell
$env:HUNTER_FIXTURE_PRIVATE_DIR = Join-Path $env:TEMP 'HunterSystem-M7-Verification\complete'
node tests/course-journeys.mjs
```

This fixed-target real-API/installed-Chrome test checks E placement, sequential topics 1–18,
all 192 new questions across three attempts per new pool, grading/rewards/final S rank,
pre-submission answer privacy, desktop/mobile code formatting and private source blocking.
Latest result 44/44, no simulated responses; `tests/results/m8a-course.json`.
Existing build is reused; no production frontend changes. The complete fixture now contains
published course content, so do not blindly rerun historical placeholder assumptions or
reset history. See `../content/README.md` and `../docs/milestone-8a-content.md`.
Dev draft import and human content sign-off remain pending; badge content is the next task.

## Milestone 8B badge proposals

Final local acceptance has started, but service/authentic-admin prerequisites are blocked.
Current build passes; Chrome and Edge each pass73 component-render checks (both Chromium;
viewport emulation only). `course-journeys.mjs` now requires the final exact six on-sale
badges and covers failed-first reward/retry,180 coins, all six UI purchases, duplicate and
unaffordable rejection, showcase/ledger reconciliation. It writes final-course-acceptance.json;
it has NOT run against the revised final state. Apache probes now include private content
sources/banks/backup paths. See docs/final-local-test-report.md and docs/local-startup-demo.md.

Current revision: approved Shadow Recruit/Soldier/Knight/Elite Shadow Knight/Commander/Monarch
at10/15/20/30/45/60. Updated v2 artwork and local review pages validate successfully.
The old54 API checks below are historical; revised live checks are blocked by stopped
MariaDB/Apache. `node tests/course-review-render.mjs` passes73 focused actual-component
rendering checks for18 lessons at desktop/mobile sizes plus safe literal HTML; no API mocks
or live-integration claim. Current authentication/import instructions are in
docs/administrator-content-access.md. Deployment is out of scope.

Badge proposals are now prepared in `../content/badges/` (manifest, private SVGs, local
review/contact sheets, real store/profile screenshots and reports). Only six PNG exports
are public at `assets/badges/m8b-*-v1.png`; no dev catalogue entries were created/approved.
`node tests/badge-artwork.mjs` renders/validates PNGs and desktop/mobile proposal sizes.
With the existing complete fixture backend8098 and explicit HUNTER_FIXTURE_PRIVATE_DIR,
`node tests/badge-proposal-journeys.mjs` passes54 real API/Chrome checks. It reuses the
existing fixture administrator, creates no admin and imports no course content. It tests
six proposal catalogue lifecycles and three browser purchases/showcase, then leaves its
fixture badges off sale while preserving ownership/history. No simulated responses.
User review, authentic course import and publication approval remain pending. See
`../docs/administrator-content-access.md` for hidden interactive credential prompts.
