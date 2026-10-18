# Final local acceptance report — dev activation pending

## Current resumed result (2026-10-15)

Apache/MariaDB are reachable through the existing user-started services. Live isolated
fixture activation and testing completed: full-course52/52, student Chrome31/31,
admin Chrome52 checks, assessment34/34, quiz51/51, badge12/12, admin API15/15,
concurrency9/9 and focused repaired-pool/security11/11. Auth41/42: the remaining
HTTPS probe addresses a plain-HTTP fixture and is unverified. Apache private-path,
SPA/CSP and anonymous-admin probes pass. Simulated tests are separate historical evidence.

**Not yet ready for a complete college demonstration:** dev activation remains pending.
User will handle authentic administrator authentication afterward. Dev still has two
published lessons, no badges on sale and four draft samples; content/audit checksums
unchanged. No dev mutations or fabricated audit attribution. A restricted logical backup
was created and marker/hash verified, not restored. Original fixture history preserved.

See [current detailed live results](final-local-live-results.md) for environment, exact
backup path/hash, test logs, fixture recovery, security findings and remaining checks.
An Aria Errcode22 privilege-write error blocks compatible legacy rollback-injection
fixture provisioning; no corruption diagnosis/recovery or global permission change made.
Dependency registry audit remains unavailable; a partial official advisory assessment
is documented separately. Root is passwordless and services listen on all interfaces;
local-use limitations remain explicit. Publication approval is already valid.

Workflow: implementation/content preparation complete → fixture acceptance verified to
documented limits → authentic localhost activation → dev smoke/remaining checks → local
demo handover. Start MySQL/Apache through normal XAMPP account and open
**http://localhost/hunter-system/**. Follow [startup and activation instructions](local-startup-demo.md).
No deployment. Offline checks below were completed earlier and not repeated unnecessarily.

## Historical offline-stage report (superseded by current result above)

Date: 2026-10-15. Scope: this project on localhost and isolated fixtures only.
**Verdict: not yet accepted for a local college demonstration.** The application build,
content/rendering and focused security checks pass, but the approved published dev state,
full journeys and live basic-security checks have not been established or verified.
Do not describe this as fully secure or all tests passed. No deployment work performed.

## Environment and service diagnosis

- Workspace: `C:\xampp\htdocs\hunter-system`; no Git repository detected. Existing work retained.
- Actual executing identity: `LAPTOP-P157L62J\codexsandboxoffline`, SID
  `S-1-5-21-2729280478-1073095584-1824224882-1020`, restricted workspace-write context.
- Windows PowerShell; Node24.21.0; Python3.14.8; PHP8.2.12 CLI.
- React/react-dom19.3.1, React Router7.18.4, Vite8.3.4, Playwright Core1.64.1
  (read from installed package metadata).
- Apache2.4.58 Win64, configuration syntax passes (`httpd.exe -t`).
- MariaDB10.4.32 from startup banner. No MariaDB/Apache process was running at inspection
  or the final check. No competing server/data directory was started.
- Chrome155.0.8059.79 and Edge155.0.4283.45 tested through Playwright. Both use Chromium;
  Firefox/WebKit/bundled Chromium binaries absent. Desktop/mobile sizes are viewport
  emulation, not tests on actual mobile devices.

Exact attempted startup, only after confirming no mysqld process:

```powershell
& C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone --console
```

It exits1: Errcode13 Permission denied creating the lower-test file/opening
`C:\xampp\mysql\data\aria_log_control`, and ibdata1 must be writable. Plugin initialization
then fails. This is **not evidence of corruption**; no recovery/reset/deletion was attempted.
Visible ACL owner is Nikhil Modi; Authenticated Users have Modify with no displayed explicit
deny. The failure is consistent with this token's restricted sandbox write boundary rather
than a proven ordinary-user ACL misconfiguration. A complete Windows access-check audit
was not available. Apache syntax is valid; no service-configuration failure is established.
User was asked to start both services through the normal XAMPP account; no confirmation arrived.
No ACL, root authentication, firewall, binding, service installation or machine-wide setting changed.

## Approval, activation and backup status

User explicitly approved local publication of the reviewed18-topic course, the six reviewed
badges at10/15/20/30/45/60 (180 total), and audited retirement of four sample badges.
Approval is recorded; it has not been converted into fabricated administrator audit events.

**No authentic administrator identified/authenticated, no dev backup created, no dev content
mutation, no sample retirement and no price approval/publication executed in this run.**
Existing backups/history and IR-1 were not changed by this task. Current dev counts/checksums
could not be read, so prior preservation fingerprints are historical, not current evidence.
Historical dev state had two published lessons/four provisional sample badges; current state
must be reread once services are running. Do not infer current activation from old reports.

Inspected old batch: backup, draft topics3–18, off-sale six badges and sample retirement;
it omitted foundation corrections and all publication. Prepared extended batch now supports:

1. Logical dump outside htdocs, data/schema/trigger/completion marker checks and SHA256;
   routines/events included in dump options. This is not a restored-backup test.
2. Baseline history/audit/ownership/result/ledger fingerprints, including question106/choices.
   CLI configuration must be the expected dev database; fixture environment mismatch aborts.
3. Authentic hidden-prompt administrator plan/apply: reviewed lessons1–18, existing question
   102/112 explanation corrections with original IDs/choices,192 new-bank questions, six badges
   and four audited sample retirements. Reviewed foundation baselines must match; unexpected
   edits stop. Question106 is untouched. Snapshots/grades/purchases/balances are never rewritten.
4. Explicit `--activate-local`: lesson publication/question activation/topic readiness, exact
   badge-price approval and sale through existing authenticated CSRF/admin APIs. Requires all18
   topics ready and exactly the intended six badges on sale; unexpected catalogue entries stop.
5. Additive-history verification. Receipts record each successful operation; reruns compare
   revisions/versions and skip completed changes. Crash gaps require deliberate reconciliation,
   not guessing/recreating rows. The batch is not one global SQL transaction.

**New activation/foundation import paths are syntax-checked, not live rehearsed.** Do not run
dev activation until the current batch has been successfully rehearsed on the isolated fixture.
Repeated catalogue apply after activation now remains a no-op instead of taking badges off sale.
Future backup location: timestamped `C:\xampp\mysql_backups\hunter_system_dev_before_content_*.sql`
and sibling `.history.json`, created only by the user's interactive execution. No new location
can be reported as an actual backup because none was made in this run.

## Checks executed in this acceptance run

| Check | Actual result / target |
|---|---|
| Production build, `npm.cmd run build` | PASS43 modules; existing React frontend/dist; JS392.04kB, gzip120.16kB |
| PHP syntax | PASS70 files, zero failures (backend and content PHP helpers) |
| Python content validator | PASS187 explicit temporary-directory cases;18 reviewed lessons,216 quiz questions; controlled input/files/expected exceptions |
| Existing Markdown component in Chrome | PASS73 checks,18 lessons at1280/390px, code/indentation/overflow and safe literal HTML |
| Same component in installed Edge | PASS73 checks; separate Chromium-branded browser, not a distinct engine |
| Badge artwork | PASS six512×512 PNG signatures/dimensions/alpha;18 previews at desktop/mobile sizes |
| Parser/password/router unit checks | PASS20; invalid JSON types/fields, literal hostile-looking strings, password bounds/hash verification, numeric-route validation |
| Exception logger regression | Failed before fix; PASS after fix, synthetic marker redaction with diagnostics retained |
| Offline fixture guards | PASS5 unsafe URL/database/account combinations rejected before HTTP/config/DB access; positive live attestation not tested |
| Importer Python compilation | PASS; does not prove API integration, idempotency or publication |
| Apache configuration syntax | PASS; no live routing/private-path HTTP pass claimed |
| Dependency audit | BLOCKED: npm registry audit request failed; no vulnerability count/conclusion available |

The rendering harness initially needed adjustments for Vite's build output, production React
and CRLF normalization; final focused rendering checks pass. No test assertions were relaxed.
No simulated-response suite is counted as live API evidence. Earlier Milestone7 and8A/B live
results remain historical and were not repeated against the final configuration here.
Current offline summary: `frontend/tests/results/final-local-offline.json`; content/artwork
and Edge-render details remain in the private content validation JSON files.

## Reproduced application defects and fixes

1. **Exception logging could leak submitted values.** Trigger: unexpected exception carries a
   synthetic secret in its message/argument while PHP trace arguments are enabled. Original
   Logger serialized message/getTraceAsString. Fix: omit arbitrary exception message, retain
   class/code/error ID/location and structured frames without arguments. Synthetic test failed
   first and passes after. Existing private logs were not opened, printed or rewritten.
2. **JSON empty list accepted as object.** Trigger: `jsonObject()` received `[]`; associative
   decoding confused it with `{}`. Fix: require a parsed array whose original JSON starts with
   `{`, preserving nested associative decoding and valid empty objects.20 unit checks pass.
   Live auth/content endpoint regression remains blocked and is required after startup.

No registration-throttle redesign, dependency upgrade or global security change was made
without a testable local environment. Prepared importer improvements are not reported as
reproduced/live-fixed API defects.

## Basic security assessment and limitations

- Authentication, student/admin role and ownership, CSRF, session expiry/logout, SQL execution
  with hostile-looking inputs, answer leakage, double-submit/reward/purchase races and concurrent
  spending: existing implementation/test coverage inspected; **current live checks blocked**.
  Unit acceptance of a SQL-like string as literal input is not a SQL-injection integration test.
- Request parsing/router/password policy and safe React Markdown rendering: focused checks pass.
- Logger exception redaction fixed; historical log content, log retention/rotation and all
  runtime failure paths were not audited. No private secrets/log dumps were printed.
- Private code/config/banks/backups and non-executable PNG/WebP access rules remain in source;
  static rendering passes, **HTTP/private/artwork-handler checks blocked** because Apache is down.
- Restricted-account grant template denies schema/global administration and history deletion;
  actual current SHOW GRANTS/negative SQL execution cannot be verified without MariaDB.
- Login throttling exists (email/IP failures, keyed hashes). Registration currently has no
  dedicated throttle; repeated account creation/Argon2 work remains an abuse/resource risk,
  especially if the app is reachable beyond the intended single-machine use.
- Cleanup CLI exists; no confirmed scheduled cleanup task. No Hunter-named task was visible
  to this identity, which is not proof that all schedules are absent. Retention while idle
  and PHP-session-file/log cleanup need verification. No task was installed or removed.
- Root accepted passwordless CLI connections in prior sessions; current authentication cannot
  be checked while stopped. Do not use root for the app/browser. Keep root/admin credentials
  in local prompts only. No root authentication change was made.
- Apache configuration has `Listen80`; MariaDB has no active bind-address override in my.ini.
  Runtime listening interfaces/firewall exposure are **unverified**, not assumed loopback-only.
  For the demo, use localhost on a trusted machine; do not forward ports/tunnel/share externally.
  Binding/firewall/root configuration remains an explicit owner decision, not a silent change.
- Dependency advisory assessment is unverified because audit networking failed. No “zero
  vulnerabilities” claim. Backend has no Composer dependency manifest/framework.

## Required remaining execution

Start local services under the usual Windows account, identify/authenticate the authentic
administrator locally, and complete current isolated fixture rehearsal with backup/history
evidence before dev changes. Preserve existing fixture content/history; do not blindly reset it.
Some legacy suites assume placeholder content and missing lessons (admin fixture topic18),
or the old hunter_fixture account. They cannot be blindly run against the new full-course
fixture. Use compatible isolated profiles or deliberately adapt assumptions without deleting
history or weakening checks. Old browser admin creation tests also need the seed-profile server.

Prepared `course-journeys.mjs` now targets the final activated six-badge fixture and adds
zero-coin purchase refusal, failed-first reward/successful retry, exact180 earnings, all-six
real UI purchases, duplicate refusal, over-limit/reordered showcase and ledger reconciliation.
It writes `frontend/tests/results/final-course-acceptance.json` only on execution, with an
explicit incomplete/passed status. No current result exists; its new coverage is not counted
as executed. Updated Apache probes include source banks, content tools and backup/artwork paths.

Then run established backend/live student/admin suites and final full-content acceptance:
E placement, fail-first reward then successful no-reward retry, resume/save, recommendation/
offered-topic, unlocks and placement-floor/rank thresholds through18, max180 coins, all six
purchases for180, insufficient/duplicate rejection, ordered three-slot showcase and reconciliation.
Admin tracking/publication/pool/price/retirement/audit/two-context conflict checks; deep links,
multiple tabs, network faults, duplicate responses, sessions, keyboard/mobile and empty/error
states remain required. Real devices and independent Firefox/WebKit engines are unverified.
Recheck both scoped PHP fixes through affected live API suites. Run dependency audit and
private-file/CSRF/ownership/grant/concurrency checks, fix actual failures and retest.

Next workflow: complete blocked prerequisites and fixture rehearsal → authenticated local
activation → full localhost acceptance/basic security → fixes/retest → local demo handover.
Publication is already approved; no repeated publication permission is required. Deployment,
public exposure, external penetration testing and hosting setup remain out of scope.
See `local-startup-demo.md` for exact user actions and the prepared activation command.
