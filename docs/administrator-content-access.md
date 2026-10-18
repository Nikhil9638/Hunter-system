# Identify your administrator and import reviewed course drafts

## Final acceptance authorization — current

Local course publication, exact six badge prices/sale and audited sample retirement are
now approved. Older “publication prohibited” notes below are historical. No repeat approval
is required. Services now run and isolated activation/journeys passed. User will handle authentic
administrator login afterward; no dev mutation occurred. A restricted TEMP logical backup was
marker/hash verified, not restore-tested; make a fresh persistent backup during activation.
See final-local-test-report.md
for current evidence and local-startup-demo.md for the current backup-first command using
`--activate-local`, reviewed foundations1–2, history/IR-1 fingerprints and explicit admin
price-approval events. Endpoint activation paths passed isolated live rehearsal; authentic
dev wrapper execution and final dev smoke remain pending. Never use fixture actor helpers on dev.

Existing TEMP fixture configs belong to the restricted tool identity. After you start
XAMPP normally, return here for fixture rehearsal rather than loosening those private ACLs
or trying to read/copy their secrets from another account. Run authentic dev password
prompts yourself in the usual authorized Windows account; never send passwords here.

## Current local-only batch — updated decisions

AI-assisted course review is at `content/review/course-review.md`; it is not human sign-off.
Names/prices are approved at total180 and sample retirements authorized. Course publication
and new badge sales remain prohibited. Deployment/external access are out of scope.
No dev import/retirement has occurred. Current MariaDB startup under the tool identity fails
with permission denied on C:\xampp\mysql\data; Apache is also not running. Start both from
your usual XAMPP Control Panel using your normal authorized Windows account. Do not weaken
private-folder or database-directory ACLs. No authentic administrator has been identified here.

After services start, fixture rehearsal must be completed before relying on the revised
importers. Use the existing complete fixture server/configuration, then from project root:

```powershell
$env:HUNTER_FIXTURE_PRIVATE_DIR = Join-Path $env:TEMP 'HunterSystem-M7-Verification\complete'
python content/tools/import_course.py revise-source
python content/tools/import_course.py apply
python content/tools/import_course.py apply
python content/tools/import_course.py verify
python content/tools/import_badges.py plan --target fixture
python content/tools/import_badges.py apply --target fixture
python content/tools/import_badges.py apply --target fixture
python content/tools/import_badges.py verify --target fixture
```

The course receipt/source update deliberately compares the entire saved server baseline.
No fixture course publication is needed for draft-import verification. The badge fixture path
attests the actual fixed8098 database/account and reuses a fixture-only actor; dev never does.
Check matching audit rows and original history before declaring these revised imports verified.
Missing samples, stale records or crash gaps stop the operation for explicit reconciliation.

If you need your own administrator, use the existing creation command below with your real
email and hidden password prompt. Once identified, the exact local batch command is:

```powershell
Set-Location C:\xampp\htdocs\hunter-system
python content/tools/local_content_batch.py --email YOUR_EMAIL --backup-dir C:\xampp\mysql_backups
```

This Python wrapper works without changing PowerShell execution policy. It prompts locally
for database backup credentials through mysqldump -p (never a plaintext argument), creates
a timestamped nonempty logical backup including triggers/routines/events outside htdocs,
records its SHA256, then authenticates each importer via hidden prompts. It creates/reuses
plans, asks you to inspect them, imports topics3–18 as drafts and six badges off sale, and
retires the four samples through the audited APIs. No price approval or sale is fabricated.
Repeat imports are no-ops only when the saved baseline still matches; conflicts stop.

The standalone course dev apply now also requires `--backup PATH_TO_LOGICAL_DUMP`.
Badge plan is `content/badges/import-dev-reviewed.json`. Do not reuse an arbitrary old backup.
The wrapper and revised badge importer have syntax/guard checks only so far; live rehearsal
is blocked in this run, not passed. It does not automatically apply foundation1–2 corrections:
compare current records with the private review snapshot, stage them as drafts through normal
admin workflows, and preserve IDs/snapshots. Publication still needs separate approval.

The older individual apply command below must include the new --backup option; the batch
command above is the current preferred path. No administrator or import was executed here.

These are instructions for you to run in a normal interactive terminal, not actions
executed during badge preparation. Never paste passwords into chat, arguments, scripts,
environment variables, command history or committed files.

## Use an existing authentic administrator

Sign in at `http://localhost/hunter-system/login` using your own account. An administrator
lands at `/admin`; a student account does not have that role. Identify the email you
actually control and use it for import attribution. Historical `example.invalid` test
administrators are not substitutes for your real identity. The importer independently
authenticates and checks the role before any content write; audit attribution uses the
authenticated administrator's database ID. Do not promote a student by editing SQL.

## Create your own administrator if needed

Use the Windows identity already authorized to read the existing dev private configuration.
The CLI defaults to `C:\xampp\hunter-system-private`; make the target explicit so a previous
fixture environment variable cannot accidentally create your account on a fixture.
Do not print or copy hunter.env. An access denial needs the correct authorized identity
or narrowly scoped access resolution, not a broad XAMPP ACL grant or routine elevation.

```powershell
Set-Location C:\xampp\htdocs\hunter-system
$env:HUNTER_PRIVATE_DIR = 'C:\xampp\hunter-system-private'
& C:\xampp\php\php.exe backend\bin\create-admin.php --email=YOUR_EMAIL --display-name="YOUR_NAME"
```

Replace only the nonsecret placeholders. The existing CLI prompts twice for a new password
without echo in an interactive terminal and applies the same 12–256 character password
policy. There is no password argument. An already registered email is rejected without
changes; choose an unused email you control for a new administrator. The command does not
convert an existing student account. Sign in through the app afterwards to confirm `/admin`.

No administrator was created as part of Milestone 8B. This command is for your deliberate
future execution; do not use browser fixture helpers to create a real account.

## Authenticate the course draft importer

After educational review and identifying your authentic administrator, run from an ordinary
interactive terminal in the project root:

```powershell
python content/tools/import_course.py plan --target dev --email YOUR_EMAIL
# Inspect the saved baseline and actor in content/import-dev.json.
python content/tools/import_course.py apply --target dev --email YOUR_EMAIL --backup PATH_TO_LOGICAL_DUMP
python content/tools/import_course.py verify --target dev --email YOUR_EMAIL
```

Each command prompts for the administrator password with Python's hidden interactive
secret prompt. Do not pipe a password or run where hidden terminal input is unavailable.
It authenticates at the fixed local Apache API, obtains CSRF/session state in memory,
and requires admin role. No database/root secret is needed. Plan refuses unexpected existing
dev content; apply compares the full saved content baseline and uses exact revisions.
Conflicts preserve the plan and stop writes; never replace the baseline just to bypass one.

This imports topics 3–18 as drafts only; it does not publish them. Publication requires
separate approval. After approval use the admin UI to review/activate each topic's questions,
publish the reviewed lesson, then make the topic available when readiness passes. Badge
names, artwork and prices require their own decisions and authenticated catalogue changes.

Course educational review, authentic dev draft import and publication approval remain pending.
