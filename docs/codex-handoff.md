# Codex handoff — 2026-10-15

## Resumed final acceptance — latest status

Current task completed available fixture acceptance; user will handle authentic admin login
afterward. Existing Apache/MariaDB reachable, no competing DB or original ACL changes.
Live fixture full-course52/student31/admin52/assessment34/quiz51/badge12/admin API15/
concurrency9/focused11 pass; auth41/42 (TLS-to-HTTP-fixture probe unverified). Apache probes
pass. Dev unchanged:2 published lessons,0 on-sale badges,4 draft samples,0 authentic admins.
Restricted logical backup created and verified for markers/hash, not restored. No dev audit
actor fabricated. See final-local-live-results.md and local-startup-demo.md; publication
approval persists. Next: authentic backup-first activation, dev smoke and remaining checks.

Full fixture importer receipt deliberately reconciled after audited permanent-retirement
replacements and lesson18 restore; recovery IDs/actor in content/review/final-fixture-recovery.json.
Original history preserved. Full-content admin runner skips destructive legacy pool shaping;
boundary protection separately verified. Separate legacy-account CREATE USER hit AriaErrcode22,
partly created unused account, no config/grants verified; no retries/recovery/global changes.
Legacy rollback suites and complete registry advisory audit remain unverified. Earlier handoff
sections are historical; no deployment or claim of complete local acceptance.

Workflow: requirements → flow → database → architecture/setup → backend/API/testing are implemented; student frontend/integration and Milestone 7 administrator frontend/integration verification are complete. **Next: course and badge content completion**, then full application testing and final security review. Do not deploy or change machine-wide database security as part of this task.

## Final local acceptance — current handoff

Publication, approved badge prices and sample retirement now authorized locally. No extra
permission needed; still no authentic admin or running MariaDB/Apache. Current identity
codexsandboxoffline; DB startup permission denial with ACL allowing normal Authenticated Users
Modify points to restricted execution, not corruption. Ask normal owner to start XAMPP;
never recover/reset data or weaken ACLs. No dev backup/content mutations occurred.

Actual checks: buildPASS;70 PHP syntax files;187 Python cases;73 Chrome+73 Edge rendering
checks (same Chromium engine/emulatedmobile); six PNG validations;20 security unitchecks;
five offlinefixture guards. Audit networking failed. Reproduced/fixed Logger exception-value
leak and Request empty-array-as-object defect; live PHP regression still blocked.

Extended import_course.py --include-foundations + publish-local; import_badges.py activate-local;
local_content_batch.py --activate-local creates/checks dump and outside-htdocs capture_acceptance.php
fingerprints history/audit/IR-1, then uses real authenticated admin APIs. Strict preflight/revisions,
stable receipts and no-op reruns retained. New live paths unverified. Current full-content
course-journeys.mjs now includes fail-first reward/retry,180 coins/all-six UI purchases,
duplicates/affordability/showcase/ledger; outputs final-course-acceptance.json only when run.
Some old suites need compatible placeholder/seed profiles; do not delete reviewed fixture history.

Verdict and exact remaining coverage: final-local-test-report.md; actions: local-startup-demo.md.
Not yet accepted for local demo. Next fixture rehearsal/authentic activation/full localhost
functional and basic security tests/fixes. Deployment/public exposure remains out of scope.

## Updated content handoff — prior

AI-assisted educational review: content/review/course-review.md, all18 topics/216 quiz
questions+two foundation assessment questions.187 Python cases and73 real Chrome component
render checks passed. Topic1–2 reviewed sources/corrections are private and based on seed;
live DB could not be reread, so compare current records before any audited correction.
Topic2 swap initialization fixed; simpler type questions and missing prerequisite definitions
corrected in canonical author_course.py and regenerated sources. IR-1/question106 unchanged.

Approved badge names/prices: Shadow Recruit10 / Shadow Soldier15 / Shadow Knight20 /
Elite Shadow Knight30 / Shadow Commander45 / Shadow Monarch60 (180). New v2 PNGs and
updated manifest/review sheets at content/badges. Six PNG/alpha/responsive checks pass.
Old lifecycle screenshots/reports are historical, not revised live evidence. Sample retirement
authorized but not executed. Six new badges remain unimported/off sale; no price-approval event.

Live blocker: MariaDB startup denied writes to C:\xampp\mysql\data; Apache unavailable.
No ACL/machine-wide changes. No authentic administrator identified; no dev backup or writes.
Current fixture mutation/idempotency/audit/history/private-HTTP checks remain blocked.
Prepared content/tools/import_badges.py (dev/attested fixture), backup requirement in course
importer, and local_content_batch.py with hidden interactive authentication and backup first.
Syntax/guard checks pass; new live importer paths not verified yet. Exact user commands in
administrator-content-access.md. No course publication or badge sale. Next: local startup,
fixture rehearsal, authentic draft/off-sale imports/sample retirement, publication approval,
then final localhost/full/basic-security checks. Deployment explicitly out of scope.

## Milestone 8B — prior handoff

Six badge proposals at `content/badges/manifest.json`; local `review.html`, desktop/mobile
contact sheets and actual store/profile screenshots in the same private directory. Original
editable vectors generated by `build_proposal.py`; only six 512×512 transparent PNGs in
`assets/badges/m8b-*-v1.png` are public. Not approved catalogue content. Proposed total240,
alternative180; maximum reward earnings180 unchanged. Four dev sample badges remain untouched.

Actual verification: `frontend/tests/badge-artwork.mjs` verifies PNG/alpha and responsive
review sizes; `badge-proposal-journeys.mjs` passes54 real fixture API/Chrome checks, creates
no administrator, reuses the existing M7 fixture-only actor and labels all test rows/students.
It exercises six catalogue lifecycles, price invalidation, three UI purchases and ordered
showcase, image/static/private-path checks. Proposals left off sale on fixture. Existing dev
content/badges/audit fingerprints unchanged; no dev catalogue mutation or course import.
Reports and fixture preservation baseline live privately under `content/badges/`.

Next: user decisions on names/artwork/prices/sample retention, course human review,
authentic administrator identification and authenticated imports, then separately approved
publication. `administrator-content-access.md` documents existing CLI creation and hidden
password prompts; never request passwords in chat or use fixture test accounts for dev.
Full application/cross-browser/accessibility/security review remains afterward.

## Milestone 8A — prior handoff

Course drafts topics 3–18: 16 lessons, 192 questions, in private `content/topics/`.
Canonical source `content/tools/author_course.py`; explicit snippet validator
`validate_course.py`; audited optimistic-concurrency API importer `import_course.py`.
Python 3.14.8: 163 cases passed. Real fixture API: all 18 topics ready and progression
complete, all 192 new questions selected/graded; real Chrome course sample 44/44.
History baseline: 11,735 original rows unchanged. Dev content/audit fingerprints unchanged;
IR-1 preserved. See `milestone-8a-content.md`, `content/validation-report.json`,
`content/fixture-verification.json`, `content/preservation-report.json`, and frontend result JSON.

**Do not mark dev import complete:** explicit administrator identity/authentication is still
required. No dev course content imported/published. Actor question remains pending; no
historical test account was chosen. `content/README.md` gives draft-import and review steps.
Next: course human review/attributable draft import, then final badge content, full application
testing and final security review. Do not reset fixtures: complete fixture now has real course
content, so historical placeholder-assuming suites may need deliberate fixture-profile handling.

## Milestone 7 verification completed — prior evidence

Real API integration **15/15**, real API fixture **32/32**, real admin Chrome **58/58**, full
student Chrome regression **31/31**. Separate simulated admin Chrome **49/49**. Build and
Apache protection pass (37 private paths, 15 SPA routes, 5 anonymous API rejections). Dev content/audit checksums are identical before/after, audit count
still 1, IR-1 row unchanged, question 106 active/revision 1. Required verification is no longer
blocked. See `progress.md` current completion section and `milestone-7-verification.md` for
exact commands, account scopes, evidence paths and remaining general test limitations.

The original access failure was Windows denial of `hunter.env` reads and ACL inspection
under `LAPTOP-P157L62J\codexsandboxoffline`, while `USERNAME` inherited `Nikhil Modi`.
The legacy setup ACL selects that environment username instead of the token identity.
Original current ACL entries were inaccessible and were not claimed as inspected. Sandbox
write boundaries also exclude the original outside-workspace directories. Original folders,
permissions, production credentials and original DB accounts remain untouched.

`backend/tests/fixtures/setup_m7_access.php` provisions a protected child of the allowed
temporary directory, `%TEMP%\HunterSystem-M7-Verification`, outside htdocs, for the actual
token SID, SYSTEM and Administrators only (ACLs verified). `complete/` uses the existing
`hunter_system_m3_fixture` as new `hunter_m7_fixture`; `seed/` uses the new one-time seeded
`hunter_system_m7_seed_fixture` as `hunter_m7_seed`. Each restricted account has the exact
57 application grants on only its own fixture DB, no dev/global privileges. Configs contain
fresh application secrets only. Browser helpers use restricted credentials, no root password.

Test servers bind loopback: 8097 seed, 8098/8099 complete. The test router rejects dev/unknown
identities; `__test/identity` attests the actual PDO database/account and is absent in Apache's
normal API. PHP/browser runners and preview proxies compare exact expected identities before
writes; six negative target guards pass. `HUNTER_M7_ISOLATED=1` selects seed mode for the
15-check API suite; `test:browser:isolated` runs the entire student regression on real fixtures.
`test:admin` uses `HUNTER_SEED_PRIVATE_DIR` plus `HUNTER_FIXTURE_PRIVATE_DIR`; no fake
responses are used in live admin mode. `--mock` remains separately labelled.

No production React/backend/schema/Apache change was needed in this verification task.
Only test infrastructure/configuration changed; fixture histories remain, badge helper
cleanup is restricted to the current run. The real browser tests cover lesson creation,
actual pool guards/override, exact badge audit sequence, two-context stale conflicts and real
idle session expiry. The original partial results below remain historical implementation evidence.

## Inspection baseline

Workspace confirmed as `C:\xampp\htdocs\hunter-system`. No Git repository, AGENTS.md or CLAUDE.md was found; existing files must be preserved directly. requirements.md and application-flow.md are absent. project-context.md has historical scope statements superseded by A-01–A-09 and contract sections 9–10. Historical test counts in progress.md are prior-agent reports, not Codex executions. IR-1 and audit row #1 must remain intact.

## Architecture and paths

- `frontend/src/main.jsx`: React root, data router `/hunter-system`, AuthProvider. `App.jsx`: onboarding/role guards. `components/Layout.jsx`, `components/ui.jsx`, `styles.css`: shell and existing design system. Data router enables dirty-form `useBlocker`; no runtime dependencies added.
- `api/client.js`: same-origin cookies, memory-only CSRF, no automatic retries, normalized errors and session-loss listeners. `auth/AuthContext.jsx`: identity/session lifecycle. `lib/useApi.js`: abortable page-local data and single-flight actions. No global content cache or browser storage.
- Student pages: Auth → Experience → Assessment/results → Dashboard/Topics → Lesson/Quiz; History, Store, Profile. Each calls actual contract endpoints. QuestionRunner saves answers and safely shows post-submission review; markdown renderer escapes text and supports only its limited syntax.
- `backend/public/index.php` → `src/bootstrap.php` → `src/App.php`: explicit routing, Origin/JSON checks, authentication/role checks, CSRF and safe exception handling. Controllers validate body keys; services implement rules/transactions; repositories use PDO parameters.
- Services: AuthService/SessionManager/LoginThrottle; OnboardingService/AssessmentService/DashboardService; LearningService; BadgeService; ContentService/CatalogueService/AdminTrackingService/AuditService. Repositories mirror these modules. `Db/Database.php` sets UTC, native prepares and READ COMMITTED, rolls back exceptions and retries admin deadlock victims only.
- `database/schema.sql`: 35 tables, 34 triggers, 8 views; migrations 002/003; `seed.sql` inserts missing IDs without overwriting; `accounts/hunter_app_grants.sql` restricts API privileges. No schema change needed for admin UI.
- `.htaccess`: API rewrite, strict build/artwork allowlists, private catch-all 404 and CSP. Folder rules deny private code/docs/SQL/tests; dist allows only built assets. Secrets are outside htdocs, never copied into this document.

## End-to-end invariants

- Login creates tracked session + login event, regenerates PHP ID and returns CSRF; later authorization reads database role. Session idle limit 30 minutes, recent activity 15 minutes, touch interval 60 seconds. Recent activity is not proof of an online browser. Logout and expiry are distinct recorded reasons.
- Assessment start locks profile, selects group quotas 2/2/2/2/1/1 with shared question locks and snapshots choices. Save/submission lock profile then owned attempt. Submission grades snapshots, records placement and unlocks earlier topics; never awards coins. Recommendation and fallback remain distinct.
- Quiz locks profile then attempt, grades snapshots and atomically records result, first-completion 10-coin claim/ledger, next-topic unlock on pass and monotonic promotion. Retries cannot pay twice. Content readiness and per-student unlock are distinct.
- Purchase locks wallet then badge in share mode; ownership and debit commit together. Showcase accepts at most three distinct owned IDs in order; retired owned badges remain usable.
- Admin content locks topic before lesson/question and choices; catalogue locks badge only. Changes and audit rows commit together. Lessons/questions use exact integer revision; topics/badges use opaque version. Refused stale writes must never be retried automatically. Question status transitions do not increment content revision; server transition and pool checks still apply.
- Curriculum titles/order are fixed. Topic draft hides new learning access without deleting progress. Retirement cannot override assessment quotas; quiz pool override explicitly drafts the affected topic. Badge price changes revoke approval and withdraw on-sale badges.

## Completion and remaining work

Backend milestones 1–5 and student React screens exist. Only dev topics 1–2 are ready; 3–18 are drafts. Four sample badges remain draft/provisional with missing artwork; live store intentionally empty. Do not generate/publish final content as part of Milestone 7.

Administrator interface now exists in `frontend/src/pages/Admin.jsx`: endpoint-derived overview;
tracking/list/detail/login events; topics/readiness/summary and lesson editing; question
list/editor/activation/retirement; badge catalogue/editor/approval/status; audit filters and
before/after values. Route prefix `/admin`, old `/admin-pending` redirects. Shared Editor
retains original token + draft, preserves errors and requires deliberate conflict comparison
and discard/reload. Pending writes are single-flight; dirty navigation/logout and lifecycle
actions prompt. Data stays component-local, unmounted on auth/identity/role change.

## Test commands and boundaries

- `cd frontend; npm run build`; `npm run test:browser`: existing real installed Chrome via playwright-core, Part A Apache/dev account and student journeys; Part B fixture purchases via preview proxy.
- `C:\xampp\php\php.exe backend/tests/admin_integration_test.php`: dev read/refused-mutation suite with content/audit fingerprints.
- Set `HUNTER_PRIVATE_DIR` to the complete test configuration; run `backend/tests/admin_fixture_test.php`: successful mutations only in `hunter_system_m3_fixture`, restricted `hunter_fixture` or `hunter_m7_fixture` account, attested servers on 8098/8099. Existing fixture setup must not reset or replace history. New isolated access commands are in `milestone-7-verification.md`.
- Other existing suites: auth, assessment, quiz, badge integration; quiz/badge fixture. SQL verify is integrity/seed verification; transaction harness only accepts disposable `_verify` database.
- Fixture server router is `backend/tests/fixtures/router.php`; frontend preview uses `HUNTER_API_TARGET=http://127.0.0.1:8098`. Successful admin browser writes require fixture target guards before browser launch.
- `npm.cmd run test:admin`: fixed API 8098, requires config DB/account identity and fresh random fixture-admin login before content writes. Does not silently fall back to dev. `npm.cmd run test:admin:ui`: explicit simulated API, no integration claim. `node tests/admin-readonly.mjs`: real dev Chrome using historical b6 test accounts; admin writes blocked and content/audit checksummed. `node tests/apache-probes.mjs`: read-only Apache checks.

## Initial Codex implementation evidence (superseded by completion above)

Final build passed (392.04 kB JS, 120.16 kB gzip; 17.51 kB CSS). Chrome 155: **49/49
simulated-response UI checks**, **21/21 actual read-only checks**. Private probes: **34**
blocked; **15** SPA paths served with CSP; **5** anonymous admin API reads refused. SQL
verify personally executed: dev **66/0/3**, fixture **64/0/5**, temporary integrity changes
rolled back. Source-defined live object counts confirmed: 35 tables/34 triggers/8 views.
Evidence logs and screenshots: `frontend/tests/results/`. The existing student browser
suite was attempted once, **4 passed then 1 aborted** at the private-config helper; not a
31/31 result. Admin API/fixture suites and real fixture browser runner fail before checks
at private-config loading, so successful real mutation/conflict journeys remain unverified.
Controlled pool errors/session expiry in UI simulation are client handling evidence only.

At the initial implementation stage, private configuration folders denied CLI access.
That stage's blocked suites are now completed using the isolated configuration above;
the original ACLs/secrets remain protected. The initial student run left
`b6.0227219e4f.a@example.invalid` and an open placement
attempt; retained as test history. Real read-only admin checks reused old accounts only.
Dev content/audit checksums remained identical; IR-1 audit row unchanged. No successful
admin mutation was sent to dev, no schema/backend-runtime changes and no content authored.

## Material discrepancies / review items

Historical architecture/database requirement portions describe earlier scope; latest approved
decisions and contract control. Current architecture/progress/frontend README describe M7.
Historical database README counts precede latest 66/64 verification counts. Registration is
still unthrottled in AuthService. Current netstat shows MariaDB on all IPv4/IPv6 interfaces;
root metadata confirms empty authentication for localhost/127.0.0.1/::1. Scheduled-task
inspection failed; purge remains historically unscheduled, current schedule unconfirmed.
No machine-wide change was made. Final review is pending, not a security certification.
