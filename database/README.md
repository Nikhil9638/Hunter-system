# Hunter System database

**Schema version:** `2026.10.14-3` · **Target:** MariaDB 10.4.32 (XAMPP), InnoDB, `utf8mb4_unicode_ci`, UTC timestamps · **Database:** `hunter_system_dev` (the pre-existing `hunter_system` and `hunter_system_test` are left untouched)

| File | Purpose |
|---|---|
| `schema.sql` | Fresh install: 35 tables, 34 triggers, 8 views. Installs only into an **empty** database and refuses otherwise. No DROP, TRUNCATE or DELETE. |
| `seed.sql` | Reference data, 18 topics, **approved** policies (version 2) with the provisional v1 rows kept as `retired`, the rank progression policy, lessons and 12-question pools for topics 1–2, 24 assessment questions, and 4 sample badges (`draft`, provisional prices, not for sale). No users or student data. Safe to re-run: it inserts only rows whose stable ID is missing and never overwrites edits. |
| `migrations/002_v2_simplify_and_approvals.sql` | Upgrades an existing `2026.10.14-1` database to `-2` and keeps its records. A guard block checks every precondition before any change. |
| `migrations/003_auth_throttle_readiness_badges.sql` | `-2` → `-3`: adds `login_throttle`; readiness counts only valid questions; a badge goes on sale only with an approved price, and a price change withdraws approval. The guard refuses if any `on_sale` badge has an unapproved price. |
| `accounts/hunter_app_grants.sql` | The exact privileges of the API's restricted account `hunter_app@127.0.0.1`, applied by `backend/bin/setup-db-account.php` after a full REVOKE. |
| `verify.sql` | Read-only schema and seed checks, then integrity tests in one transaction that is **rolled back**. Prints PASS, FAIL or INFO per check. |
| `tests/transactions_check.php` | Command-line PDO harness for the designed PHP transactions, including real multi-process races. It commits records, so it **only runs against a disposable database whose name ends in `_verify`**. |

Design and reasons: `docs/database-design.md`.

> **Always use `--abort-source-on-error`** with `-e "source file.sql"`. Without it, the `mysql` client keeps executing after an error, so a failed guard block would not stop the rest of the file. This was discovered while rehearsing migration 002; the earlier versions of this README omitted the flag. (Running `mysql ... < file.sql` in cmd stops at the first error by default.)

```powershell
$MYSQL = "C:\xampp\mysql\bin\mysql.exe"
$SRC   = "C:/xampp/htdocs/hunter-system/database"
```

`-p` prompts for the password; press Enter if the account has none.

---

## A. Fresh install (new, empty database)

```powershell
& $MYSQL -u root -p -e "CREATE DATABASE hunter_system_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& $MYSQL --abort-source-on-error -u root -p hunter_system_dev -e "source $SRC/schema.sql"
& $MYSQL --abort-source-on-error -u root -p hunter_system_dev -e "source $SRC/seed.sql"
```

`CREATE DATABASE` deliberately fails if the name is taken, and `schema.sql` refuses to run in a database that already has tables.

## B. Upgrade an existing database (`-1` → `-2` → `-3`)

1. **Logical backup**, including triggers and routines, then record a checksum:

   ```powershell
   $B = "C:\xampp\mysql_backups\logical_hunter_system_dev_$(Get-Date -Format yyyyMMdd_HHmmss)"; New-Item -ItemType Directory $B | Out-Null
   C:\xampp\mysql\bin\mysqldump.exe -u root -p --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --databases hunter_system_dev --result-file="$B\hunter_system_dev.sql"
   Get-FileHash "$B\hunter_system_dev.sql" -Algorithm SHA256
   ```

2. **Migrate** in order. Each migration's guard refuses a database at the wrong version:

   ```powershell
   & $MYSQL --abort-source-on-error -u root -p hunter_system_dev -e "source $SRC/migrations/002_v2_simplify_and_approvals.sql"   # only for -1
   & $MYSQL --abort-source-on-error -u root -p hunter_system_dev -e "source $SRC/migrations/003_auth_throttle_readiness_badges.sql" # -2 -> -3
   ```

   Running it again is refused (`database is not at schema 2026.10.14-1; nothing changed`).

3. **Verify** (section C).

MariaDB DDL commits as it goes, so the migration isn't atomic. That's why all checks run before the first change, and why the backup comes first. The rollback, if ever needed, is to restore the dump into a new database.

## C. Verify

```powershell
& $MYSQL --abort-source-on-error -u root -p --table hunter_system_dev -e "source $SRC/verify.sql"
```

Expect `passed 65`, `failed 0`, `info 2`. Check C02 confirms that all 43 Part B tests reported. The warning `Some non-transactional changed tables couldn't be rolled back` is expected, because the results table is deliberately non-transactional.

## D. Concurrency check of the PHP transactions (throwaway database only)

This commits test records, including test-only content that makes topic 3 ready. The cleanup is dropping the whole database.

```powershell
& $MYSQL -u root -p -e "CREATE DATABASE hunter_system_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& $MYSQL --abort-source-on-error -u root -p hunter_system_verify -e "source $SRC/schema.sql"
& $MYSQL --abort-source-on-error -u root -p hunter_system_verify -e "source $SRC/seed.sql"
# If root has a password: $env:HUNTER_DB_PASSWORD = "<password>"   (never on the command line or in a file)
C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\database\tests\transactions_check.php --db=hunter_system_verify --port=3306
# when no longer needed:
& $MYSQL -u root -p -e "DROP DATABASE hunter_system_verify"
```

Sample badges stay `draft` (approved A-05: not purchasable until their prices and artwork are approved). No administrator account is seeded and no password is hardcoded; the first administrator is created with the CLI command described in `docs/architecture.md`.

---

## Results: schema 2026.10.14-3 and API milestone 1 (2026-10-14)

| Run | Result |
|---|---|
| Fresh `-3` install (isolated instance) | 35 tables, 8 views, 34 triggers; `verify.sql` **65 PASS, 0 FAIL, 2 INFO** |
| Migration 003 on a restored copy of `hunter_system_dev` (`-2`) | Structure **identical to fresh `-3`** (645-line comparison); `verify.sql` 65/0/2 |
| Migration 003 guard on a database with on-sale badges at provisional prices | Refused, nothing changed (that throwaway database, the isolated `hunter_system_verify`, stays at `-2`) |
| `transactions_check.php` on a fresh `-3` throwaway database | **22 PASS, 0 FAIL** (test badges now inserted with an approved price) |
| XAMPP `hunter_system_dev`: logical backup, then migration 003 | Backup `C:\xampp\mysql_backups\logical_hunter_system_dev_20261014_155057\hunter_system_dev_schema_2026.10.14-2.sql`; structure identical to fresh `-3`; `verify.sql` **65/0/2** (also after the API tests: 15 wallets reconciled, no throttle row older than 24 h) |
| Restricted account `hunter_app@127.0.0.1` | Exactly the 10 grants in `accounts/hunter_app_grants.sql`; 14 forbidden operations refused (integration test D1) |
| API integration tests (`backend/tests/auth_integration_test.php`, live through Apache) | **41 PASS, 0 FAIL** (see `docs/progress.md`) |

`hunter_system_dev` now contains test accounts (`*@example.invalid`, created by the integration tests and smoke checks). Their users, sessions and login events are append-only history.

## Results: schema 2026.10.14-2 (2026-10-14)

### Rehearsal on an isolated instance (MariaDB 10.4.32, separate data directory, port 3307)

| Run | Result |
|---|---|
| Fresh `schema.sql` + `seed.sql` | 34 tables, 8 views, 33 triggers; seed inserted all rows; re-run inserted 0; schema re-run refused and left the database unchanged |
| Logical backup of XAMPP `hunter_system_dev` (`-1`) restored as a copy | All 34 tables identical by `CHECKSUM TABLE` |
| First rehearsal of migration 002 | **Exposed two defects** (since fixed): a syntax error in the guard block, and the client continuing past it without `--abort-source-on-error`. The tainted copy was dropped and restored again. |
| Guard tests | Refused on a `-2` database and on a second run, with the structure unchanged |
| Migration on the restored copy | Structure **identical to a fresh `-2` install** (630-line comparison of columns, indexes, FKs, CHECKs, trigger bodies and views); policy and content data identical (52 rows and content hashes) |
| Migration on a `-1` database **with records** (8 users, 17 attempts, 14 ledger entries) | 126 stored evidence rows equalled the derivation before the migration; the derived views reproduce them, and the 126 stored progress-statistic rows, after it. Untouched tables were checksum-identical (only the policy tables gained v2 rows). Structure identical to fresh `-2`. |
| `verify.sql` on four isolated databases | 61 PASS, 0 FAIL, 2 INFO each |
| `transactions_check.php` | **22 PASS, 0 FAIL** |

### XAMPP server (port 3306)

| Run | Result |
|---|---|
| MariaDB start (`mysql_start.bat`) | `ready for connections`, no errors, no Aria recovery needed |
| `hunter_system_dev` before the change | Seed and reference data only (0 users, attempts, ledger, sessions); unchanged since the logical backup (34 checksums identical); backup SHA-256 re-verified |
| Logical backup | `C:\xampp\mysql_backups\logical_hunter_system_dev_20261014_151817\hunter_system_dev_schema_2026.10.14-1.sql` (34 tables, 39 triggers, 4 views; SHA-256 recorded alongside) |
| Migration 002 on `hunter_system_dev` | Completed; structure identical to a fresh `-2` (630 lines); policy and content data identical |
| `verify.sql` on `hunter_system_dev` | **61 PASS, 0 FAIL, 2 INFO** |
| `hunter_system_verify` (throwaway, with records) | Backed up to the same folder, migrated (8 users, 17 attempts, 14 ledger entries kept; 126 evidence rows now derived) |
| `transactions_check.php` on `hunter_system_verify` | **22 PASS, 0 FAIL** |
| `verify.sql` on `hunter_system_verify` | 61 PASS, 0 FAIL, 2 INFO |

**Answer save racing final submission (T6):**
- **T6a:** the answer held its locks and the submit waited, then graded the changed answer (4/5).
- **T6b:** the submit went first, the late answer was refused with `attempt_finalized`, and the stored answers were unchanged.
- **T6c:** 6 unheld races; both orders were observed, every outcome was consistent, and no answer is timestamped after its submission.

**Other races:** simultaneous starts (T4), double submits (T2, plus T9 with 5 unheld rounds), and two purchases (T7 different badges, T8 the same badge) all behaved correctly.

**Rank progression (T11, B27):**
- An E-placed student passing 3 topics is promoted to D with evidence and policy version.
- A retake changes nothing.
- A C-placed student who earns only D keeps C.
- The database refuses wrong bands, wrong counts, promotions citing a failed quiz, and any lowering of rank.

### Earlier results (schema `-1`, kept for history)

`verify.sql` 52/0/3 and `transactions_check.php` 15/0 on the temporary instance and on XAMPP. The 40 Python checks of code-based answers all matched.

## Not tested

- Load or performance with many students.
- Session expiry, activity throttling and login throttling as running PHP code (the table rules are tested; the PHP doesn't exist yet).
- Admin content-management transactions and audit writes (the structure exists; the endpoints are designed in `docs/api-contract.md`).

## Remaining content work

- Lessons and at least 10 quiz questions for each of topics 3–18 (currently `draft`).
- Your review of the seeded lessons, questions and explanations for topics 1–2 and the assessment pool.
- Badge names, artwork and prices.

---

## Appendix: XAMPP MariaDB recovery record (2026-10-14)

**Status: recovered.** MariaDB 10.4.32 starts through XAMPP, accepts connections, and phpMyAdmin connects.

### Confirmed cause

Every start since at least 07:34 on 2026-10-14 logged:

```
Cannot find checkpoint record at LSN (1,0x55da)
[ERROR] mysqld.exe: Aria recovery failed. Please run aria_chk -r on all Aria tables and delete all aria_log.######## files
[ERROR] Plugin 'Aria' registration as a STORAGE ENGINE failed.
[ERROR] Could not open mysql.plugin table. Some plugins may be not loaded
[ERROR] Aborting
```

`aria_log_control` was readable and pointed to checkpoint LSN `(1, 0x55da)`, a record that `aria_log.00000001` didn't contain in readable form. Aria crash recovery therefore failed and the Aria engine never registered, so MariaDB's 24 Aria system tables in `data\mysql\` (including `mysql.plugin`) couldn't be opened. InnoDB (the project data) started normally every time. `aria_chk -c` also flagged `global_priv` and `roles_mapping` with size mismatches.

**Probable trigger (not proven):** an unclean stop. `aria_log_control` was last written on 2026-10-13 at 21:59, and XAMPP's `mysql_stop.bat` kills `mysqld.exe`.

### Backup (made and verified before any change)

`C:\xampp\mysql_backups\pre_aria_repair_20261014_105604\`

| Item | Content |
|---|---|
| `data\` | Full robocopy of `C:\xampp\mysql\data` with no `mysqld` running: 174 files, 95,792,363 bytes, 0 failed; every file matched by path, size and SHA-256 |
| `inventory_sha256.csv` | Hash inventory of the original data directory |
| `config\bin_my.ini`, `mysql_error.log.original` | Configuration and the original error log |
| `moved_from_live\aria_log.00000001` | The damaged Aria log moved out of the live directory |
| `diagnosis\aria_chk_check.log`, `repair_live.log`, `robocopy.log` | Output and exit code of every command |

### Repair (server stopped; rehearsed first on copies)

Repairing only the two flagged tables wasn't enough in rehearsal: `mysql.proxies_priv` was reported corrupt after a restart. The procedure that passed four clean restart cycles, then applied live:

1. Confirmed no `mysqld` process and nothing on port 3306. Confirmed the live Aria log and control file still matched the backup.
2. Moved `aria_log.00000001` aside. **Kept `aria_log_control`**: it was readable, with no evidence of corruption.
3. `aria_chk --zerofill` on all 24 tables: 24/24 exit 0.
4. `aria_chk -r` on all 24: 23/24 exit 0. `columns_priv` (0 rows) needed `-o` (safe-recover), which exited 0.
5. `aria_chk -c` on all 24: clean.

Not done: no change to `ibdata1`, the InnoDB logs or project folders; no `innodb_force_recovery`; no replacement of `data\` or the `mysql` database; no reinstall.

### Results

- **Start:** `mysql_start.bat` reached `ready for connections`. The one expected error was "aria_log.00000001 not found"; MariaDB then created a new log.
- **Restart:** a graceful restart logged 0 errors, and the server was still running 60 seconds later.
- **Connections:** SQL and phpMyAdmin 5.2.1 both connect.
- **Existing data:** table names match the backup (`hunter_system` 8/8 with 13 triggers, `mysql` 31/31, `phpmyadmin` 19/19). `CHECK TABLE` on 125 tables: 0 not OK.

### Notes

- Stop MariaDB gracefully: `C:\xampp\mysql\bin\mysqladmin.exe -u root -p shutdown`.
- This build's `mysql_error.log` loses its last lines when `mysqld` exits. Run `mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --console` to see a start-up failure.
- Keep the backup until you're satisfied.
