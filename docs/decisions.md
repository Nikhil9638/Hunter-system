# Hunter System: Decisions Log

**Last updated:** 2026-10-14

This log has five parts:
- what you have confirmed (C-xx)
- what you approved on 2026-10-14 (A-xx)
- the status of every earlier proposal (P-xx)
- what is still open
- the implementation choices (D-xx)

IDs match `database-requirements.md`, which has the full wording and reasons.

## 1. Confirmed decisions (made by you)

| ID | Decision |
|---|---|
| C-01 | College project that teaches Python from beginner to intermediate through lessons, assessments, topic quizzes, ranks, coins and collectible badges. |
| C-02 | Stack: React, PHP, MySQL, Apache through XAMPP. (XAMPP actually provides MariaDB 10.4; see D-01.) |
| C-03 | Multiple-choice questions, including code-output prediction. No execution of student Python code. |
| C-04 | Students pick an experience level at onboarding (four options). |
| C-05 | Self-selected experience is kept separate from assessed knowledge. "Master" never grants a high rank by itself. |
| C-06 | The assessment has exactly 10 questions. |
| C-07 | Assessment results determine the starting rank and the recommended topic. |
| C-08 | Assessment records keep assigned question identities, versions or snapshots, answers, scoring rules, result and recommendation. |
| C-09 | Each topic quiz has five questions. |
| C-10 | Students may read the lesson first or attempt the quiz directly. |
| C-11 | Passing depends on the quiz grade. |
| C-12 | Completing questions earns coins. |
| C-13 | Coins buy badges in a store. Owned badges can be showcased on the profile. |
| C-14 | Badges have name, description, image reference, coin price and availability. |
| C-15 | A badge purchase deducts coins and records ownership atomically. |
| C-16 | The price actually paid is kept even if the store price changes. |
| C-17 | Only owned badges can be showcased. |
| C-18 | Purchases never change knowledge level or rank. |
| C-19 | A ledger records every coin credit and deduction with amount, source, student and timestamp. |
| C-20 | Students can see their current learning level and progress. |
| C-21 | Administrators can track login activity: account list, last successful login, login history, logout and expiry information, last activity, rank, current topic and progress summary. |
| C-22 | "Recently active" uses a documented window. A login record alone never means "online", and closing a browser may leave no logout event. |
| C-23 | Raw session tokens and passwords are never stored in logs. A safe session reference is allowed. |
| C-24 | Ranks: E-Rank (Rookie), D, C, B, A, S. These are learning ranks inside the app. |
| C-25 | The 18-topic curriculum in its stated order. Each topic needs a lesson and a question pool, and the pool size is separate from the five questions per attempt. |
| C-26 | The initial placement rank is stored separately from later progression. |
| C-27 | PHP alone determines scores, ranks, progression, rewards and badge prices. |
| C-28 | No correct answers are sent before submission. Assigned questions are saved per attempt. |
| C-29 | Duplicate submissions must not award rewards twice. |
| C-30 | Password hashes, parameterized queries, and secrets kept out of committed code. |

## 2. Product decisions approved on 2026-10-14

| ID | Area | Approved decision |
|---|---|---|
| A-01 | Quizzes | Pass at 3/5. Keep the A–F grade scale. Unlimited retries. Passing unlocks the next topic. Failed attempts leave the lesson and a retry available. Retries prefer unseen questions. Completion requires all five questions answered and submitted. **Show correct answers and explanations after submission, never before.** Label quiz results as quiz performance, not proof of programming mastery. |
| A-02 | Coins | 10 coins once per student per topic on the first completed quiz, even if failed. No coins for partial attempts, retries or the placement assessment. Keep duplicate-reward prevention and the ledger. |
| A-03 | Placement | The E–S score mapping. One official placement per student. The 2/2/2/2/1/1 skill-group layout. Earlier topics stay available but aren't automatically passed. Recommend the first topic of the earliest group with a missed answer; all correct recommends topic 18. **If that topic isn't available, keep the recommendation and separately offer the nearest earlier published topic with a complete lesson and enough quiz questions. Never silently replace the recorded recommendation.** Keep the D–S letter names; E is Rookie. |
| A-04 | Rank progression | Based on distinct topics passed, including topics below placement: E 0–2, D 3–5, C 6–8, B 9–11, A 12–14, S 15–18. Current Hunter rank = the highest of the initial placement, any previously achieved rank, and the rank earned from topics passed. **The rank never decreases.** Record each actual promotion with its evidence and policy version. Badges and coins never change rank. |
| A-05 | Badges | One purchase per badge per student. Showcase up to three owned badges. Purchase-only in this version. Retired badges stay owned and can still be showcased. Sample prices and artwork stay provisional, and the samples remain unavailable for purchase until approved. |
| A-06 | Administrators | Track login activity and student progress. **Manage topics, lessons, questions and badges, with an audit trail.** 15-minute recently-active window; 30-minute idle expiry; activity update about every 60 seconds. Record failed login outcomes without storing typed credentials. Coin balances aren't shown in the admin interface. Progress = topics passed out of 18. |
| A-07 | Authentication | Login by email. Students have a separate display name. Passwords: at least 12 characters, spaces and Unicode allowed, no forced symbols or uppercase. Check the hashing algorithm's input limits and never silently truncate. Add login throttling during authentication, with bounded retention and no raw credentials logged. Create the first administrator through a CLI command with an interactively entered password. |
| A-08 | Database | Approve S1 (derive assessment group results and topic evidence from immutable attempt records) and S2 (derive quiz statistics from attempts and results). Keep the ledger safeguards, policy records and audit structures. Defer S3 and S4. |
| A-09 | Authentication milestone | Readiness = published lesson + at least 10 active valid quiz questions; IP-hash throttling (24 h max); `intl` + NFKC password normalization; Argon2id, never truncated; placement review shows answers and explanations after submission; badge sale needs explicit price approval, and a price change needs renewed approval; no admin deactivation or rank correction; SRS extras, badge art and prices, and remaining content stay pending. |

## 3. Status of the earlier proposals

| ID | Proposal (short) | Status |
|---|---|---|
| P-01 | Grade scale A–F | **Approved** (A-01) |
| P-02 | Pass = at least 3/5 | **Approved** (A-01) |
| P-03 | Unlimited retries | **Approved** (A-01) |
| P-04 | Passing unlocks the next topic | **Approved** (A-01) |
| P-05 | Failed attempts keep lesson and retry available | **Approved** (A-01) |
| P-06 | Lesson visit, completion and pass kept as separate facts | Implemented (visits stored; completion and pass derived). Not addressed separately. |
| P-07 | Retry prefers unseen questions | **Approved** (A-01) |
| P-08 | Pool of at least 10 questions per topic | Used as the readiness threshold (`min_pool_size = 10`); **please confirm** (D-12) |
| P-09 | Completion = all five answered and submitted | **Approved** (A-01) |
| P-10–P-13 | 10 coins on first completion; nothing for partial attempts, retries or the assessment | **Approved** (A-02) |
| P-14 | Reward amounts kept as versioned rules; amount stored on each ledger entry | Implemented; consistent with A-02 |
| P-15–P-17 | One purchase per badge; showcase 3; purchase-only | **Approved** (A-05) |
| P-18 | Badge prices and artwork undecided | **Still open**; samples provisional and not on sale |
| P-19 | Placement score mapping | **Approved** (A-03) |
| P-20 | Placement is a starting point, not proof of mastery | Your earlier instruction; extended to quiz results by A-01 |
| P-21 | Placement kept forever; current rank stored separately | **Approved** (A-04) |
| P-22 | One official placement per student | **Approved** (A-03) |
| P-23 | Self-selected experience used only as context | Implemented; not addressed separately |
| P-24 | Rank rises only from verified results, recorded with evidence | **Superseded by A-04** (topics-passed formula) |
| P-25 | Names for ranks D–A | **Resolved**: keep letter names (A-03) |
| P-26 | Earlier topics open but not counted as passed | **Approved** (A-03) |
| P-27 | 2/2/2/2/1/1 layout; "Not assessed" and "Sampled" labels | **Approved** (A-03) |
| P-28 | Starting-topic rule | **Approved, with a fallback** (A-03) |
| P-29–P-31 | 15 min, 30 min, about 60 s | **Approved** (A-06) |
| P-32 | Failed logins recorded without typed text | **Approved** (A-06) |
| P-33 | Admin content management with an audit trail | **Approved** (A-06) |
| P-34 | Deactivate accounts instead of deleting them | Implemented; not addressed separately |
| P-35 | Coin balances not shown to admins | **Approved** (A-06) |
| P-36 | Progress = topics passed out of 18 | **Approved** (A-06) |

## 4. Open items: resolved and remaining

Resolved on 2026-10-14 (authentication milestone, recorded as **A-09**):

| ID | Resolution |
|---|---|
| O-01 | A topic is ready only when its lesson is published and it has **at least 10 active, valid quiz questions** (2 or more choices, exactly one correct). Enforced by `v_topic_readiness` (schema `-3`). |
| O-02 | Login throttling may store a **keyed hash of the client IP for at most 24 hours**, with the key outside the web root and no raw credentials. Implemented. |
| O-03 | PHP `intl` enabled. Passwords are **NFKC**-normalized at registration, admin creation and login; emails and names are NFC. No existing accounts were affected: `hunter_system_dev` had 0 users. |
| O-05 | Administrators **cannot** deactivate accounts or manually correct ranks in this milestone. |
| O-07 | Additional SRS features stay **outside** this milestone. |
| O-08 | After placement submission, show the summary **and per-question answers and explanations**. Before submission, show neither. |
| O-09 | A badge needs **explicit administrator price approval** before sale, and **changing the price requires renewed approval**. Enforced by the database (schema `-3`: CHECK and trigger). |

Still open:

| ID | Question |
|---|---|
| O-04 | Badge names, artwork and prices (samples remain provisional and `draft`). The artwork **path** is decided (D-55); the artwork itself is not. |
| O-06 | Review of the seeded content; content for topics 3–18. (Until content exists, most placements recommend a topic that isn't ready, and the offered topic is 1 or 2.) Since Milestone 3 this also means a student who passes topic 2 unlocks topic 3 but gets `topic_content_not_available` there; nothing was published or invented to hide this. |
| O-10 | Should **registration** be throttled too (for example per IP)? It isn't yet. The `409 email_taken` response also reveals whether an email is registered (inherent in the contract). |
| O-11 | MariaDB listens on all interfaces, and `root` has **no password** (`root@localhost`, `127.0.0.1`, `::1`). Securing root was outside this milestone. Recommended before any shared or networked use. |

## 5. Implementation choices (mine, for your review)

| ID | Choice |
|---|---|
| D-01 | The database is **MariaDB 10.4.32** (what XAMPP provides). C-02 says MySQL, and the SQL uses MariaDB syntax. |
| D-02 | The database is named **`hunter_system_dev`**. The earlier `hunter_system` was left untouched. |
| D-03 | History is kept as **complete per-attempt snapshots**. The question bank is edited in place with an informational revision counter. |
| D-04 | One shared `attempts` table, with separate result tables. |
| D-05 | `student_profiles` is the per-student wallet and lock row. The coin balance is a trigger-maintained cache of the ledger. |
| D-06 | Rules are versioned policy rows. Approval adds a new version (v2), and the provisional v1 rows are kept as `retired`. |
| D-07 | Triggers enforce finalize-once, immutability, append-only history, a never-negative balance, rank and balance changes only through their history, a rank that never decreases, and promotion evidence. |
| D-08 | All timestamps are UTC. |
| D-09 | No cascading deletes; rows are retired or deactivated instead. |
| D-10 | Email is the only login identifier, stored lower-case. The `username` column was removed in schema `-2` (it was empty). |
| D-11 | The seeded content is development material; topics 3–18 are `draft`. |
| D-12 | "Enough quiz questions" for a topic to be ready means **at least 10 active quiz questions** (`quiz_grading_policies.min_pool_size`), twice the attempt size, so retries can prefer unseen questions. This is my interpretation of A-03 (O-01). |
| D-13 | Passwords are hashed with **Argon2id** (`PASSWORD_ARGON2ID`), never PHP's default bcrypt. Testing showed bcrypt silently truncates at 72 bytes, while Argon2id uses the full input. A maximum length of 256 characters (1,024 bytes) bounds hashing cost. |
| D-14 | **Lock order** for every write: student profile row → attempt row → other rows. Saving an answer uses the same order as submitting, so the two can't interleave. |
| D-15 | Session tracking stores the SHA-256 of a separate random token kept in `$_SESSION`, not PHP's session ID, which changes on `session_regenerate_id()`. |
| D-16 | Schema changes to an existing database go through numbered migration files with a pre-change guard block, a logical backup, a rehearsal on a restored copy, and a structural comparison with a fresh install. |
| D-17 | **Unicode normalization:** passwords use NFKC (NIST SP 800-63B recommends NFKC or NFKD), so composed and decomposed forms and full-width compatibility characters match. Emails and display names use NFC, are trimmed, and emails are lower-cased. Without `intl`, the API refuses to serve authentication (503) rather than skip normalization. |
| D-18 | **Private folder** `C:\xampp\hunter-system-private\` (outside `htdocs`) holds `hunter.env` (DB credentials, `THROTTLE_KEY`), PHP session files and `logs\app.log`. Its ACL allows only the current user, SYSTEM and Administrators, and files inherit it. Override with the `HUNTER_PRIVATE_DIR` environment variable. |
| D-19 | **Throttle parameters:** email key 5 failures, IP key 30 failures, both per 15-minute window; 15-minute lock; absolute deletion 24 hours after creation, even while locked. Adjustable in `hunter.env`; retention can't exceed 24 hours. |
| D-20 | **Least-privilege account** `hunter_app@127.0.0.1`. Grants live only in `database/accounts/hunter_app_grants.sql` and are applied after a full `REVOKE`; column-level `UPDATE` covers `users` and `user_sessions`. Each milestone extends the file and re-runs `backend/bin/setup-db-account.php`. |
| D-21 | **Apache protection** is a deny-by-default `/hunter-system/.htaccess` (front-controller rewrite, 404 for everything else, no listings, security headers), with `Require all denied` in each private folder. No `httpd.conf` change was needed, because `AllowOverride All` already applies to `htdocs`. |
| D-22 | **Origin check:** every state-changing request with an `Origin` header must match `APP_ORIGINS`. Together with JSON-only bodies, this blocks cross-site login and registration, which have no CSRF token. |
| D-23 | **Registration rejects unknown JSON keys** (`role` and anything else), so the browser can never influence the role. |
| D-24 | **Milestone 2 needed no schema change.** The `-3` tables, triggers and views cover onboarding, snapshots, results, placement and topic availability; only grants were added (`hunter_app_grants.sql`, now 36 statements). |
| D-25 | **Assessment question order:** positions follow skill-group order (1–2 G1 … 10 G6). Questions are chosen at random within each group with a CSPRNG shuffle (`random_int`). Choices keep their label order (no shuffling). |
| D-26 | **Start status codes:** `POST /assessment` returns `201` when it creates an attempt and `200` with `"resumed": true` when it returns the open one. A simultaneous start resumes the same attempt. |
| D-27 | **Dashboard `next_rank`** returns `topics_required` (the band's minimum) and `topics_remaining` (required minus passed), replacing the draft's single `topics_needed`. It comes from the current rank progression policy, and is null at S or before placement. |
| D-28 | **Topic list** reports `unlocked` (this student's availability) and `content_ready` (lesson + at least 10 valid questions) as separate booleans, alongside the derived `status`. |
| D-29 | **Race tests over HTTP** fix the arrival order by holding the student's profile-row lock from a database-admin connection, then sending two requests 300 ms apart (InnoDB FCFS lock queue). No hold or sleep hooks exist in application code. |
| D-30 | **Milestone 3 needed no schema change.** `-3` already has `lessons`, `quiz_results`, `reward_claims` (unique per student, topic and type), `coin_ledger` with its balance triggers, `rank_history` with promotion-evidence triggers, and the derived topic status views. Only 8 `GRANT` statements were added (44 in total). |
| D-31 | **Access check order** for lessons and quizzes: `placement_required` → `404` → `topic_locked` → `topic_content_not_available`. Unlocked and ready are separate checks, and a failed check creates nothing. |
| D-32 | **Question choice for a quiz:** "unseen" means never assigned to this student **in this topic's quizzes** (any earlier attempt, passed or failed). Unseen questions are used first and seen ones fill the remainder; both sets are shuffled with `random_int`. With a pool of 12, attempts 1 and 2 never overlap and attempt 3 uses the last 2 unseen plus 3 seen (tested). |
| D-33 | **Grading uses the current grading policy at submission time** and stores its id with the result, so a later policy version never changes a stored grade. The reward uses the current reward policy, recorded on the claim. |
| D-34 | **Reward timing:** the claim is checked inside the submit transaction, after the profile lock, so two submissions of one topic cannot both pay; the unique key `uq_reward_claims_once` is the database backstop. The first completed attempt is the one that pays, even if a later one passes. |
| D-35 | **Unlock rule:** only a pass unlocks, only the next topic by curriculum position, and only if it is still locked (`unlock_source='passed_previous'`). A topic unlocked by placement keeps `placement`. Unlocking never makes content ready and never re-locks. Topic 18 has no successor. |
| D-36 | **Promotion rule:** after a pass, earned rank = band of the distinct passed count under the current rank policy. A promotion is recorded only if it is above the **current** rank (which already includes the placement floor and earlier promotions), so at most one promotion per submission and never a demotion. A placed-C student stays C at 3–8 passes and becomes B at 9. |
| D-37 | **Result facts vs current values:** a stored `QuizResult` reports only what that attempt caused (reward with its own `balance_after`, the unlock if it was the first pass, its own promotion). Balance, rank, passed count and best score shown elsewhere are current values from the profile and views. |
| D-38 | **New response fields** (additive): `coins_awarded` (0 or the amount), `attempt_number`, `question_count`, `answered_count`, `resumed`, `promotion.policy_version`, ledger entry `id` (for `before_id` paging), history `started_at`. |
| D-39 | **Isolated fixture for advanced cases:** rank thresholds, the final topic and the rollback need ready topics 3–18, which do not exist. They are tested in a separate database `hunter_system_m3_fixture` with `[FIXTURE]`-labelled placeholder content, its own restricted account `hunter_fixture` and private folder, served by PHP's built-in server. `hunter_system_dev` content, Apache and other students were not changed. |
| D-40 | **Controlled-failure test:** the rollback is proven by temporarily revoking `INSERT` on `coin_ledger` from the **fixture** account during one submission (restored in `finally`). The API returns `500 internal_error` with a logged `error_id`, and the attempt, result, claim, ledger, unlock, rank and balance are all unchanged; the same attempt then submits normally. |
| D-41 | **Contract correction, store listing:** `GET /badges` lists only badges that are `on_sale` with an `approved` price (your rule). Owned badges that are off sale or retired are shown in the profile, not the store. |
| D-42 | **Contract correction, artwork:** `image_url` is nullable. `image_ref` under `placeholder/` → `null` (all four seeded badges); other references → `/hunter-system/assets/<ref>`, a provisional location until artwork and the frontend asset folder exist. No artwork was invented. |
| D-43 | **Purchase retry semantics:** an already-owned badge always answers `409 already_owned` with `details {badge_id, price_paid, purchased_at}` and never writes; a client whose first response was lost treats it as confirmation. Check order: unknown → `404`; owned → `already_owned`; not on sale or not approved → `not_on_sale`; balance → `insufficient_coins`. |
| D-44 | **Purchase locking:** profile row `FOR UPDATE`, then the badge row `LOCK IN SHARE MODE`; price and availability are read under that lock, inside the transaction. A concurrent catalogue change is either fully before (applied) or after (waits) the purchase. |
| D-45 | **Profile and showcase shapes:** the profile adds `coins`, `showcase_limit`, and per-badge `image_url`, `status`, `showcased`; no email or account fields. `PUT /me/showcase` returns `{showcase: [...]}`; validation codes `required`, `invalid`, `too_many`, `duplicate`; `409 not_owned` names the first unowned id. The limit comes from `app_policies.showcase_limit` (3). |
| D-46 | **Milestone 4 needed no schema change.** Ownership (unique, append-only, `price_paid`), the debit's composite FK to ownership, the balance triggers and the showcase-ownership FK already existed. Grants: +3 statements (47), including `DELETE` on `badge_showcase` only. |
| D-47 | **Store fixtures:** sale-approved badges exist only in `hunter_system_m3_fixture`, created per run as `[FIXTURE]` badges (`fx-<run>-*`) and retired at the end of the run (earlier runs' leftovers are retired at start). The dev sample badges stay `draft` with provisional prices. |
| D-48 | **`verify.sql` split:** Part A/B check structure and integrity for any content; Part S (`[seed]`) holds assumptions about the standard seed and reports INFO "not applicable" when row A00 detects labelled fixture content. B04 and B33 now assert the approved rules (offered topic only when the recommendation isn't ready; readiness follows the threshold), which is stricter than before because the rule is checked in both branches across dev and fixture. New A14d: on-sale badges are approved, showcases are owned, ≤ limit and contiguous. |
| D-49 | **Admin conflict detection without schema change:** `expected_revision` (existing `revision`) for lessons and questions; `expected_version` = `updated_at` (moved forward ≥ 1 ms on every update) for topics and badges. Compared after `FOR UPDATE`; mismatch → `409 edit_conflict` with the current token. Status changes also require the token. |
| D-50 | **Contract corrections (topics):** titles, slugs, positions and skill groups are not editable (approved curriculum); topic `status` accepts only `draft` and `available` (`retired` would remove a curriculum topic). `summary` is editable. |
| D-51 | **Price approval record:** the approving administrator, exact price and time live in the append-only `audit_log` (`action = update`, `operation = approve_price`); `GET /admin/badges` derives `price_approval` from it while `price_status = approved`. No new columns were needed. |
| D-52 | **Snapshot coherence fix:** quiz and assessment starts read candidate question rows with `LOCK IN SHARE MODE` (question rows only); admin question edits lock the question row before its choices. Found by inspection (consistent-read pool + current-read `INSERT … SELECT` of choices), proven by a negative control, fixed and re-tested. |
| D-53 | **Question rules:** drafts may be incomplete; `active` requires 2–6 choices, exactly one correct, non-empty stem and explanation; labels `A`… contiguous; topic/usage fixed after draft (`tagging_locked`); used choices can't be removed; retired is final. |
| D-54 | **Readiness guards:** `available` needs `content_complete`; lesson unpublish/retire on an available topic refused; retiring below 10 needs `confirm` and drafts the topic in the same transaction; retiring below an assessment group quota is refused without override. |
| D-55 | **Artwork path:** `/hunter-system/assets/badges/<name>.png|webp`, served statically by Apache with handlers disabled; `image_ref` limited to `badges/<name>.(png|webp)` or `null` (stored as a `placeholder/` reference). No uploads, no SVG. |
| D-56 | **Admin transactions retry deadlocks** (`Database::txRetry`, 3 attempts) because concurrent assessment-question retirements can legitimately deadlock across the pool scan. |
| D-57 | **Tracking endpoints** completed: student detail (placement, topics, rank history), per-student logins and sessions, all login events with filters; no coins, hashes or session references. |
| D-58 | **Test separation:** the dev admin suite performs reads and refused mutations only, guarded by a content fingerprint and audit count; every successful content change is tested in the fixture database, whose touched topics are restored to `available` in a `finally` block. |
| D-59 | **Out-of-band corrections are documented, not audited.** `audit_log` records administrator API actions only (its FK requires an admin user). A correction made by the developer's database-admin connection (IR-1, question 106) is recorded in `progress.md` (incident record) and is not inserted into `audit_log`, so the log never attributes a change to someone who did not make it. Existing audit rows are never altered. |
| D-60 | **Mutating tests stay on the fixture database.** Dev suites may only read and send requests that are refused before any write; they verify this with content fingerprints and audit counts. The fixture suite covers the assessment-pool protection (Q8, skill group G6 at its quota). |
| D-61 | **Frontend stack:** React 19, React Router 7, Vite 8 (the installed Node 24/npm 11); no UI framework, state library or Markdown library. `playwright-core` (test-only) drives the installed Chrome. |
| D-62 | **Serving:** build into `frontend/dist`, static files under `/hunter-system/static/`, router basename `/hunter-system`. Apache serves the build through **allowlists** (hashed files; SPA fallback only for the app's route prefixes), replacing the Milestone-1 catch-all that would have answered private URLs with the app page. |
| D-63 | **Client security:** session cookie only (HttpOnly), CSRF token in memory, nothing in web storage; no automatic retries; lesson Markdown rendered to React elements with no HTML insertion. |
| D-64 | **Answer saving:** one ordered save queue per attempt; visible per-answer state; a failed save blocks navigation away from that question; submission waits for pending saves. |
| D-65 | **Administrators** signing in to the student app land on a single "Administrator interface coming soon" page (no redirect loop) until the admin UI milestone. |
| D-66 | **Browser-test data:** Part A (Apache, dev DB) creates only student/admin test accounts and their attempts (as earlier suites do); purchases, approved badges and showcases run against the fixture DB through `vite preview` with `HUNTER_API_TARGET`. |
| D-67 | **Milestone 7 admin routes:** `/admin` replaces the temporary landing page; `/admin-pending` redirects there for old links. Existing student onboarding/role routing remains. Admin overview derives counts only from existing topic, badge, question and student endpoints. No backend or schema change. |
| D-68 | **Administrator drafts and conflicts:** page-local original record and editable draft; every write sends the original server revision/version. A 409 preserves input and blocks further writes until deliberate server comparison/reload. Reload explicitly discards the draft; no merge, token substitution or automatic stale-write retry. Native confirmation covers availability, approval, retirement and dirty navigation. |
| D-69 | **Dirty navigation:** retain React Router 7, using its data router (`createBrowserRouter`/`RouterProvider`) so `useBlocker` protects SPA/history navigation. `beforeunload` protects refresh/close; logout warns separately and is single-flight. No new runtime dependencies. Admin data lives only in mounted role/identity-keyed pages, with abortable loads and no shared content cache. |
| D-70 | **Administrator test boundaries:** `test:admin` checks fixture database/account identity before creating a random fixture administrator and requires that account to log in to the fixed fixture API on 8098 before any content write. No target environment override. `test:admin:ui` explicitly simulates the API and is not integration evidence. `admin-readonly.mjs` reuses a historical b6 test admin, blocks every dev admin mutation in the browser, and compares content/audit checksums. |
| D-71 | **Scoped test access:** original private folders/ACLs/secrets remain untouched. Test-only setup protects `%TEMP%\HunterSystem-M7-Verification` for the actual token SID, SYSTEM and Administrators before writing new application credentials. It never uses inherited `USERNAME` as the executing identity and refuses broad directory targets. No sandbox/elevation/security-setting change. |
| D-72 | **Preserve both test content profiles:** reuse the complete M3 fixture as `hunter_m7_fixture`; one-time seed-profile `hunter_system_m7_seed_fixture` as `hunter_m7_seed` supports the original student draft-content/empty-store journeys and missing-lesson creation. Each new account gets the existing 57 application grants on its own fixture only. No original account rotation/grant changes; no database reset. Browser helpers use restricted credentials instead of root. |
| D-73 | **Attest the real test backend:** loopback-only test router refuses dev/unknown identity pairs; test-only `__test/identity` reads actual PDO database/account names. API suites, browser contexts and student preview proxies require exact fixed-URL/config identity agreement before writes. Six negative controls cover dev, wrong URL/account and mismatched server. The normal Apache API does not expose the test endpoint. |
| D-74 | **Live versus simulated evidence:** real admin mode uses server-generated validation, pool, stale-edit and idle-expiry responses (no response stubs); `--mock` is separate. Full student regression runs with `--isolated` against real seed/complete fixtures and retains its established controlled network-fault cases. Dev content/audit fingerprints must remain identical. Milestone 7 verification passes 15 API integration, 32 API fixture, 58 admin browser and 31 student browser checks; simulated 49 stays separately labelled. |
| D-75 | **Private course source:** topics 3–18 are authored as Markdown, structured quiz banks and explicit executable validation manifests under denied `content/`. Python 3.14.8 validates 163 reviewed cases in fresh temporary directories; answer banks never enter public frontend assets. Topics 1–2 and placement pools are preserved. |
| D-76 | **Audited course imports:** use authenticated existing admin APIs, exact revisions/versions and stable source-key receipts. Full baseline preflight rejects changed records/pools before writes; repeated completed apply is a no-op. Exclude derived student usage counters from content edit signatures. Crash gaps fail closed and require deliberate audit/receipt reconciliation. Fixture-only publication retires placeholders without deleting history. |
| D-77 | **Attribution and review boundary:** draft creation is authorized but dev publication is not. Dev import waits for an explicitly identified authenticating administrator; do not attribute course changes to historical test admins or invent an actor. Dedicated actor creation uses the established CLI with explicit identification/authorization. Human educational review precedes separate publication approval. |
| D-78 | **Badge proposals remain proposals:** six original cosmetic names, descriptions and artwork; proposed prices total240 versus max180 earned coins, with alternative total180. No rewards, repeat earnings, sample retirement or dev catalogue changes. User chooses names/artwork/prices and retention policy before authenticated import/approval/publication. |
| D-79 | **Badge asset boundary:** editable original SVGs, manifest and local contact sheets stay private under content/badges. Only six intended transparent PNG exports use the existing restricted assets/badges static path. No uploads, copied logos, character artwork or Hunter rank letters. |
| D-80 | **Milestone 8B attribution:** create no administrator and import no course content. Reuse an existing isolated fixture actor only for labelled badge rehearsal; never attribute real catalogue/course changes to historical test accounts. Document authentic administrator CLI setup and interactive secret prompts for the user's future execution. |
| D-81 | **User-approved revised badges:** Shadow Recruit10, Shadow Soldier15, Shadow Knight20, Elite Shadow Knight30, Shadow Commander45, Shadow Monarch60 (180 total). Theme-inspired application cosmetic titles, not official anime ranks or Python mastery certification; E–S ranks separate. Retain original geometry, update labels/descriptions/assets. Four sample retirements authorized through audited API only. New badges stay off sale; course publication still unapproved. |
| D-82 | **AI-assisted review and local scope:** all18 topics reviewed; foundation swap and explanatory/prerequisite defects corrected in private sources, preserving existing IDs/snapshots. No human-review claim. Authentic admin plus logical backup required for dev batch; exact-version importers fail closed. Current revised live checks blocked by stopped/access-denied local services, not claimed passed. Final stage is localhost journeys/basic security; deployment/public exposure/external testing explicitly out of scope. |
| D-83 | **Local publication approved:** user approved reviewed18-topic course, Shadow Recruit10/Soldier15/Knight20/Elite Shadow Knight30/Commander45/Monarch60, explicit admin price approvals/sale and audited retirement of four samples. No deployment/public exposure. Real authentic admin, verified logical backup, preserved-history fingerprints and isolated rehearsal precede dev mutations. Service/auth blockers mean no activation claim. |
| D-84 | **Focused acceptance security fixes:** Logger omits untrusted exception messages and argument values while retaining ID/type/code/frame locations. Request requires an actual JSON object, rejecting [] while accepting {}. Both reproduced before fixes and pass focused regression. Current live regression remains blocked; do not call the project fully secure/accepted. |
| D-85 | **Resumed final fixture acceptance:** existing user-started Apache/MariaDB reused with protected scoped fixture configs; approved course/badge API activation rehearsed and live journeys pass to documented limits. User defers authentic dev login/activation until afterward. Permanent retirement is not reversed: fixture harness mistake repaired by audited equivalent creation, original rows retained, explicit source-equality reconciliation. No schema/product feature added. Aria Errcode22 privilege-write failure stops legacy provisioning/fault-injection rather than prompting recovery/global ACL changes. No deployment or full-acceptance claim; see final-local-live-results.md. |
