# Milestone 7 live verification

Run commands from `C:\xampp\htdocs\hunter-system` in the same non-elevated Windows
identity that owns the test configuration. Never display hunter.env, session files or private
logs. The original private directories and their ACLs are not needed or modified.

## Test access and targets

`backend/tests/fixtures/setup_m7_access.php` uses the actual token SID (`whoami /user`),
not `USERNAME`. It creates only `%TEMP%\HunterSystem-M7-Verification` and protects it
for that SID, SYSTEM and Administrators before writing fresh secrets. It refuses arbitrary
directory targets. In this Codex session the token was `codexsandboxoffline`, while
`USERNAME` was `Nikhil Modi`; Windows denied reads and ACL inspection of the old folders.
The session's workspace policy also excludes writing those old outside-workspace folders.
The allowed temporary directory resolves both needs without weakening the sandbox.

| Configuration | Database | Restricted account | Server |
|---|---|---|---|
| `seed/hunter.env` | `hunter_system_m7_seed_fixture` | `hunter_m7_seed@127.0.0.1` | 8097 |
| `complete/hunter.env` | existing `hunter_system_m3_fixture` | `hunter_m7_fixture@127.0.0.1` | 8098, 8099 |

The seed database is created once from the existing schema/seed to preserve the two-ready-topic
profile needed by student journeys. Existing databases/accounts/configs are reused without
reset or password rotation. Both accounts use the exact application grants template (57
statements), each on its fixture DB only. Root credentials are used only by CLI provisioning
and the established backend transaction/permission test harness; they are not stored in test
configs or passed to browser tests. Browser helpers use restricted config credentials.

The built-in test router refuses dev/unknown database-account pairs and non-loopback clients.
`/__test/identity` under the fixture API reports actual `DATABASE()`/`CURRENT_USER()` names,
never credentials. This endpoint does not exist in the normal Apache API. Every live runner
attests the expected server/helper identity before mutations. Browser base URLs are fixed,
and environment proxy overrides cannot silently redirect a mutating test into dev.

## Provision once; start the three test servers

```powershell
$projectRoot = (Get-Location).Path
$php = 'C:\xampp\php\php.exe'
$testPrivateRoot = Join-Path $env:TEMP 'HunterSystem-M7-Verification'
& $php backend/tests/fixtures/setup_m7_access.php
# Enter the database setup administrator password at the hidden prompt (Enter if none).
# It is never saved in either test config.

$testServers = @()
$env:HUNTER_PRIVATE_DIR = Join-Path $testPrivateRoot 'seed'
$testServers += Start-Process $php -ArgumentList '-S 127.0.0.1:8097 -t backend\tests\fixtures backend\tests\fixtures\router.php' -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
$env:HUNTER_PRIVATE_DIR = Join-Path $testPrivateRoot 'complete'
$testServers += Start-Process $php -ArgumentList '-S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php' -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
$testServers += Start-Process $php -ArgumentList '-S 127.0.0.1:8099 -t backend\tests\fixtures backend\tests\fixtures\router.php' -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
```

Do not kill an existing process just because a port is occupied. Identity attestation must
pass; a server using different configuration must be handled deliberately. Apache/MariaDB
must already be running for the app/database; no global settings need changing.

## Required suites

```powershell
& $php backend/tests/fixtures/m7_guards_test.php
$env:HUNTER_M7_ISOLATED = '1'
$env:HUNTER_PRIVATE_DIR = Join-Path $testPrivateRoot 'seed'
& $php backend/tests/admin_integration_test.php

$env:HUNTER_PRIVATE_DIR = Join-Path $testPrivateRoot 'complete'
& $php backend/tests/admin_fixture_test.php

$env:HUNTER_SEED_PRIVATE_DIR = Join-Path $testPrivateRoot 'seed'
$env:HUNTER_FIXTURE_PRIVATE_DIR = Join-Path $testPrivateRoot 'complete'
npm.cmd --prefix frontend run test:admin

$env:HUNTER_PRIVATE_DIR = $env:HUNTER_SEED_PRIVATE_DIR
npm.cmd --prefix frontend run test:browser:isolated

# Separately labelled UI simulation; not live API evidence:
npm.cmd --prefix frontend run test:admin:ui
npm.cmd --prefix frontend run build
node frontend/tests/apache-probes.mjs
```

The API integration suite's isolated mode retains all 15 checks and application permission
probes on the seed fixture. It permits labelled draft/retired fixture lessons outside topic 3
so browser-created lesson history can remain; all 16 later topics must still be draft/unready.
Default non-isolated behavior remains restricted to dev/hunter_app, with refused mutations
and fingerprint checks. For this milestone the suite was run in isolated mode, not relabelled
as an Apache/dev mutation suite.

Run the backend fixture suite before real admin Chrome to establish topic 17 at ten valid
questions and G6 at its quota. The browser then exercises actual refusal/confirmation and
restores readiness with a new `[FIXTURE]` question, never reactivating a retired question.
Lesson creation consumes one currently empty seed-fixture topic slot (4–18); no existing
lesson is deleted to make room. If no slot remains, the runner refuses rather than resets.
The existing backend fixture harness has its documented single fixture-lesson replacement
for its creation test; immutable attempts/results/audit protections remain enabled.

Real admin mode uses real API responses throughout, including server-generated pool errors,
400 artwork validation, 409 stale saves and 401 from a real backdated idle session. Network
response delay is used to verify pending controls, without faking server results. Simulated
faults remain only under `--mock`. Student regression keeps its established controlled
network/save/showcase faults and otherwise runs through actual student endpoints.

Part A student journeys use preview 4172 → seed API 8097; Part B uses preview 4173 → complete
API 8098. Both proxy identities are verified before writes. Real admin Chrome uses preview
4187 for static assets and pins each browser context to the attested fixture API. Contexts
never receive database passwords or root credentials. No course/badge publication occurs
in dev. The complete fixture is left with all 18 topics ready; test records and audit remain.

## Preservation and cleanup

Capture `CHECKSUM TABLE` for dev `topics,lessons,questions,question_choices,badges,audit_log`
before and after. No difference is acceptable. Check IR-1 row #1 and question 106 remain
unchanged. This task's comparison is in `frontend/tests/results/m7-preservation.json`.
Use new log names for future runs so earlier evidence is retained.

```powershell
# Stop ONLY the child test servers created in this PowerShell session:
$testServers | ForEach-Object { if (-not $_.HasExited) { Stop-Process -Id $_.Id } }
```

Do not delete private configs, fixture databases, audit, attempts, backups or previous logs as
test cleanup. The student badge helper retires only badges with its current run's prefix.

## Latest personally executed results

API integration **15/15**; API fixture **32/32**; real admin Chrome **58/58**; student Chrome
regression **31/31**; separate simulated admin Chrome **49/49**. Production build and Apache
protection checks pass (37 private/malformed paths, 15 SPA paths, 5 anonymous admin rejections).
Required Milestone 7 verification is complete. Full application testing,
other browsers/devices, load and final security review remain later work.
