# Hunter System: Architecture

**Last updated:** 2026-10-15

Final acceptance update: existing Apache/MariaDB restored by owner; tests reuse SID-protected
TEMP seed/complete configs with fixed loopback API identity attestation. Production API/client
and serving configuration unchanged this resumed run. `admin-journeys.mjs --full-content`
preserves reviewed pools and restores lesson18 via audited edit; legacy boundary checks remain
separate. `final-concurrency.mjs` uses two real fixture servers and audited temporary badges,
without privilege changes. Explicit fixture repair/reconciliation tools retain retired rows and
require matching authored content; normal importer stale checks remain enabled. Dev activation
deferred by user. See final-local-live-results.md for results and Aria provisioning limitation.

**Current frontend status:** student screens and Milestone 7 administrator screens are
implemented and Milestone 7 verification is complete. Administrator implementation reuses
the existing client/auth/shell; isolated test configuration preserves original private ACLs.
Historical "future" statements
below describe earlier milestones; current behavior is in §5.14 and `codex-handoff.md`.
**Status:**
- **Milestone 1 (implemented and tested):** Apache protection, backend structure, restricted database account, authentication, sessions, CSRF, login throttling, the first-admin CLI.
- **Milestone 2 (implemented and tested):** onboarding, the placement assessment, the dashboard and the topic list.
- **Milestone 3 (implemented and tested):** lessons, topic quizzes, first-completion rewards and the coin ledger, next-topic unlock, rank promotion.
- **Milestone 4 (implemented and tested):** badge store, purchases, own profile and showcase.
- **Milestone 5 (implemented and tested):** administrator content management (topics, lessons, questions, badge catalogue and price approval), audit history, remaining student-tracking endpoints, the public badge-artwork path.
- **Milestone 6 (implemented and tested):** the student React interface (`frontend/`), built into `frontend/dist` and served by Apache under `/hunter-system/`.
- **Milestone 7 (implemented and verified):** real API suites 15/15 and 32/32; real admin browser 58/58; full student browser regression 31/31; separate simulated admin checks 49/49. Initial read-only Chrome evidence remains 21/21.
**Stack (C-02):** React (browser) · PHP 8.2.12 (API) · MariaDB 10.4.32 (schema `2026.10.14-3`) · Apache 2.4.58, all through XAMPP on Windows.

Companion documents: `api-contract.md` (endpoints), `database-design.md` (tables, transactions, lock order), `decisions.md` (approvals and choices).

---

## 1. Overview

```
 Browser                          Apache (XAMPP :80 / :443)                       MariaDB 10.4 (:3306)
┌──────────────┐  same origin    ┌─────────────────────────────────────┐   PDO   ┌───────────────────────┐
│ React SPA     │ ──────────────▶ │ /hunter-system/        → frontend/   │         │ hunter_system_dev     │
│ (future)      │  JSON + cookie  │   dist (when built)                  │ ──────▶ │  as hunter_app        │
│               │                 │ /hunter-system/api/v1/* →            │         │  (least privilege)    │
└──────────────┘                 │   backend/public/index.php (only PHP)│         └───────────────────────┘
                                  │ everything else → 403 / 404          │
                                  └─────────────────────────────────────┘
                 private, outside htdocs:  C:\xampp\hunter-system-private\ (hunter.env, sessions\, logs\)
```

- **Local API URL:** `http://localhost/hunter-system/api/v1` (also `https://localhost/hunter-system/api/v1` with XAMPP's self-signed certificate).
- **One origin.** React and the API share the Apache host, so the session cookie is `SameSite=Strict` and no CORS is needed.
- **PHP decides; the database backstops.** PHP makes every decision about scores, unlocks, ranks, rewards and prices (C-27). The database refuses anything that breaks an integrity rule.

## 2. Folder structure (as implemented)

```
C:\xampp\
├── htdocs\hunter-system\
│   ├── .htaccess                    # routing + deny-by-default + security headers (§3)
│   ├── backend\
│   │   ├── .htaccess                # Require all denied (second layer)
│   │   ├── public\
│   │   │   ├── .htaccess            # Require all granted (the only public folder)
│   │   │   └── index.php            # front controller - the ONLY PHP reachable over HTTP
│   │   ├── src\                     # namespace Hunter\, own autoloader (bootstrap.php), no framework
│   │   │   ├── App.php              # wiring, routes, request pipeline, error handling
│   │   │   ├── Http\                # Request, Response, Router, ApiError
│   │   │   ├── Controllers\         # AuthController, HealthController, StudentController (M2), LearningController (M3),
│   │   │   │                        # BadgeController (M4),
│   │   │   │                        # Admin\StudentController
│   │   │   ├── Services\            # AuthService, SessionManager, LoginThrottle, AdminTrackingService, AuthContext,
│   │   │   │                        # OnboardingService, AssessmentService, DashboardService (M2), LearningService (M3),
│   │   │   │                        # BadgeService (M4)
│   │   │   ├── Repositories\        # UserRepository, SessionRepository, ThrottleRepository, AdminOverviewRepository,
│   │   │   │                        # StudentRepository, AssessmentRepository (M2), QuizRepository (M3), BadgeRepository (M4)
│   │   │   │                        # - PDO prepared statements, explicit columns
│   │   │   ├── Db\Database.php      # PDO setup, tx()
│   │   │   └── Support\             # Config, Paths, Text (Unicode), PasswordPolicy, Logger, Console, Time
│   │   ├── bin\                     # CLI only: setup-db-account.php, create-admin.php, purge-expired.php
│   │   ├── config\hunter.env.example  # configuration template (no secrets)
│   │   └── tests\                  # auth_integration_test.php (M1), assessment_integration_test.php (M2),
│   │                                # quiz_integration_test.php + quiz_fixture_test.php (M3),
│   │                                # badge_integration_test.php + badge_fixture_test.php (M4), TestSupport.php, QuizSupport.php,
│   │                                # fixtures\ (setup_m3_fixture.php, router.php for the built-in server)
│   ├── database\                    # .htaccess deny; schema, seed, migrations, verify, accounts\hunter_app_grants.sql
│   ├── assets\                      # .htaccess deny; assets\badges\ serves *.png / *.webp only (artwork, §3)
│   ├── docs\                        # .htaccess deny
│   └── frontend\                    # .htaccess deny (frontend\dist will be served when it exists)
└── hunter-system-private\           # OUTSIDE the web root; ACL: current user, SYSTEM, Administrators
    ├── hunter.env                   # DB credentials, THROTTLE_KEY (generated, never printed)
    ├── sessions\                    # PHP session files (own folder: no shared garbage collection)
    └── logs\app.log                 # JSON-lines application log
```

Private code under `backend/` is inside `htdocs` but is unreachable (§3, verified). Secrets, sessions and logs are **outside** `htdocs` entirely.

## 3. Apache protection (implemented, verified live)

Before this milestone, with Apache running, `http://localhost/hunter-system/database/seed.sql` returned **200** with every correct answer, and `/docs/` and `/database/` returned directory listings. XAMPP's `httpd.conf` grants all of `htdocs` (`Require all granted`, `Options Indexes`, `AllowOverride All`, `Listen 80`).

Now `/hunter-system/.htaccess`:

1. Sets `Options -Indexes -Includes -ExecCGI` and `DirectoryIndex disabled`.
2. Rewrites `api/v1(/…)` to `backend/public/index.php` with `[END]`.
3. Serves badge artwork `assets/badges/<name>.png|webp` (lower-case letters, digits, hyphens; no sub-folders) **only if the file exists** (Milestone 5).
3b. **Student app (Milestone 6), allowlisted, only when a build exists:** `static/<hashed>.js|css|svg|png|webp|woff2` and `favicon.svg` → `frontend/dist/…` (file must exist); the SPA fallback `frontend/dist/index.html` **only** for the project root and the app's route prefixes (`login, register, start, assessment, dashboard, topics, quiz, history, store, profile, admin, admin-pending`, plus sub-paths of `[A-Za-z0-9_-]` segments). The Milestone-1 catch-all (any existing file in `dist`, any other path → `index.html`) was replaced, because once a build exists it would have answered private URLs such as `/database/seed.sql` with 200 (the app page). Keep the route list in step with `frontend/src/App.jsx`.
4. Sends **404** for everything else.
5. Refuses everything (`Require all denied`) if `mod_rewrite` is missing.
6. Sets these headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`.

**Second layer:** `backend/`, `database/`, `docs/`, `frontend/` and `assets/` each have `Require all denied`; `backend/public/` has `Require all granted`. `frontend/dist/.htaccess` (generated from `frontend/public/.htaccess` on every build) grants only `index.html`, `favicon.svg` and hashed `*.js|css|svg|png|webp|woff2`, sets `SetHandler none` / `php_flag engine off`, `Cache-Control: no-cache` for `index.html` and one-year `immutable` caching for hashed files. The site-wide CSP (`default-src 'self'`) needed no change: the build has no inline script and uses no external fonts or scripts.

**Verified after Milestone 6 (live):** 15 app routes (including deep links such as `/topics/1/lesson`, `/quiz/12`) return the app page; the hashed bundle is served as `text/javascript`; 29 private or malformed URLs return 403/404 (SQL, docs, PHP source and tests, `frontend/src`, `package.json`, `package-lock.json`, `node_modules`, `vite.config.js`, `frontend/dist/index.html` directly, `.htaccess` files, `static/` listing, `..` traversal, unknown paths such as `/xyz`, `/index.html`, `/package.json`). The auth suite's P1 now covers 24 private paths plus P1b (the root serves exactly the built `index.html`).

**Badge artwork path (Milestone 5):** `assets/badges/.htaccess` denies everything except files named `^[a-z0-9][a-z0-9-]{0,62}\.(png|webp)$`, sets `SetHandler none` and `php_flag engine off` (nothing is ever executed), forces `image/png` / `image/webp`, and adds `Content-Security-Policy: default-src 'none'` and `nosniff`. Public URL: `/hunter-system/assets/badges/<file>`. Verified live with temporary probe files (then deleted): a PNG → 200 `image/png`; a `.png` file containing PHP code → served as bytes, **not executed**; `.php`, `.svg`, `README.txt`, `.htaccess`, listings, upper-case names and encoded traversal → 403/404. The API only accepts `image_ref` values matching the same pattern (`badges/<name>.png|webp`) or `null`. No upload endpoint exists; files are placed by the developer. SVG is excluded because it can carry script.

**Verified (test P1):** 22 private paths return 403 or 404. They include SQL files, docs, code, config, CLI scripts, tests, the `.htaccess` files, folder listings, the direct front-controller path and the project root. `/api/v1/health` returns 200. `.htaccess` is honoured, so no `httpd.conf` change was needed.

## 4. Responsibilities

| Layer | Owns | Never does |
|---|---|---|
| React (future) | Screens, navigation, showing server results | Calculating scores, ranks, rewards or prices; storing tokens in `localStorage` |
| `App` (pipeline) | Route match → Origin and content-type checks → readiness check (`intl`, Argon2id) → throttle purge → authentication and role → CSRF → controller | Business rules |
| Controllers | Parse input (unknown JSON keys rejected), call one service, shape JSON | SQL, rules |
| Services | Rules and transactions; lock order student → attempt → others (later milestones) | Trust IDs or roles from the browser |
| Repositories | Parameterized SQL through PDO (native prepares) | Build SQL from user input |
| MariaDB | Integrity backstops (FKs, CHECKs, 34 triggers), derived views | Decisions PHP must make |
| Apache | Static files, routing, deny rules, headers | Running PHP other than the front controller |

**Error handling:**
- Expected errors use `ApiError`, which carries an HTTP status and a stable code.
- Anything unexpected is logged to `logs\app.log` with a random `error_id` and returned as `500 internal_error` without internals.
- PHP warnings become exceptions, and `display_errors` is off for API requests.

## 5. Authentication and sessions (implemented)

### 5.1 Accounts

- **Email** is the login identifier: trimmed, Unicode **NFC**, lower-cased, at most 254 characters, validated with `FILTER_VALIDATE_EMAIL` plus `FILTER_FLAG_EMAIL_UNICODE`. The database also enforces lower-case and uniqueness.
- **Display name:** NFC and trimmed, 1–80 characters, no control or format characters.
- **Registration always creates `role='student'`.** A `role` field (or any unknown field) is rejected with `400 unknown_field`. Administrators come only from the CLI (§5.7).

### 5.2 Passwords (A-07, D-13, D-17)

| Rule | Implementation (`Support/PasswordPolicy.php`) |
|---|---|
| Unicode normalization | **NFKC** (`Normalizer::FORM_KC`, PHP `intl`, now enabled in `C:\xampp\php\php.ini`) before counting, hashing and verifying, at registration, CLI creation and login alike. Composed and decomposed forms match; full-width and other compatibility characters fold to their standard forms. |
| Length | 12–256 characters **and** at most 1,024 bytes, after normalization. Longer input is **rejected** (`too_long`), never cut. Invalid UTF-8 is rejected. |
| Content | Spaces and any Unicode allowed; no composition rules; never trimmed. |
| Hash | **Argon2id** (`PASSWORD_ARGON2ID`, PHP defaults m=64 MiB, t=4, p=1, about 270 ms on this PC). bcrypt is never used: this PHP's default bcrypt verified a 72-byte prefix with a different tail. |
| Rehash | `password_needs_rehash()` on successful login. |
| Existing hashes | When normalization was introduced, `hunter_system_dev` had **0 accounts**, so no existing hash was created without it. |
| Unknown email | `password_verify` runs against a dummy Argon2id hash, so response time doesn't reveal whether the account exists. |

### 5.3 Session cookie

| Setting | Value |
|---|---|
| Name and flags | `HSSESSID`, `HttpOnly`, `SameSite=Strict`, `Path=/hunter-system/` |
| `Secure` | `COOKIE_SECURE=auto` sets it only when the request is HTTPS (tested: HTTP no, HTTPS yes). Use `true` in production. |
| PHP settings | `use_strict_mode=1`, `use_only_cookies=1`, `use_trans_sid=0`, `sid_length=48`, `sid_bits_per_character=6` |
| Storage | Session files in `hunter-system-private\sessions` (outside the web root, not shared with phpMyAdmin), `gc_maxlifetime=86400`. The app itself enforces the 30-minute idle limit; PHP's default 24-minute garbage collection would otherwise cut sessions short. |
| Regeneration | `session_regenerate_id(true)` at every login and registration. An attacker-chosen ID is never accepted (strict mode plus a file-existence check). A login sends two `Set-Cookie` headers (start, then regenerate); the browser keeps the last, and the first session is deleted server-side. |
| Contents | `$_SESSION` holds only `user_id`, `role`, `tracking_token` and `csrf_token`. Authenticated requests open it read-only (`read_and_close`), so no file lock is held. |

### 5.4 Session tracking, idle expiry, activity (C-21 to C-23, A-06)

| Event | What happens |
|---|---|
| Login or registration | One transaction inserts the `user_sessions` row (`session_ref_hash = SHA-256(tracking_token)`, the current `app_policy_id`) and a `login_events` success row. Then the PHP session is regenerated. If this browser already had an open tracked session, it's ended as `logged_out`. |
| Every authenticated request | Look up the row by hash, with idle and touch flags computed by the **database clock** under the session's own policy. Then: ended or missing → `401 session_ended`; idle for over 30 minutes → set `ended_at = last_activity_at + 30 min`, `end_reason='expired'`, return `401 session_expired`; account not active → end as `ended_by_admin`, return 401. The role always comes from the database. If `last_activity_at` is more than 60 s old, it's updated. |
| Logout | The row ends as `logged_out`, the PHP session file is destroyed and the cookie is cleared. Replaying the old cookie gives 401 (tested). |
| Closed browser | Lazy expiry on the next request. The admin list also runs a sweep, as does `bin/purge-expired.php`. |
| Failed login | `login_events`: `wrong_password` or `account_deactivated` (user known), or `unknown_account` (no identity). No typed email, IP or password is stored anywhere (tests L5 and L6). |

The PHP session ID is never stored; only the hash of a separate random token is (test R4).

### 5.5 CSRF and request rules

- **CSRF token:** `logout`, and every future state-changing endpoint for logged-in users, needs `X-CSRF-Token` to match the session token. A mismatch gives `403 csrf_failed`. The token comes from `register`, `login` and `GET /auth/me`.
- **Origin:** every non-GET request with an `Origin` header must come from `APP_ORIGINS` (`http(s)://localhost`, `http(s)://127.0.0.1`); otherwise `403 csrf_failed`. This also blocks cross-site login and registration.
- **Content type:** a body must be `application/json` (otherwise `415`). A browser can't send JSON cross-site without a CORS preflight, and the API never approves one.
- **Limits:** bodies are capped at 64 KB (`413`), and JSON nesting at depth 8.

### 5.6 Login throttling (O-02 approved, implemented)

`login_throttle` (migration 003) stores `key_hash = HMAC-SHA256(THROTTLE_KEY, 'email:'+normalized email)` and `HMAC-SHA256(THROTTLE_KEY, 'ip:'+REMOTE_ADDR)`. `THROTTLE_KEY` (32 random bytes) lives in `hunter.env`, outside the web root. Proxy headers aren't trusted.

| Setting (env override) | Default |
|---|---|
| Email key: failures before lock (`THROTTLE_EMAIL_MAX_FAILURES`) | 5 in a 15-minute window |
| IP key: failures before lock (`THROTTLE_IP_MAX_FAILURES`) | 30 in a 15-minute window |
| Lock (`THROTTLE_LOCK_SECONDS`) | 900 s; while locked, every login for that key returns `429 too_many_attempts` with `Retry-After`, **without checking the password** |
| Retention (`THROTTLE_RETENTION_SECONDS`, capped at 86,400) | Rows are deleted **24 h after creation at the latest**, even if locked, and earlier once their window has passed and they aren't locked. Purged on every API request and by `bin/purge-expired.php`. |

A successful login clears that email's key; the IP key keeps counting. Registration isn't throttled in this milestone (see `progress.md`).

### 5.7 First administrator (CLI only)

```
C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\bin\create-admin.php --email=admin@example.org --display-name="Course Admin"
```

- **Password entry:** it asks twice, with no echo (PowerShell `Read-Host -AsSecureString`, UTF-8). It never accepts the password as an argument or environment variable.
- **Policy:** the same password policy as registration (NFKC, length limits, Argon2id).
- **Refusals:** a duplicate email, mismatched repeats and policy failures.
- **Records:** the creation goes to `app.log` (`audit_log` requires an acting admin).
- **Web access:** reachable over HTTP? No: 403 from Apache, and `PHP_SAPI` must be `cli` anyway.
- **Automation:** when standard input is piped rather than a terminal (automated tests), it reads two lines from STDIN.

### 5.8 Authorization

Routes are tagged `public`, `any`, `student` or `admin`. The role is read from the database on every request; the wrong role gets `403 forbidden`, and no session gets `401`. Admin responses never include password hashes, session hashes or coin balances.

### 5.9 Placement assessment transactions (Milestone 2)

All three write paths take the **same lock order** as `database-design.md` §11: `student_profiles` row (`FOR UPDATE`), then the `attempts` row (`FOR UPDATE`, only if it's this student's assessment), then other rows. Each is one `Database::tx()`, so any failure rolls back everything. Expected refusals become `ApiError`s; anything else is logged and returns `500 internal_error`.

| Flow | Steps |
|---|---|
| Start | Lock profile → refuse if a placement exists or no experience is chosen → return an open attempt if there is one (resume) → pick 10 valid questions by group quota (`random_int` shuffle) → insert the attempt, then snapshot each question and **all** its choices. If a duplicate-key error still occurs, the winner's attempt is resumed. |
| Answer | Lock profile → lock attempt → `409 attempt_finalized` if submitted → the position must exist → the choice must belong to that assigned question → update the selection. |
| Submit | Lock profile → lock attempt → if already submitted, return the stored result (no writes) → `409 attempt_incomplete` with the unanswered positions → **grade in PHP** from the snapshot's correct flags and write `is_correct` → placement band → recommendation (earliest missed group's first topic; all correct → 18) → offered topic (nearest earlier ready topic if the recommendation isn't ready) → insert the result → mark submitted (the trigger re-checks count, answers and sum) → placement `rank_history` → profile placement and current rank → 18 progress rows (unlocked up to the recommendation). |

**What a student can see:**
- **Before submission:** responses are built from `v_attempt_choices_public` and explicit columns only. No correct flags, explanations or source IDs (test A3/A5 scans every key).
- **After submission:** the result includes the snapshot review (O-08).

**How races were tested over HTTP:** the integration test holds the student's profile-row lock from a database-admin connection and sends two requests 300 ms apart, then releases the lock. InnoDB's first-come-first-served lock queue then fixes which request runs first. No test hook exists in production code.

### 5.10 Lessons, quizzes, rewards and rank (Milestone 3)

`LearningService` reuses the snapshot, answer and grading helpers of `AssessmentRepository` (they are kind-agnostic) plus `QuizRepository`. Every write path takes the same lock order: profile row, then this student's `topic_quiz` attempt row, then other rows. Each is one `Database::tx()`.

**Topic access** (lesson, quiz start): placement done → topic exists → unlocked for this student (`student_topic_progress.unlocked_at`) → content ready (`v_topic_readiness.is_ready`). These are separate checks with separate errors, so an unlocked topic with no content says so instead of starting an incomplete quiz. A lesson read only updates the visit columns.

| Flow | Steps |
|---|---|
| Start | Validate `started_via` → lock profile → access checks → resume the open attempt if one exists → pick 5 distinct valid quiz questions: unseen in this topic first, then seen, `random_int` shuffle within each set → insert the attempt (`attempt_number` + 1) → snapshot each question and all its choices. A duplicate-key error on `uq_attempts_one_open` resumes the winner's attempt. |
| Answer | Lock profile → lock attempt → `409 attempt_finalized` if submitted → position must exist → choice must belong to it → update. |
| Submit | Lock profile → lock attempt → already submitted: return the stored result, **no writes** → `409 attempt_incomplete` → grade in PHP from the snapshot, write `is_correct` → `quiz_results` with the current grading policy id and band → mark submitted (trigger re-checks count, answers, graded, sum = result) → **if passed:** unlock the next topic by position if still locked (`passed_previous`; none after topic 18) and apply rank progression → **if no claim for this topic:** `reward_claims` + `coin_ledger` credit with `balance_after` = locked balance + amount (trigger verifies it and updates the cached balance). |

**Rank progression:** after a pass, the distinct passed count (`v_student_topics_passed`, which already includes this attempt) selects the earned band of the current rank policy. A promotion row is written only if the earned rank sorts above the **current** rank, which already includes the placement floor and every earlier promotion; then `current_rank_id` follows. The database independently checks the chain (`from` = current), that the rank rises, that the evidence is a passed quiz of this student and that `topics_passed` is the true distinct count inside the cited band.

**Concurrency:** different-topic submissions of one student serialize on the profile row, so the second sees the first's balance and rank (tested over two servers: ledger chain unbroken, exactly one promotion). Duplicate submits queue on the same locks and return the stored result. Answer and submit serialize in arrival order.

**Read model:** `QuizResult` reports only this attempt's own facts, read back from rows written in its transaction: the claim and ledger row (`reward`), the unlock source of the next topic if this attempt is the first pass (`unlocked_topic`), and the rank-history row citing it (`promotion`). Current values (balance, rank, passed count, best score) come from the profile and the derived views, so first-submission facts and dashboard values never mix. No statistics columns were added (S2).

**Fixture environment (tests only):** rank thresholds, the final topic and the rollback need content for topics 3-18, which does not exist yet. `backend/tests/fixtures/setup_m3_fixture.php` builds a separate database `hunter_system_m3_fixture` (schema + seed + `[FIXTURE]`-labelled placeholder lessons and questions) with its own account `hunter_fixture` and private folder `C:\xampp\hunter-system-fixture-private`. Two PHP built-in servers serve it, so Apache and `hunter_system_dev` are untouched. Nothing was added to the dev database's content.

### 5.11 Badge store, purchases, profile and showcase (Milestone 4)

`BadgeService` + `BadgeRepository`; no schema change. Lock order: the student's profile/wallet row `FOR UPDATE`, then the badge row `LOCK IN SHARE MODE`, then other rows.

| Flow | Steps |
|---|---|
| Store | On-sale **and** approved badges only, with `owned` and `affordable` for this student. Empty is valid. |
| Purchase | One `Database::tx()`: lock profile → lock badge (share) → already owned? `409 already_owned` with the original facts, **no writes** → `on_sale` and `approved`? else `409 not_on_sale` → balance ≥ price? else `409 insufficient_coins` → insert `badge_ownership` (`price_paid` = the price just read) → insert the `badge_purchase` debit with `balance_after` = locked balance − price. The ledger triggers verify the running balance, refuse a negative one and update the cached wallet; the composite FK makes the debit equal `price_paid`. A duplicate-key error on `uq_badge_ownership_once` (backstop) becomes `already_owned`. |
| Profile | Own data only, no email: experience, placement rank and Hunter rank (separately), topics passed (view), coins, owned badges with `price_paid` and current status, ordered showcase. |
| Showcase | Validate (list of positive ints, ≤ `app_policies.showcase_limit`, distinct) → one tx: lock profile → every id must be owned (`409 not_owned` before anything is deleted) → `DELETE` the student's rows → `INSERT` positions 1..n. The FK `(user_id, badge_id) → badge_ownership` is the database backstop. |

**Why a share lock on the badge row:** a catalogue change (price, status) and a purchase can't interleave. If an administrator's change is uncommitted, the purchase waits for it and then applies the committed state (new price, or `not_on_sale`); a purchase in progress delays the change until it commits. Tested in both directions (fixture C3, C4). Admin catalogue writes (next milestone) never lock profile rows, so this can't deadlock with the profile → badge order.

**Concurrency:** all purchases and showcase edits of one student serialize on the profile row, so simultaneous same-badge purchases give 200 + `already_owned`, and simultaneous different-badge purchases can't overspend (the second sees the reduced balance). Showcase updates apply in arrival order and never mix.

**Artwork:** no artwork is approved. `image_ref` values under `placeholder/` give `image_url: null`; other references (`badges/<name>.png|webp`) map to `/hunter-system/assets/badges/<name>.<ext>`, served by the artwork path in §3 (Milestone 5).

**Fixture:** store purchases are tested in `hunter_system_m3_fixture` with per-run `[FIXTURE]` badges (`fx-<run>-*`) created by the admin connection and retired at the end of the run. No badge in `hunter_system_dev` was approved or published.

### 5.12 Administrator content management and audit (Milestone 5)

`ContentService` (topics, lessons, questions), `CatalogueService` (badges), `AuditService` (history), `AdminTrackingService` (students, logins); repositories `ContentRepository`, `CatalogueRepository`, `AuditRepository`, `AdminOverviewRepository`; controller `Admin\ContentController`. All routes `auth = admin`; mutations need CSRF. No schema change.

**One transaction per mutation, audit inside it.** Every successful change writes its content rows **and** its `audit_log` row(s) in the same `Database::txRetry()`. A refused change (validation, conflict, rule) throws before anything is written; an unexpected failure rolls back both (tested by revoking `INSERT` on `audit_log`: content unchanged, no audit row). `txRetry` re-runs a transaction InnoDB chose as a deadlock victim (at most 3 times); the victim was fully rolled back.

**Optimistic concurrency without schema change:** lessons and questions use their existing `revision` column (`expected_revision`); topics and badges use `updated_at` as an opaque version (`expected_version`), which every update moves forward by at least 1 ms (`GREATEST(UTC_TIMESTAMP(3), updated_at + 1 ms)`). The token is compared **after** the row is locked `FOR UPDATE`, so two admins can't both pass the check.

**Lock order:** topic row → lesson or question row → choices; badge row alone. Readiness and quota checks inside a mutation use **locking reads** (current committed state), never an older read view. Student flows lock their profile, then question rows in **share** mode (quiz/assessment start) or the badge row in share mode (purchase). Admin writes never lock profiles, so no cycle exists.

**Snapshot coherence (fixed in this milestone):** before, a quiz or assessment start read the question pool from its consistent read view but copied the choices with `INSERT … SELECT`, which reads the latest committed rows; an admin edit committing in between produced an **old stem with new choices**. Now `QuizRepository::validQuizPool` and `AssessmentRepository::validPool` read the pool with `LOCK IN SHARE MODE` (question rows only, no topic join), and every admin question edit locks the question row `FOR UPDATE` before touching its choices. A start either waits for an in-progress edit and copies the whole new version, or holds the rows until it commits and the edit waits. **Verified:** fixture test C1 (coherent new version) and a negative control with the lock removed (all 5 snapshots mixed old stems with new choices), after which the fix was restored byte-identically.

**Readiness guards:** `available` requires a published lesson and ≥ 10 active valid quiz questions (checked with locking reads under the topic lock). Unpublishing or retiring the lesson of an available topic is refused (`topic_in_use_set_draft_first`). Retiring an active quiz question that would take an available topic below 10 is refused unless `confirm: true`, which sets the topic to `draft` in the same transaction (two audit rows). Retiring an active assessment question that would leave a skill group below its quota is refused. Topic `draft` never touches progress, attempts or results; an attempt already in progress stays answerable and submittable (tested).

**Questions:** drafts may be incomplete (they can never be selected: students only receive `active` questions, and the pool also requires ≥ 2 choices and exactly one correct). Activation and any edit of an active question require the complete rules. Topic and usage flags are fixed once a question leaves draft. Choice ids are kept by label; a used choice can't be removed (also the snapshot FK). Retired is final; nothing is deleted.

**Badges:** create (`draft`, provisional) → edit → explicit `approve-price` of the exact current price (audited with the approving admin, price and time; shown as `price_approval`) → `on_sale`. A different price sets `provisional` and, if on sale, `off_sale` in the same transaction (the `-3` trigger also refuses a price change that keeps approval; the CHECK refuses `on_sale` without approval). Retirement keeps ownership, `price_paid` and showcases.

**Audit:** `audit_log` (append-only by trigger; `hunter_app` has SELECT and INSERT only). Actions use the schema enum (`create`, `update`, `publish`, `unpublish`, `retire`); price approval and price changes are `update` with `operation` `approve_price` / `price_change` in `after`. `before`/`after` hold content fields only. `GET /admin/audit-log` is paginated (≤ 100 per page) with validated filters.

**Tracking:** `GET /admin/students/{id}`, `/logins`, `GET /admin/login-events`: read-only, no coins, hashes or session references; the expiry sweep runs first.

### 5.13 Student interface (Milestone 6)

React 19 + React Router 7 + Vite 8 in `frontend/` (setup and commands: `frontend/README.md`). Router basename `/hunter-system`; static files under `/hunter-system/static/` so they can never collide with `/hunter-system/assets/badges/`.

- **One API client** (`src/api/client.js`): same-origin `fetch` with cookies; the HttpOnly session cookie is never visible to JavaScript and nothing is stored in `localStorage`/`sessionStorage` (verified in the browser). The CSRF token is kept in memory, taken from register/login/`/auth/me` responses and sent as `X-CSRF-Token`. Errors become `ApiError {status, code, message, details}`; network failures are `network_error`. **No request is ever retried automatically.** A 401 `session_expired`/`session_ended`/`not_authenticated` on any authenticated call notifies the session state.
- **Session state** (`AuthContext`): `/auth/me` on load → `loading` / `anonymous` / `authenticated`. Route guards send students by the **server-owned** `onboarding_state` (`/start` → `/assessment` → app) and administrators to `/admin`. Old `/admin-pending` links redirect there. On session loss the student is sent to `/login` with the reason and the page they were on; after logging in they return there, and everything the server had saved (answers, results, coins) is shown again.
- **Answering** (`QuestionRunner`, shared by the placement check and quizzes): the attempt comes from the server (refresh = resume). Answer saves run through one queue in the order chosen; each answer shows *saving / saved / not saved*. A failed save blocks moving on until retried. Submit waits for every pending save and refuses if any failed (verified with a delayed save in the browser). Submissions and purchases are single-flight (`useAction`) and not retried; `already_submitted` and `already_owned` are shown as recorded outcomes.
- **Obsolete requests:** screen loads use `useApi`, which aborts the previous request on route change, reload or unmount.
- **Lesson content:** Markdown rendered by a small in-house renderer that builds React elements (headings, paragraphs, lists, fenced and inline code, bold, italic; links shown as text). **No HTML is ever inserted** (no `dangerouslySetInnerHTML` anywhere); code keeps its indentation and is never executed. The lesson's leading `# <title>` is not repeated under the page title.
- **Accessibility:** labelled inputs, fieldsets for choices, a skip link, focus moved to the page title on navigation, visible focus outline, status text and icons in addition to colour, `aria-live` save announcements, 44 px targets, reduced-motion support.
- **Known UI decision:** right after submitting the placement check the cached onboarding state still says `experience_chosen`; the result route accepts it and syncs the state once the stored result loads (React Router applies navigations in a transition, so updating the state first redirected to the dashboard; found and fixed during browser testing).

### 5.14 Administrator interface (Milestone 7)

`frontend/src/pages/Admin.jsx` contains overview, students/detail, global login events,
topics/editor, lesson editor, question list/editor, badge list/editor and audit history.
Each screen uses contract §9–§10 through the existing `api/client.js`. Overview counts
come from those endpoints; there is no invented analytics endpoint. Lists use documented
URL query filters and pagination. Student details never request coins, session hashes or
password data; sessions distinguish valid, logged out and expired from actual API fields.

`App.jsx` gates all `/admin` routes by exact `admin` role; `AuthContext.homeFor` sends
administrators there, students keep their onboarding routes. `/admin-pending` redirects
to `/admin`. Layout selects role-specific navigation. Admin component state is keyed by
identity/role, never globally cached or stored in the browser. Guards unmount it on logout,
expiry or role changes; `useApi` aborts obsolete requests.

Shared `Editor` retains an original server record and a separate editable draft. It sends
the exact original revision/version, disables repeat writes and lifecycle actions while
pending/dirty, and retains fields on errors. A stale edit disables further mutations and
offers a current-record fetch for comparison; only an explicit discard/reload adopts the
new token. No silent merge or stale retry. Native dialogs confirm availability, permanent
retirement and approval/publication of the exact saved price. Pool-threshold override is
a separate confirmed action; assessment quotas have no override. Retired questions/badges
are read-only; retired lessons may still be edited and republished per the existing API.

`main.jsx` uses React Router's `createBrowserRouter`/`RouterProvider` with the same basename
and nested routes, enabling `useBlocker` for dirty navigation. `beforeunload` warns on
refresh/close; logout checks dirty state before clearing authentication. Existing styles
and components are reused; selects, textareas and number fields share 44px controls. Audit
JSON and content use escaped React text, never HTML injection. Artwork is nullable and
restricted to the existing image-reference grammar; no uploads.

Apache's SPA route allowlist now includes `admin`; private-file rules and CSP remain in
force (37 private probes blocked after the latest build). `frontend/tests/admin-journeys.mjs` defaults
to the fixed guarded fixture API; `--mock` is explicitly UI-only. `admin-readonly.mjs`
blocks admin writes and fingerprints dev content/audit. Exact execution limitations and
results are in `progress.md`. No production backend runtime, schema, grants or API behavior changed.

**Verification configuration (completed):** the original private directories are unchanged.
The test process executes as `codexsandboxoffline` even though `USERNAME` inherits the human
user name. Test-only setup uses the actual token SID to protect the exact allowed temporary
child `%TEMP%\HunterSystem-M7-Verification` before generating fresh credentials; current SID,
SYSTEM and Administrators only. `seed/` points to one-time seeded `hunter_system_m7_seed_fixture`
as `hunter_m7_seed`; `complete/` points to the preserved `hunter_system_m3_fixture` as
`hunter_m7_fixture`. Each receives exactly the existing 57 application grants on its own
fixture only, without modifying the original accounts or copying production secrets.

`backend/tests/fixtures/router.php` binds via loopback PHP servers 8097 (seed), 8098/8099
(complete). It refuses unknown/dev database-account pairs before dispatch and provides a
test-only `__test/identity` response using actual PDO database/account names. Normal Apache
API routing has no such endpoint. `requireFixtureBackend` in `TestSupport.php` and browser runners
check fixed/allowlisted URLs and exact helper/server identity before mutations. Preview
proxies are also checked. Browser database helpers use restricted application credentials;
root remains confined to the established CLI provisioning/backend-test responsibilities.

Student `--isolated` runs all existing journeys on seed/complete fixtures through previews
4172/4173; admin Chrome serves the same build on 4187 and pins contexts to the attested
backends. Live admin mode uses actual pool/conflict/validation/expiry responses; simulated
errors are confined to `--mock`. See `milestone-7-verification.md` for rerun and scoped
server cleanup instructions. No fixture/dev reset, no immutable-history bypass, and no final
course or badge publication occurred in dev. Content and audit checksums stayed identical.

## 6. Restricted database account (implemented)

`backend/bin/setup-db-account.php` (run once by the developer, as a database admin):
1. Creates `'hunter_app'@'127.0.0.1'` with a random 48-hex-character password.
2. Runs `REVOKE ALL`, then applies `database/accounts/hunter_app_grants.sql`.
3. Writes the private `hunter.env`, restricting the folder ACL and letting files inherit it.

It never prints the password. Re-running it re-syncs the grants; `--rotate` changes the password. `--account=hunter_<name>` creates another restricted account with the same grants on another database (used for the fixture database). An account that already has grants on a **different** database is refused, so `hunter_app` cannot be re-pointed by mistake (verified).

**Milestone 1 grants (exactly these):**

| Table or view | Privileges |
|---|---|
| `schema_info` | SELECT |
| `app_policies` | SELECT |
| `users` | SELECT, INSERT, UPDATE(`password_hash`, `updated_at`) |
| `student_profiles` | SELECT, INSERT |
| `user_sessions` | SELECT, INSERT, UPDATE(`last_activity_at`, `ended_at`, `end_reason`) |
| `login_events` | SELECT, INSERT |
| `login_throttle` | SELECT, INSERT, UPDATE, DELETE |
| `v_admin_student_overview` | SELECT |

**Milestone 2 additions** (36 `GRANT` statements in total; the file is the source of truth):

| Table or view | Privileges |
|---|---|
| `experience_levels`, `ranks`, `skill_groups`, `topics`, `questions`, `question_choices`, `assessment_policies`, `assessment_group_quotas`, `placement_rank_bands`, `rank_progression_policies`, `rank_progression_bands` | SELECT |
| `student_profiles` | + UPDATE(`experience_level_id`, `experience_chosen_at`, `onboarding_state`, `placement_attempt_id`, `placement_rank_id`, `current_rank_id`, `updated_at`). **Not** `coin_balance`. |
| `attempts` | SELECT, INSERT, UPDATE(`status`, `submitted_at`) |
| `attempt_questions` | SELECT, INSERT, UPDATE(`selected_choice_id`, `answered_at`, `is_correct`). Snapshot text isn't updatable. |
| `attempt_question_choices`, `assessment_results`, `rank_history`, `student_topic_progress` | SELECT, INSERT |
| `v_attempt_choices_public`, `v_topic_readiness`, `v_assessment_group_results`, `v_assessment_topic_evidence`, `v_student_topic_status`, `v_student_topics_passed` | SELECT |

**Milestone 3 additions** (44 `GRANT` statements in total):

| Table | Privileges |
|---|---|
| `lessons`, `quiz_grading_policies`, `quiz_grade_bands`, `reward_policies` | SELECT |
| `quiz_results`, `reward_claims`, `coin_ledger` | SELECT, INSERT (no UPDATE or DELETE; triggers also make them append-only) |
| `student_topic_progress` | + UPDATE(`unlocked_at`, `unlock_source`, `first_lesson_visit_at`, `last_lesson_visit_at`, `lesson_visit_count`, `updated_at`) |

**No** global privileges and no `CREATE`, `ALTER`, `DROP`, `TRIGGER`, `GRANT`, `FILE` or other databases.
- **Milestone 1 test D1:** 14 such operations refused.
- **Milestone 2 test P2:** 12 more refused, including `coin_balance`, snapshot text, result and ledger rewrites, deletes and content edits.
- **Milestone 3 test P1:** 10 more refused: ledger, claim and result rewrites, `coin_balance`, publishing lessons or topics, adding questions, deleting progress, changing the reward policy, and deleting ownership.
- **Milestone 4 additions** (47 `GRANT` statements in total): `badges` SELECT; `badge_ownership` SELECT, INSERT; `badge_showcase` SELECT, INSERT, DELETE (the only DELETE besides `login_throttle`; the showcase is replaced as a whole). **Milestone 4 test G1:** 8 store operations refused: publishing, approving or creating badges, rewriting ownership, updating showcase rows in place, changing the app policy, rewriting the ledger.
- **Milestone 5 additions** (57 `GRANT` statements in total): `topics` UPDATE(`summary`, `status`, `updated_at`); `lessons` INSERT + UPDATE(content columns); `questions` INSERT + UPDATE(content and status columns); `question_choices` INSERT, DELETE, UPDATE(`choice_text`, `is_correct`); `badges` INSERT + UPDATE(`name`, `description`, `image_ref`, `price_coins`, `price_status`, `status`, `updated_at`); `audit_log` SELECT, INSERT. Still refused (**Milestone 5 test P1**, 19 operations): updating or deleting audit rows, deleting questions/lessons/badges, inserting topics, curriculum columns (`position`, `title`, `skill_group_id`), badge `slug`, choice `label`, snapshot rows, policies, user role/status, rank history, `ALTER`, `DROP`. Because these content columns are now writable, older probes that used them were moved to still-forbidden operations (auth D1, assessment P2, quiz P1, badge G1).
- Because Milestone 4 grants read access to `badges` and `badge_ownership`, three older probes were changed again: auth D1 `SELECT badge_ownership` → `UPDATE badges`; assessment P2 `SELECT badges` → `INSERT badges`; quiz P1 `SELECT badge_ownership` → `DELETE badge_ownership`.
- Because Milestone 3 grants `INSERT` on `quiz_results` and `coin_ledger` and `SELECT` on `coin_ledger`, three older probes were changed to still-forbidden operations: auth D1 `SELECT coin_ledger` → `SELECT badge_ownership`; assessment P2 `INSERT quiz_results` / `INSERT coin_ledger` → `UPDATE quiz_results` / `DELETE coin_ledger`.

Later milestones add lines to the grants file and re-run the script.

## 7. Content management, assessment review, badges (implemented rules)

- **Assessment review (O-08, decided):** after placement submission, the response includes the summary **and** per-question correct answers and explanations from the snapshot. Before submission, neither is exposed.
- **Badge prices (O-09, decided; enforced in the database since `-3`):**
  - `on_sale` requires `price_status='approved'` (CHECK).
  - Changing the price while it's approved is refused (trigger); the price must go back to `provisional` and be approved again.
- **Admin powers:** no account deactivation or rank correction (O-05). Content management and audit are implemented in the API (§5.12) and React interface (§5.14).
- **Topic readiness (O-01):** a published lesson plus at least 10 active **valid** quiz questions (2 or more choices, exactly one correct), in the `v_topic_readiness` view.

## 8. Local setup and verification (exact steps)

Prerequisites: XAMPP at `C:\xampp`, with Apache 2.4 and MariaDB 10.4 started from the XAMPP Control Panel or `C:\xampp\apache_start.bat` and `C:\xampp\mysql_start.bat`.

1. **PHP `intl`:** in `C:\xampp\php\php.ini`, the line `extension=intl` must be uncommented. It was enabled on 2026-10-14; the original is saved as `php.ini.bak_before_intl_20261014_154531`. Restart Apache afterwards.
2. **Database at schema `2026.10.14-3`:**
   - Fresh install: see `database/README.md`, section A.
   - Existing `-2` database:

     ```powershell
     & C:\xampp\mysql\bin\mysql.exe --abort-source-on-error -u root -p hunter_system_dev -e "source C:/xampp/htdocs/hunter-system/database/migrations/003_auth_throttle_readiness_badges.sql"
     ```
3. **Restricted account and private config:** this asks for the database admin password (press Enter if none):

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\bin\setup-db-account.php --db=hunter_system_dev
   ```
4. **First administrator:**

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\bin\create-admin.php --email=you@example.org --display-name="Your Name"
   ```
5. **Check the API:**

   ```powershell
   curl.exe -s http://localhost/hunter-system/api/v1/health
   ```

   Expected: `{"data":{"status":"ok","checks":{"database":true,"schema":true,"intl":true,"argon2id":true}}}`
6. **Re-sync grants** whenever `database/accounts/hunter_app_grants.sql` changes (keeps the password):

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\bin\setup-db-account.php --db=hunter_system_dev
   ```
7. **Run the tests:** creates `it.<run>.*` / `m2.<run>.*@example.invalid` accounts in the configured database:

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\tests\auth_integration_test.php
   ```

   - **Pass:** expect `41 checks, 41 passed, 0 failed`.
   - **Milestone 2:** `C:\xampp\php\php.exe C:\xampp\htdocs\hunter-system\backend\tests\assessment_integration_test.php`, expecting `33 checks, 33 passed, 0 failed`.
   - **Milestone 3 (dev, Apache):** `...\backend\tests\quiz_integration_test.php`, expecting `51 checks, 51 passed, 0 failed`.
   - **Milestone 3 (fixture):** build the fixture once, start two built-in servers, run the fixture suite (expect `16 checks, 16 passed, 0 failed`):

     ```powershell
     C:\xampp\php\php.exe backend\tests\fixtures\setup_m3_fixture.php
     $env:HUNTER_PRIVATE_DIR = 'C:\xampp\hunter-system-fixture-private'
     C:\xampp\php\php.exe backend\bin\setup-db-account.php --db=hunter_system_m3_fixture --account=hunter_fixture
     Start-Process C:\xampp\php\php.exe '-S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php'
     Start-Process C:\xampp\php\php.exe '-S 127.0.0.1:8099 -t backend\tests\fixtures backend\tests\fixtures\router.php'
     C:\xampp\php\php.exe backend\tests\quiz_fixture_test.php
     ```

     The fixture suite refuses to run unless its configuration points at `hunter_system_m3_fixture` as `hunter_fixture`.
   - **Milestone 4:** `...\backend\tests\badge_integration_test.php` (dev, expect `12 checks, 12 passed`) and, with the two fixture servers running, `...\backend\tests\badge_fixture_test.php` (expect `25 checks, 25 passed`).
   - **Milestone 5:** `...\backend\tests\admin_integration_test.php` (dev; reads and **refused** mutations only, guarded by a content fingerprint; expect `15 checks, 15 passed`) and, with the fixture servers running, `...\backend\tests\admin_fixture_test.php` (expect `31 checks, 31 passed`).
   - **Admin password:** the test's database-admin connection uses `root`, with the password from `HUNTER_TEST_DB_ADMIN_PASSWORD` (empty if unset).
8. **Database checks:**

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe --abort-source-on-error -u root -p hunter_system_dev -e "source C:/xampp/htdocs/hunter-system/database/verify.sql"
   ```

   Expect `passed 66, failed 0, info 3` on the dev database. `verify.sql` separates **Part A/B** (structure and integrity; valid for any content) from **Part S** (assumptions about the standard seed). Row A00 reports the content profile; on the fixture database Part S reports INFO "not applicable" (expect `passed 64, failed 0, info 5`).
9. **Student interface:** in `frontend/` run `npm install` (once) and `npm run build`; open `http://localhost/hunter-system/`. Development: `npm run dev` → `http://localhost:5173/hunter-system/`. Browser journeys: `npm run test:browser` (needs the two fixture servers for Part B; expect `31 checks, 31 passed`).
10. **Optional:** schedule `C:\xampp\php\php.exe ...\backend\bin\purge-expired.php` hourly in Task Scheduler, so the 24-hour throttle limit also holds while the API is idle.

**Windows note:** `curl.exe` re-encodes non-ASCII command-line arguments. Send Unicode JSON from a file (`--data-binary @body.json`); otherwise the API correctly answers `400 invalid_json`.

## 9. Open items

Final local acceptance: publication authorized but blocked by stopped services/authentication.
Prepared batch now stages foundations1–2 with existing question IDs, captures outside-htdocs
history/audit/IR-1 fingerprints after logical backup, and supports explicit local publication/
exact-price badge sale through current APIs. Paths are not live-rehearsed yet. Logger error
records now contain no exception message/trace arguments; Request rejects [] for JSON objects.
Focused tests pass, live API regression pending. See final-local-test-report.md and
local-startup-demo.md. No machine-wide security or deployment settings changed.

Updated local content preparation: reviewed foundations live privately under content/review,
with explicit AI review report and execution/rendering evidence. Existing production React/PHP
behavior/schema unchanged. Course dev apply now requires a logical backup; hidden password
input fails closed without a terminal. import_badges.py uses existing auth/CSRF/admin APIs,
stable source slugs/receipts and exact versions to create/update approved titles off sale and
retire four samples without deleting history or fabricating approval. local_content_batch.py
backs up outside htdocs before mutations; live rehearsal remains blocked by local service
access and authentic attribution. No deployment work is part of completion scope.

Milestone 8B: private badge proposal sources/review/manifest/reports are in `content/badges/`.
Original SVG geometry is rendered by installed Chrome into six allowed transparent PNGs under
`assets/badges/m8b-*-v1.png`; no SVG/HTML/manifest source is publicly served. Existing BadgeArt
renders the PNGs at 64px without UI changes. Proposal prices and metadata are not imported to
dev. Lifecycle rehearsal uses the existing guarded fixture backend, authenticated existing
fixture administrator and admin APIs; no production code/schema/configuration change.

Milestone 8A adds private authoring artifacts under `content/`, not runtime product code.
Markdown matches the existing safe React renderer; JSON banks remain outside public assets.
`content/tools/validate_course.py` executes only explicit reviewed manifests in disposable
directories. `import_course.py` authenticates a real administrator and calls existing APIs,
retaining service transactions, audit attribution and exact concurrency tokens. Saved private
receipts provide stable source-key/ID mappings and fail-closed idempotency; usage counters are
excluded from edit signatures. Publication command is fixture-only. Dev draft import remains
pending actor identification; no dev publication. See `milestone-8a-content.md` for evidence.

See `decisions.md` §4. Still pending: badge artwork and prices, the remaining course content, which SRS features to include, and whether to throttle registration.
