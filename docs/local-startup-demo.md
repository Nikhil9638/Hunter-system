# Local startup and college demonstration

**Current dev acceptance is pending authentic activation.** Services now run normally;
full-course, student/admin and focused security fixture journeys passed. User will handle
authentication afterward. Dev still has two published lessons and an empty store.
Publication approval is already granted for localhost. See final-local-live-results.md.

## Start

1. Sign in to Windows using the usual authorized XAMPP account (Nikhil Modi).
2. Open `C:\xampp\xampp-control.exe`; click **Start** beside **MySQL**, then **Apache**.
   Wait for both to show Running. Do not start another mysqld against the same data directory.
   If startup reports permission denied, use the correct account; do not delete Aria/InnoDB
   files, run recovery, reset the DB, grant Everyone access or change global settings.
3. Open **http://localhost/hunter-system/**. API base:
   `http://localhost/hunter-system/api/v1`. A server-down/500 result is not a content pass.

## Administrator and approved activation

Use your own authentic administrator, never historical example.invalid test accounts.
Passwords belong only in the app login form or a local hidden terminal prompt, never chat,
arguments or documents. If no authentic administrator exists, from normal interactive
PowerShell with the existing private-directory permissions:

```powershell
Set-Location C:\xampp\htdocs\hunter-system
$env:HUNTER_PRIVATE_DIR='C:\xampp\hunter-system-private'
& C:\xampp\php\php.exe backend\bin\create-admin.php --email=YOUR_EMAIL --display-name="YOUR_NAME"
```

This prompts twice without echo and refuses an existing email; it does not promote an
existing student. Confirm your login lands at `/admin`. No administrator was created here.

Before dev activation, complete the current fixture rehearsal below. Once it passes, use:

```powershell
Set-Location C:\xampp\htdocs\hunter-system
$env:HUNTER_PRIVATE_DIR='C:\xampp\hunter-system-private'
python content/tools/local_content_batch.py --email YOUR_EMAIL --backup-dir C:\xampp\mysql_backups --activate-local
```

The prepared command backs up data/schema/triggers/routines/events outside htdocs, hashes
the dump, captures existing history/audit/IR-1 fingerprints, stages reviewed18-topic content
and six badges, retires samples, then explicitly publishes the course/approves exact prices/
puts six badges on sale using your authenticated audit identity. Inspect the saved plans
when prompted. Do not bypass a conflict; compare the current record and saved draft. It is
API activation paths were live-rehearsed on the isolated fixture; the authentic dev wrapper
has not run. A failed batch can leave explicitly staged partial work;
use its receipt/audit to resume safely, never reset databases to obtain a pass.

## Fixture rehearsal / testing

Use the existing outside-htdocs restricted fixture configuration and guarded8098/8099
servers described in `milestone-7-verification.md`. No dev database fallback allowed.
The current TEMP configurations belong to the tool identity: start XAMPP normally and
return to the assistant for these fixture checks; do not weaken ACLs or copy secrets to
make them readable from another Windows account. Commands below are for that authorized
fixture execution context, not an instruction to bypass access restrictions.
Capture a fresh outside-htdocs history baseline using capture_acceptance.php before writes.
With complete fixture8098 running and explicit HUNTER_FIXTURE_PRIVATE_DIR:

```powershell
python content/tools/import_course.py revise-source --include-foundations
python content/tools/import_course.py apply --include-foundations
python content/tools/import_course.py apply --include-foundations
python content/tools/import_course.py publish-fixture --include-foundations
python content/tools/import_course.py verify --include-foundations
python content/tools/import_badges.py plan --target fixture
python content/tools/import_badges.py apply --target fixture
python content/tools/import_badges.py apply --target fixture
python content/tools/import_badges.py activate-local --target fixture
python content/tools/import_badges.py verify --target fixture
```

Retain existing plans: skip plan if already present; never overwrite it to bypass conflicts.
Unexpected on-sale fixture badges must be reconciled through audited admin operations,
not SQL deletion. Verify history/audit and exact ready pools/price approval/sale set, then run
the compatible backend/student/admin full journeys. Some historical suites assume missing
placeholder lessons; inspect/adapt their isolated profile before running. Activation endpoint
paths and affected PHP live regressions now pass. Legacy privilege-fault suites remain blocked
by the documented AriaErrcode22 provisioning error; never run recovery/reset as a workaround.
See final-local-test-report.md for the precise pending coverage. Do not count old reports.

## Demonstration after acceptance passes

- Register a fresh demo student; log out/in and choose experience.
- Complete placement; show recommendation, roadmap and starting rank.
- Read a lesson; save/resume a quiz. Show a completed failure awards10 coins once, then a
  successful retry unlocks the next topic without a second reward.
- Show rank/coin history and dashboard agreement. Completing all18 topics can earn180 coins;
  no manual coin grants or repeat-reward shortcuts are part of the demo.
- Buy approved cosmetic badges with earned coins; explain their titles do not certify skill
  or change E–S rank. Show ordered showcase, up to three slots, and ledger debits.
- Use your own administrator to show readiness, student tracking and audit history. Avoid
  editing reviewed published content casually during the demonstration.

## Shutdown and backup

Log out through the app, close demo tabs, stop any task-owned fixture PHP/Vite servers,
then click **Stop** for Apache and MySQL in XAMPP. Wait for normal shutdown; do not kill
mysqld during writes. Keep existing backups and test/audit history.

For a separate logical backup, use the existing database README command with mysqldump
`-p` (local prompt), `--single-transaction --routines --triggers --events`, a timestamped
result file outside htdocs, and SHA256. Never put a password on the command line. A successful
dump plus content checks is not a tested restore; restore verification should use a new
isolated database, not overwrite the current one. This run created a restricted TEMP dump;
its exact path/hash is in final-local-live-results.md. It does not replace the fresh persistent
backup required by your authentic activation batch.

No hosting, domains, tunnels, router forwarding, public access or deployment are needed.
