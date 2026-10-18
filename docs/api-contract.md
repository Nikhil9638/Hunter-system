# Hunter System: API Contract (v1)

**Last updated:** 2026-10-14
**Status:** Milestones 1-5 are **implemented and tested** (marked **[implemented]**); the whole v1 contract is implemented:
- **Milestone 1:** `GET /health`, the four `/auth/*` endpoints and `GET /admin/students`.
- **Milestone 2:** onboarding, the placement assessment, `GET /me/dashboard` and `GET /topics`.
- **Milestone 3:** lessons, topic quizzes (start/resume, answers, submit, result, history), first-completion rewards, next-topic unlock, rank promotion, `GET /me/rank-history` and `GET /me/coins`.
- **Milestone 4:** badge store, purchases, `GET /me/profile` and `PUT /me/showcase`.
- **Milestone 5:** administrator content management (topics, lessons, questions, badges, price approval), audit history and the remaining student-tracking endpoints.

Everything else is a reviewed draft. Database: schema `2026.10.14-3`. Architecture and local setup: `architecture.md`.

**Local API URL:** `http://localhost/hunter-system/api/v1` (also `https://localhost/hunter-system/api/v1` with XAMPP's self-signed certificate).

**Client integration (2026-10-15):** the Milestone 7 React administrator interface consumes
§9–§10 without changing endpoints or response behavior. Existing revisions/versions are
sent unchanged. UI conflicts retain drafts and require deliberate comparison/reload;
real fixture-browser mutation verification is complete (58 checks, including actual stale
conflicts, pool protection, approvals/audit and idle expiry; see `progress.md`). No production
API endpoint, authorization rule or response behavior changed during verification.

---

## 1. Conventions

| Item | Rule |
|---|---|
| Base URL | `/hunter-system/api/v1` (same origin as the React app) |
| Format | JSON, UTF-8. Request bodies need `Content-Type: application/json` (otherwise `415`); maximum 64 KB. |
| Authentication | Session cookie `HSSESSID` (HttpOnly, SameSite=Strict). No tokens in URLs or `localStorage`. |
| CSRF | Every authenticated `POST`, `PUT`, `PATCH` and `DELETE` sends `X-CSRF-Token: <token from register, login or /auth/me>`. Missing or wrong → `403 csrf_failed`. Login and registration need no token. |
| Origin | Any non-GET request carrying an `Origin` header must come from an allowed origin (`http(s)://localhost`, `http(s)://127.0.0.1`; `APP_ORIGINS`). Otherwise → `403 csrf_failed`. This also blocks cross-site login and registration. |
| Unknown fields | JSON bodies may contain only the documented keys. Anything else (for example `role`) → `400 validation_failed` with `fields.<key> = "unknown_field"`. |
| JSON object boundary | Required object bodies accept `{}` but reject `[]`, other arrays and scalar/null JSON with `400 invalid_json`. Empty-array acceptance was corrected during local acceptance review; valid client object payloads are unchanged. Live regression remains pending service access. |
| Roles | `public`, `student`, `admin`. Student endpoints act only on the session's own user. |
| Times | UTC, ISO-8601 with milliseconds and `Z`, e.g. `"2026-10-14T15:30:00.000Z"`. |
| IDs | Integers. Attempt, question and badge IDs in URLs are checked against the session user. Another student's ID gives `404`, not `403`, so it doesn't reveal that the record exists. |
| Success | `200` (or `201` when something is created) with `{ "data": … }`. |
| Error | `{ "error": { "code": "snake_case", "message": "Human readable", "details": { … } } }`. |
| Correct answers | **Never** in any response before the attempt is submitted (C-28). After submission, the review includes correct answers and explanations (A-01). |
| Wording | Quiz results carry `"label": "Quiz performance"` and are never called mastery (A-01). Placement carries `"label": "Starting point from a short placement check, not proof of mastery"` (P-20). |

### 1.0 Client integration notes (student interface, Milestone 6)
- The browser app is same-origin (`/hunter-system/`), so cookies flow automatically (`credentials: 'same-origin'`); the CSRF token is held in memory only and refreshed from every register/login/`/auth/me` response.
- Start-up: `GET /auth/me` → `401` means anonymous; `user.role` and `user.onboarding_state` decide the first screen.
- Mutations are never retried automatically. After a network failure on a submission or purchase the UI asks the user to reload: the server's stored result (`already_submitted`) or ownership (`already_owned`) is then authoritative.
- Any `401` with `session_expired`, `session_ended` or `not_authenticated` on an authenticated call ends the client session; server-saved progress is unaffected.
- `image_url: null` is rendered as a neutral placeholder; an empty `GET /badges` list is a normal state.
- In development (`npm run dev`) the Vite proxy forwards `/hunter-system/api` to Apache and presents the Apache origin; requests sent directly to Apache from another origin are still refused (`403 csrf_failed`, verified).

### 1.1 Common error codes

| HTTP | `code` | When |
|---|---|---|
| 400 | `validation_failed` | Field errors in `details.fields` (`{ "email": "invalid_format" }`) |
| 400 | `invalid_json` | Body isn't valid JSON (or isn't valid UTF-8), or isn't a JSON object |
| 401 | `not_authenticated`, `session_expired`, `session_ended` | No session; idle limit passed; logged out elsewhere or account deactivated |
| 403 | `forbidden`, `csrf_failed` | Wrong role; missing or invalid CSRF token |
| 404 | `not_found` | Unknown route or resource, or not owned by this student |
| 405 | `method_not_allowed` | Known path, wrong method (`Allow` header) |
| 409 | `conflict` and the specific codes listed per endpoint | Business-rule refusals |
| 413 | `payload_too_large` | Body over 64 KB |
| 415 | `unsupported_media_type` | Body without `application/json` |
| 429 | `too_many_attempts` | Login throttling (`Retry-After` header) |
| 500 | `internal_error` | Unexpected; `details.error_id` matches the entry in `hunter-system-private\logs\app.log` |
| 503 | `service_unavailable` | Server misconfigured (`intl` or Argon2id missing); the API fails closed |

---

## 2. Health and authentication (A-07)

### `GET /health`: public **[implemented]**
`200` → `{ "data": { "status": "ok", "checks": { "database": true, "schema": true, "intl": true, "argon2id": true } } }`. `503` with `"status": "degraded"` if any check is false. Booleans only: no versions, paths or settings.

### `POST /auth/register`: public **[implemented]**
Request: `{ "email": "asha@example.org", "display_name": "Asha", "password": "correct horse battery staple" }`

| Rule | Error |
|---|---|
| Only `email`, `display_name` and `password` are accepted; `role` or anything else is rejected | `400` (`<key>`: `unknown_field`) |
| Email trimmed, Unicode NFC, lower-cased, at most 254 chars, valid format | `400 validation_failed` (`email`: `required`, `invalid_format`) |
| Display name NFC and trimmed, 1–80 characters, no control characters | `400` (`display_name`: `required`, `invalid`) |
| Password **NFKC-normalized**, then 12–256 characters and at most 1,024 bytes; valid UTF-8; spaces and Unicode allowed; no composition rules; **never truncated** (Argon2id) | `400` (`password`: `required`, `too_short`, `too_long`, `invalid_encoding`) |
| Email not already registered | `409 email_taken` |

`201` → `{ "data": { "user": UserSelf, "csrf_token": "…" } }`. The student is logged in, with a new session ID, a `user_sessions` row and a `login_events` success row. The role is always `student`. The duplicate check is case-insensitive, including a concurrent race (unique key).

Sets `HSSESSID` (`HttpOnly; SameSite=Strict; Path=/hunter-system/`, plus `Secure` on HTTPS).

### `POST /auth/login`: public **[implemented]**
Request: `{ "email": "…", "password": "…" }` (only these keys)

- `200` → `{ "data": { "user": UserSelf, "csrf_token": "…" } }`
- `401 invalid_credentials` for an unknown email, an invalid email format, a wrong password, or a password that can't be valid. All return the identical body, with similar timing (a dummy Argon2id check). `login_events` records `unknown_account` (no identity) or `wrong_password` (user known); typed values are never stored.
- `403 account_deactivated`, only after a correct password (recorded as `account_deactivated`).
- `429 too_many_attempts` with `Retry-After` (seconds) and `details.retry_after_seconds`:
  - **Email key:** 5 failures in 15 minutes lock that email for 15 minutes.
  - **IP key:** 30 failures in 15 minutes lock that IP.
  - **While locked:** the password isn't checked, so even a correct one gets 429.
  - **Storage:** only HMAC-keyed hashes, deleted within 24 hours (architecture §5.6).
- On success: a **new session ID**, and any previous tracked session of this browser is ended (`logged_out`). The email's throttle counter is cleared.

### `POST /auth/logout`: any authenticated user **[implemented]**, CSRF required
`200` → `{ "data": { "logged_out": true } }`. Ends the session row (`logged_out`), deletes the server-side PHP session and clears the cookie. The old cookie then gives `401`. No body is needed; an empty body is fine.

### `GET /auth/me`: any authenticated user **[implemented]**
`200` → `{ "data": { "user": UserSelf, "csrf_token": "…" } }`. React calls this on start-up.

**Errors (all authenticated routes):**
- `401 not_authenticated`: no session.
- `401 session_expired`: 30 idle minutes passed. The session ends with `ended_at` = last activity + 30 min.
- `401 session_ended`: logged out, or the account isn't active.

**Activity:** each authenticated request records activity at most once per 60 seconds.

```jsonc
// UserSelf
{ "id": 12, "email": "asha@example.org", "display_name": "Asha", "role": "student",
  "onboarding_state": "not_started" }      // students only: not_started | experience_chosen | assessment_submitted
```

---

## 3. Onboarding

All §3–§5 endpoints require a **student** session: an administrator gets `403 forbidden`, and no session gets `401`. State-changing calls need `X-CSRF-Token`.

### `GET /experience-levels`: student **[implemented]**
`200` → `{ "data": [ { "id": 1, "code": "complete_beginner", "label": "Complete beginner" }, { "id": 2, "code": "basic", … }, { "id": 3, "code": "intermediate", … }, { "id": 4, "code": "master", "label": "Master" } ] }` (sort order).

### `PUT /me/experience`: student **[implemented]**, CSRF
Request: `{ "experience_level_id": 4 }` (a JSON **integer**; no other keys).

| Case | Response |
|---|---|
| Valid, while `onboarding_state = not_started` | `200` → `{ "data": { "onboarding_state": "experience_chosen" } }` |
| Missing / not an integer / unknown ID / extra key | `400 validation_failed` (`experience_level_id`: `required`, `invalid`; or `<key>`: `unknown_field`) |
| Already chosen | `409 experience_already_chosen` (it can't be changed) |

The level is stored only as self-description. It never affects grading, rank or recommendation (C-05; tested with "Master" at every placement result).

---

## 4. Placement assessment (A-03, O-08)

### `POST /assessment`: student **[implemented]**, CSRF (start or resume)
No body, or `{}`.

| Case | Response |
|---|---|
| New attempt created | `201` → `AssessmentAttempt` with `"resumed": false` |
| An attempt is already in progress (any tab, or a simultaneous start) | `200` → the **same** attempt with its saved answers, `"resumed": true` |
| No experience level chosen | `409 choose_experience_first` |
| Placement already recorded | `409 placement_already_recorded` (one official placement) |
| Question pool can't satisfy the policy | `503 assessment_unavailable` |

**Question selection:**
- Exactly 10 distinct **valid** questions (active, assessment use, 2 or more choices, exactly one correct).
- Split 2/2/2/2/1/1 across G1–G6 by the current assessment policy, chosen at random within each group (CSPRNG).
- Positions are ordered by group: 1–2 G1, 3–4 G2, 5–6 G3, 7–8 G4, 9 G5, 10 G6.
- The question **and all its choices are snapshotted** with the attempt.

```jsonc
// AssessmentAttempt: never contains correct flags, explanations or source IDs
{ "attempt_id": 41, "status": "in_progress", "question_count": 10, "answered_count": 2, "started_at": "…Z", "resumed": true,
  "questions": [
    { "position": 1, "skill_group": "G1", "stem": "Which line correctly prints the word Python?", "code_snippet": null,
      "choices": [ { "id": 901, "label": "A", "text": "print(Python)" }, … ],
      "selected_choice_id": 903 } ] }
```

### `PUT /assessment/{attemptId}/answers/{position}`: student **[implemented]**, CSRF
Request: `{ "choice_id": 903 }`: an integer snapshot-choice ID of **that** position. Answers may be changed freely until submission.

| Case | Response |
|---|---|
| Saved or changed | `200` → `{ "data": { "position": 1, "selected_choice_id": 903 } }` |
| `choice_id` missing or not a positive integer | `400 validation_failed` |
| The choice belongs to another position, another attempt, or doesn't exist | `400 invalid_choice` |
| The attempt isn't this student's own assessment; position outside 1–10 | `404 not_found` |
| Already submitted | `409 attempt_finalized` (nothing changes) |

Lock order: student profile row, then attempt row (the same as submission). Tested in both orders: a save that arrives first is graded; a save that arrives after submission is refused.

### `POST /assessment/{attemptId}/submit`: student **[implemented]**, CSRF
No body, or `{}`.

| Case | Response |
|---|---|
| All 10 answered | `200` → `AssessmentResult` with `"already_submitted": false`. A single transaction grades the snapshot in PHP; stores the result, placement rank, current Hunter rank and placement history row; and creates topic availability. |
| Repeat (double click, retry, simultaneous) | `200` → the stored `AssessmentResult` with `"already_submitted": true`. No side effects are repeated. |
| Fewer than 10 answered | `409 attempt_incomplete`, `details.unanswered_positions: [10]` |
| Not this student's own assessment | `404 not_found` |

**Rules applied:**
- **Placement mapping:** 0–2 E, 3–4 D, 5–6 C, 7–8 B, 9 A, 10 S. The Hunter rank starts equal to the placement rank.
- **Recommendation:** the first topic of the earliest group with a missed answer; all correct → topic 18. It's always stored.
- **Fallback:** if the recommended topic isn't ready, the nearest **earlier** ready topic is stored separately as `offered_topic` (null if there is none, or if the recommendation is ready).
- **Unlocks:** topics up to the recommended position are unlocked (`unlock_source: "placement"`). Nothing is marked passed.
- **Coins:** none (`coins_awarded: 0`, no ledger row).

```jsonc
{ "attempt_id": 41, "already_submitted": false, "submitted_at": "…Z",
  "correct_count": 4, "question_count": 10,
  "placement_rank": { "code": "D", "name": "D-Rank", "title": null },
  "label": "Starting point from a short placement check, not proof of mastery",
  "recommended_topic": { "id": 7, "position": 7, "title": "Conditions", "ready": false },
  "offered_topic":     { "id": 2, "position": 2, "title": "Variables and assignment", "ready": true },
  "groups":  [ { "code": "G1", "name": "Python basics", "asked": 2, "correct": 2 }, … ],          // 6 groups
  "topics":  [ { "id": 3, "title": "Basic data types", "evidence": "sampled", "asked": 1, "correct": 1 },
               { "id": 17, "title": "File handling", "evidence": "not_assessed", "asked": 0, "correct": 0 }, … ],   // all 18; no percentages
  "experience_level": { "code": "master", "label": "Master" },     // shown beside the result, never used for it
  "coins_awarded": 0,
  "review": [                                                       // only after submission (O-08)
    { "position": 1, "skill_group": "G1", "topic": "Python introduction", "stem": "…", "code_snippet": null,
      "choices": [ { "id": 901, "label": "A", "text": "print(Python)", "correct": false }, { "id": 903, "label": "C", "text": "print(\"Python\")", "correct": true }, … ],
      "selected_choice_id": 903, "is_correct": true, "explanation": "Text must be a string in quotes, …" } ] }
```

`ready` reflects **current** content readiness; the stored recommendation and offer don't change when content changes.

### `GET /assessment/result`: student **[implemented]**
`200` → the stored `AssessmentResult`, including `review` but without `already_submitted`. Before placement → `404 not_found`.

---

## 5. Dashboard, topics and lessons

### `GET /me/dashboard`: student **[implemented]**
```jsonc
{ "data": {
  "display_name": "Asha",
  "onboarding_state": "assessment_submitted",            // not_started | experience_chosen | assessment_submitted
  "experience_level": { "code": "master", "label": "Master" },              // null before chosen
  "placement_rank":   { "code": "D", "name": "D-Rank", "title": null },      // null before placement; never changes
  "hunter_rank":      { "code": "D", "name": "D-Rank", "title": null },      // current rank; null before placement
  "assessment": { "state": "submitted", "attempt_id": 41, "can_start": false },   // not_started | in_progress | submitted
  "recommended_topic": { "id": 7, "position": 7, "title": "Conditions", "ready": false },   // null before placement
  "offered_topic":     { "id": 2, "position": 2, "title": "Variables and assignment", "ready": true },
  "current_topic":     { "id": 1, "title": "Python introduction", "content_ready": true },  // first unlocked, not passed
  "coins": 0,                                              // own balance (cached ledger balance)
  "topics_passed": 0, "topics_total": 18,                  // distinct topics with a passed quiz (A-06)
  "next_rank": { "code": "C", "name": "C-Rank", "topics_required": 6, "topics_remaining": 6 } } }   // current rank policy; null at S or before placement
```

### `GET /topics`: student **[implemented]**
`200` → 18 items in curriculum order. **Availability for this student** (`unlocked`) and **content readiness** (`content_ready`) are separate, so an unlocked topic may still lack content:

```jsonc
{ "id": 7, "position": 7, "title": "Conditions", "skill_group": "G3",
  "unlocked": true, "unlock_source": "placement",        // placement | passed_previous | null
  "content_ready": false,                                 // published lesson + >= 10 active valid quiz questions
  "status": "available",                                  // locked | available | in_progress | completed | passed (derived)
  "lesson_visited": false, "completed_attempts": 0, "best_correct": null, "first_passed_at": null,
  "open_attempt_id": null }
```

Before placement, every topic is `locked` with `unlocked: false`.

### Topic access rules (lessons and quizzes)
Checked in this order, each with its own error, so availability and content readiness stay separate:

| Check | Error |
|---|---|
| Placement assessment submitted | `409 placement_required` |
| Topic exists (and the id is a positive integer) | `404 not_found` |
| Topic unlocked **for this student** | `409 topic_locked` |
| Content ready: topic `available`, published lesson, at least 10 active valid quiz questions (≥ 2 choices, exactly one correct) | `409 topic_content_not_available` |

A student may read the lesson first or go straight to the quiz; neither is required.

### `GET /topics/{topicId}/lesson`: student **[implemented]**
`200` → `{ "data": { "topic_id": 1, "title": "…", "body_markdown": "…", "revision": 1 } }` (exactly these fields). Records a lesson visit: first visit time (kept), last visit time, visit count. A visit never completes or passes anything (P-06) and pays no coins.

---

## 6. Topic quizzes (A-01, A-02, A-04)

### `POST /topics/{topicId}/quiz-attempts`: student **[implemented]**, CSRF (start or resume)
Request: `{ "started_via": "lesson_first" | "direct" }` (C-10). Missing → `400` `fields.started_via = "required"`; other values → `"invalid"`; other keys → `"unknown_field"`.

| Situation | Response |
|---|---|
| Access rule fails (see above) | `409 placement_required`, `404`, `409 topic_locked` or `409 topic_content_not_available`; **no attempt is created** |
| An attempt is already in progress for this topic (any tab, or simultaneous starts) | `200`, the same attempt with its saved answers, `"resumed": true`; the original `started_via` is kept |
| Otherwise | `201`, a new attempt (`attempt_number` + 1) with 5 distinct questions, `"resumed": false` |

**Question choice.** 5 distinct active valid quiz questions of the topic: questions this student has **never been assigned in this topic** first, then previously seen ones when fewer than 5 unseen remain; random within each group (`random_int`). Each question, its choices with the correct flag, and its explanation are **snapshotted** for the attempt.

```jsonc
// QuizAttempt (before submission: no correct flags, no explanations, no question-bank ids)
{ "attempt_id": 77, "topic_id": 2, "attempt_number": 3, "status": "in_progress", "started_via": "direct",
  "question_count": 5, "answered_count": 1, "started_at": "…Z",
  "questions": [ { "position": 1, "stem": "What does this code display?", "code_snippet": "x = 5\nx = x + 2\nprint(x)",
                   "choices": [ { "id": 1201, "label": "A", "text": "5" }, … ], "selected_choice_id": null } ],
  "resumed": false }                                     // only on the POST response
```

### `GET /quiz-attempts/{attemptId}`: student **[implemented]**
`200` → `QuizAttempt` while in progress, or `QuizResult` once submitted (without `already_submitted`). Another student's attempt, or a placement-assessment id → `404`.

### `PUT /quiz-attempts/{attemptId}/answers/{position}`: student **[implemented]**, CSRF
Request: `{ "choice_id": 1203 }`

- `200` → `{ "data": { "position": 1, "selected_choice_id": 1203 } }` (an answer may be changed any number of times before submission)
- `400 validation_failed` for a missing or non-integer `choice_id`; `400 invalid_choice` if the choice belongs to another question.
- `404` for a position outside 1-5 or another student's attempt.
- `409 attempt_finalized` once the attempt has been submitted. Saving and submitting take the same locks in the same order (profile row, then attempt row): an answer queued **before** a submit is graded; one queued **after** it gets `409` and changes nothing.

### `POST /quiz-attempts/{attemptId}/submit`: student **[implemented]**, CSRF
- `409 attempt_incomplete` (`details.unanswered_positions`) unless all 5 are answered (P-09). Nothing is written.
- **Idempotent:** repeats, including simultaneous ones, return the same stored result with `"already_submitted": true` and no side effects. Only one reward is ever paid (C-29).

**One transaction** (locks: student profile row, then attempt row): grade every answer from the snapshot → `quiz_results` with the current grading policy version → finalize the attempt → if passed: unlock the next topic (none after topic 18; never re-locks; does not make its content ready) and apply rank progression → if this student has no claim for this topic: reward claim + ledger credit. Any failure rolls the whole submission back (tested by refusing the ledger insert).

| Correct | 5 | 4 | 3 | 2 | 1 | 0 |
|---|---|---|---|---|---|---|
| Grade, percent (grading policy v2) | A 100 | B 80 | C 60 | D 40 | E 20 | F 0 |
| Passed (pass mark 3) | yes | yes | yes | no | no | no |

`200` → `{ "data": QuizResult }`

```jsonc
{ "attempt_id": 77, "topic_id": 2, "attempt_number": 3, "already_submitted": false, "submitted_at": "…Z",
  "label": "Quiz performance",
  "correct_count": 3, "question_count": 5, "grade": "C", "percent": 60, "passed": true,
  "review": [                                                     // shown only after submission (A-01)
    { "position": 1, "stem": "…", "code_snippet": "…",
      "choices": [ { "id": 1201, "label": "A", "text": "5", "correct": false }, { "id": 1202, "label": "B", "text": "7", "correct": true }, … ],
      "selected_choice_id": 1202, "is_correct": true, "explanation": "x + 2 is calculated with the old value …" } ],
  "coins_awarded": 10,                                                                  // 0 when nothing was paid
  "reward": { "coins": 10, "reason": "topic_first_completion", "balance_after": 20 },   // null when not earned by THIS attempt
  "unlocked_topic": { "id": 3, "title": "Basic data types", "content_ready": false },     // null if this attempt unlocked nothing
  "promotion": { "from": "E", "to": "D", "topics_passed": 3, "policy_version": 1 } }    // null if no rank change
```

`reward`, `unlocked_topic` and `promotion` are **facts of this attempt at submission time**, read back from the stored claim and ledger row, the unlock source and the rank-history row. They never change later: `balance_after` is the balance right after this credit, not the current balance (`GET /me/coins` and the dashboard show current values). A later re-pass shows `unlocked_topic: null`; a topic already unlocked by placement is never reported as unlocked by a quiz.

The review comes from the attempt's own snapshot, so it shows exactly what the student saw, even if the question bank changed later.

### `GET /topics/{topicId}/quiz-attempts`: student **[implemented]**
`200` → this student's attempts for the topic, oldest first: `[ { "attempt_id", "attempt_number", "status", "started_via", "started_at", "submitted_at", "correct_count", "grade", "percent", "passed" } ]` (result fields `null` while in progress). Needs placement (`409 placement_required`) and an existing topic (`404`); a locked or not-ready topic simply returns its (empty) history.

---

## 7. Rank and coins

### Rank progression (rank progression policy v1)
| Distinct topics passed | 0-2 | 3-5 | 6-8 | 9-11 | 12-14 | 15-18 |
|---|---|---|---|---|---|---|
| Earned rank | E | D | C | B | A | S |

Every distinct passed topic counts, including topics below the placement starting point. **Current rank = the highest of placement, previously achieved and earned**, so it never decreases: a student placed C who has passed 3 topics stays C. A promotion is written only when the earned rank is higher than the current one, as a `rank_history` row citing the passing attempt, the distinct count and the policy version, in the same transaction as the result. Repassing a topic never increases the count.

### `GET /me/rank-history`: student **[implemented]**
`200` → oldest first:
```jsonc
[ { "at": "…Z", "reason": "placement", "from": null, "to": "D" },
  { "at": "…Z", "reason": "promotion", "from": "D", "to": "C", "topics_passed": 6,
    "evidence_attempt_id": 90, "policy_version": 1 } ]
```
Placement rows carry only `at`, `reason`, `from`, `to`. The rank never decreases (A-04).

### `GET /me/coins`: student **[implemented]**
`200` → `{ "data": { "balance": 20, "entries": [ { "id": 41, "at": "…Z", "direction": "credit", "amount": 10, "balance_after": 20, "type": "topic_reward", "topic_id": 2 }, … ] } }`. Own entries only, newest first. `?limit=` 1-100 (default 50); `?before_id=<entry id>` for the next page; other values → `400 validation_failed`. `balance` is the cached wallet, which the database keeps equal to the ledger. Coins never change rank (C-18). Every entry has `topic_id` (topic rewards) and `badge_id` (badge purchases, `"direction": "debit"`); the other is `null`.

**Reward rule (reward policy v2):** 10 coins once per student per topic, for the **first completed** quiz of that topic **even if it failed**. Nothing for starting, partial attempts, retries or lesson visits. Uniqueness is enforced by the database (`uq_reward_claims_once`); every credit has exactly one ledger row.

---

## 8. Badge store, profile and showcase (A-05)

**Contract corrections (decided 2026-10-14, before implementation; see `decisions.md` D-41 to D-45):**
1. `GET /badges` lists **only** badges that are `on_sale` with an `approved` price. Owned badges that are off sale or retired are no longer listed in the store; they appear in `GET /me/profile` → `owned_badges`.
2. `image_url` is **nullable**. Final artwork isn't approved: every seeded `image_ref` is under `placeholder/`, and such references give `"image_url": null`. Clients show a neutral placeholder and never treat it as artwork.
3. `GET /me/profile` also returns `coins` (own balance), `showcase_limit`, and per-badge `image_url`, `status` and `showcased`.
4. `409 already_owned` carries the original purchase facts in `details`, so a retried purchase can be confirmed without a second debit.
5. `PUT /me/showcase` returns `{ "showcase": [...] }`, in the same item shape as the profile.

**Image references:** `image_ref` under `placeholder/` → `null`. Any other reference → `"/hunter-system/assets/" + image_ref`. That location is provisional until approved artwork and the frontend's asset folder exist (O-04); no asset is served yet.

### `GET /badges`: student **[implemented]**
`200` → the store, cheapest first (then by id). An empty array is a valid store; that is the live state while no badge price is approved.
```jsonc
[ { "id": 3, "name": "Loop Ranger", "description": "…", "image_url": null,
    "price": 60, "status": "on_sale", "owned": false, "affordable": true } ]
```
`affordable` = not owned and price ≤ the current balance. Draft, off-sale, retired and unapproved badges are never listed.

### `POST /badges/{badgeId}/purchase`: student **[implemented]**, CSRF
No body (an empty JSON object `{}` is also accepted); **the price and availability are read from the database inside the purchase transaction** (I-17).

**Transaction** (lock order: the student's profile/wallet row `FOR UPDATE`, then the badge row `LOCK IN SHARE MODE`): read ownership, availability, approval and price → check the balance → insert `badge_ownership` with `price_paid` = that price → insert the `badge_purchase` ledger debit (the database checks the amount equals `price_paid`, the running balance, and that it never goes negative). All or nothing. A concurrent catalogue change either commits before the purchase reads the badge (and is applied) or waits for it.

| Situation (checked in this order) | Response |
|---|---|
| Unknown badge | `404 not_found` |
| Already owned | `409 already_owned`, `details: { "badge_id": 3, "price_paid": 60, "purchased_at": "…Z" }`. **No debit.** A client whose earlier purchase response was lost treats this as confirmation. |
| Not `on_sale`, or price not approved (draft, off sale, retired) | `409 not_on_sale` |
| Balance below the price | `409 insufficient_coins`, `details: { "balance": 40, "price": 60 }` |
| Purchased | `200` → `{ "data": { "badge_id": 3, "name": "Loop Ranger", "price_paid": 60, "balance_after": 15, "purchased_at": "…Z" } }` |

Simultaneous purchases by one student are processed one at a time; the balance never goes negative and a badge is never paid twice. Purchases never change placement, Hunter rank or topic progress.

### `GET /me/profile`: student **[implemented]**
The authenticated student's own profile. No email or other account fields; other students' profiles aren't available in this version.
```jsonc
{ "display_name": "Asha",
  "experience_level": { "code": "master", "label": "Master" },                 // null before chosen
  "placement_rank": { "code": "D", "name": "D-Rank", "title": null },          // null before placement
  "hunter_rank": { "code": "D", "name": "D-Rank", "title": null },             // null before placement
  "topics_passed": 1, "topics_total": 18,
  "coins": 15,
  "showcase_limit": 3,
  "showcase": [ { "position": 1, "badge_id": 3, "name": "Loop Ranger", "image_url": null, "status": "retired" } ],
  "owned_badges": [ { "badge_id": 3, "name": "Loop Ranger", "description": "…", "image_url": null,
                      "price_paid": 60, "purchased_at": "…Z", "status": "retired", "showcased": true } ] }   // purchase order
```
`price_paid` is what the student actually paid, even after later price changes.

### `PUT /me/showcase`: student **[implemented]**, CSRF
Request: `{ "badge_ids": [3, 1] }`. 0 to `showcase_limit` (3, from the current app policy) **distinct owned** badge ids, in display order. `[]` clears the showcase.

- `200` → `{ "data": { "showcase": [ { "position": 1, "badge_id": 3, … }, { "position": 2, "badge_id": 1, … } ] } }`
- `400 validation_failed`: `fields.badge_ids` = `"required"`, `"invalid"` (not an array of positive integers), `"too_many"` or `"duplicate"`; other keys → `"unknown_field"`.
- `409 not_owned` (`details.badge_id`, the first unowned id in the request) if any badge isn't owned by this student.

The whole showcase is replaced in one transaction under the student's profile lock, so simultaneous updates are applied one after another and a refused update leaves the previous selection intact. Retired and off-sale badges the student owns may be showcased. Changing the showcase never costs coins or affects rank.

---

## 9. Administrator: tracking (C-21, A-06)

Every admin response leaves out password hashes, session hashes and **coin balances**.

### `GET /admin/students?query=&status=active|deactivated&recently_active=true|false&page=1&per_page=50`: admin **[implemented]**
- **Access:** student → `403 forbidden`; no session → `401`.
- **Parameters:** `query` is 1–100 characters (matches name or email); `page` is at least 1; `per_page` is 1–100. Invalid values → `400`.
- **Behaviour:** runs the expiry sweep first (architecture §5.4).
- **Response:** `{ "data": { "students": [ … ], "page": 1, "per_page": 50, "total": 6 } }`, with rows from `v_admin_student_overview`:
```jsonc
{ "user_id": 12, "display_name": "Asha", "email": "asha@example.org", "account_status": "active",
  "last_successful_login_at": "…Z", "last_activity_at": "…Z", "recently_active": false,   // 15-minute window
  "hunter_rank": "D-Rank", "current_topic": "Variables and assignment",
  "topics_passed": 1, "topics_total": 18 }
```
The interface says "recently active" and never "online" (C-22).

### `GET /admin/students/{userId}`: admin **[implemented]**
`404` unless the id is a student. No coins, hashes or session references.
```jsonc
{ "user_id": 12, "display_name": "Asha", "email": "asha@example.org", "account_status": "active", "registered_at": "…Z",
  "last_successful_login_at": "…Z", "last_activity_at": "…Z", "recently_active": false,
  "onboarding_state": "assessment_submitted",
  "experience_level": { "code": "master", "label": "Master" },
  "placement": { "rank": "D", "correct_count": 4, "submitted_at": "…Z",
                 "recommended_topic": { "id": 7, "title": "Conditions" }, "offered_topic": { "id": 2, "title": "…" } },   // null before placement
  "hunter_rank": "D", "topics_passed": 1, "topics_total": 18,
  "topics": [ { "id": 1, "position": 1, "title": "…", "status": "passed", "unlocked": true, "unlock_source": "placement",
                "lesson_visits": 2, "completed_attempts": 3, "best_correct": 4, "first_passed_at": "…Z" } ],
  "rank_history": [ { "at": "…Z", "reason": "placement", "from": null, "to": "D", "topics_passed": null } ] }
```

### `GET /admin/students/{userId}/logins?page=1&per_page=50`: admin **[implemented]**
`404` unless a student. Newest first, both lists paginated with the same `page`/`per_page` (1–100):
`{ "events": [ { "at": "…Z", "outcome": "success" } ], "events_total": 7, "sessions": [ { "started_at": "…Z", "last_activity_at": "…Z", "ended_at": "…Z", "end_reason": "logged_out" } ], "sessions_total": 3, "page": 1, "per_page": 50 }`. Runs the expiry sweep first.

### `GET /admin/login-events?outcome=&since=&page=1&per_page=50`: admin **[implemented]**
All login events, newest first. `outcome` ∈ `success`, `wrong_password`, `account_deactivated`, `unknown_account`; `since` is an ISO-8601 UTC time (`2026-10-14T00:00:00Z`, optional milliseconds). Invalid → `400`. Unknown-account attempts have `"user": null` (P-32): `{ "events": [ { "id": 91, "at": "…Z", "outcome": "unknown_account", "user": null }, { "id": 90, "at": "…Z", "outcome": "success", "user": { "id": 12, "display_name": "Asha", "role": "student" } } ], "page": 1, "per_page": 50, "total": 2 }`.

---

## 10. Administrator: content management (A-06) **[implemented]**

All endpoints: `auth = admin` (student → `403`, anonymous → `401`); every mutation needs `X-CSRF-Token`; bodies accept only the documented keys (`unknown_field`). Correct answers, drafts and the audit log are **never** reachable through student endpoints.

### 10.1 Rules shared by every mutation
- **One transaction** per request: the content change **and** its audit row(s) commit together or not at all. A refused or failed change leaves no content change and no audit row.
- **Optimistic concurrency (stale edits are refused):** every mutation of an existing record must send the token it last read:
  - lessons and questions → `expected_revision` (the integer `revision`; content edits increment it);
  - topics and badges → `expected_version` (the opaque string `version`; it changes on every update).
  A mismatch → `409 edit_conflict` with `details.current_revision` or `details.current_version`; nothing is written. Missing → `400 validation_failed` (`"required"`).
- **Lock order** (deadlock-free with student flows): topic row → lesson or question row → its choices; badge row alone. Admin writes never lock student profile rows. Student quiz/assessment starts lock the candidate question rows in **share** mode before copying them, so a start sees either the whole old or the whole new question (never an old text with new choices), and an edit waits for a start in progress (D-52).
- **Audit actions** (schema enum): `create`, `update`, `publish`, `unpublish`, `retire`. Badge price approval is `update` with `"operation": "approve_price"` in `after`. `before`/`after` hold the changed record's relevant fields (never credentials, tokens or configuration).

### 10.2 Topics (curriculum order, titles, slugs and skill groups are fixed)
| Method and path | Purpose | Rules and errors |
|---|---|---|
| `GET /admin/topics` | The 18 topics in curriculum order | Each: `id, position, slug, title, summary, skill_group, status, version, lesson` (`{status, revision}` or `null`), `lesson_published`, `quiz_pool_size`, `required_pool_size` (10), `content_complete` (published lesson **and** pool ≥ 10), `is_ready` (`content_complete` **and** status `available`: what students need), `students_unlocked` (availability is per student). |
| `PATCH /admin/topics/{id}` | `{ expected_version, summary?, status? }` | `status` ∈ `draft`, `available`. `available` needs `content_complete` → else `409 topic_not_ready` (`details: { lesson_published, quiz_pool_size, required_pool_size }`). `draft` hides the lesson and new quizzes; it never removes progress, attempts or results, and an attempt already in progress can still be answered and submitted. **Contract correction:** `title` is not editable (approved curriculum, C-04); `retired` is not offered in this version (it would remove a curriculum topic). Audit: `publish` / `unpublish` / `update`. |

### 10.3 Lessons (one per topic)
| Method and path | Purpose | Rules and errors |
|---|---|---|
| `GET /admin/topics/{id}/lesson` | The lesson in any status: `topic_id, lesson_id, title, body_markdown, status, revision, published_at, updated_at`; `404 not_found` if none | **Never records a student visit.** |
| `PUT /admin/topics/{id}/lesson` | Create or edit `{ expected_revision, title, body_markdown }` | Create (`201`): `expected_revision: 0` and no lesson yet (starts `draft`, revision 1); `0` when a lesson exists → `409 edit_conflict`. Edit: revision + 1; the status is unchanged (editing a published lesson changes what students read next). `title` 1–120 characters, `body_markdown` 1–100,000. Audit `create`/`update` with before/after title, body, revision. |
| `POST /admin/topics/{id}/lesson/publish` | `{ expected_revision }` | From `draft` or `retired` → `published` (sets `published_at`). Already published → `409 invalid_transition`. |
| `POST /admin/topics/{id}/lesson/unpublish` | `{ expected_revision }` | `published` → `draft`. If the topic is `available` → `409 topic_in_use_set_draft_first`. |
| `POST /admin/topics/{id}/lesson/retire` | `{ expected_revision }` | `draft`/`published` → `retired` (kept, never deleted; can be edited and published again). Same `topic_in_use_set_draft_first` rule. |

### 10.4 Questions (assessment and topic-quiz MCQs)
| Method and path | Purpose | Rules and errors |
|---|---|---|
| `GET /admin/questions?topic_id=&usage=quiz\|assessment&status=draft\|active\|retired&page=&per_page=` | Paginated list **including correct choices** | `per_page` 1–100. Each: `id, topic_id, skill_group, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status, revision, valid` (≥ 2 choices and exactly one correct), `used_in_attempts`, `choices: [{id, label, text, is_correct}]`. |
| `GET /admin/questions/{id}` | One question, same shape | — |
| `POST /admin/questions` | Create `{ topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, choices: [{ label, text, is_correct }] }` | `201`. Starts `draft`, revision 1. **Draft may be incomplete** (0–6 choices, no correct answer yet, no explanation) because a draft can never be assigned to students. Always: existing topic (its skill group is derived), at least one usage flag, `difficulty` ∈ easy/medium/hard, `stem` 1–5,000 characters, labels `A`… contiguous in order, choice text 1–1,000, **at most one** correct. Question and choices are written atomically. |
| `PUT /admin/questions/{id}` | Edit `{ expected_revision, …same fields }` (full replacement) | Revision + 1. **Active questions must stay complete:** 2–6 choices, exactly one correct, explanation 1–5,000 → else `400` (`fields.choices = "needs_two_choices"`, `"needs_one_correct"`, `fields.explanation = "required"`). **Tagging is fixed once a question leaves draft:** changing `topic_id`, `for_quiz` or `for_assessment` of an active question → `409 tagging_locked`. Choice ids are kept where labels match; removing a label whose choice was used in any attempt → `409 choice_in_use`. Retired → `409 question_retired`. Existing attempt snapshots and grades are never changed. |
| `POST /admin/questions/{id}/activate` | `{ expected_revision }` | `draft` → `active`; needs the complete rules above (`400` otherwise). Retired questions stay retired (`409 question_retired`). |
| `POST /admin/questions/{id}/retire` | `{ expected_revision, confirm? }` | → `retired` (with `retired_at`; never deleted). If it would take an `available` topic below 10 valid quiz questions → `409 would_make_topic_not_ready` (`details.topic_id`, `quiz_pool_size_after`) unless `"confirm": true`, which also sets that topic to `draft` (audited `unpublish`). If it would leave the placement assessment pool below a group quota → `409 would_break_assessment_pool` (`details.skill_group`), no override. |

### 10.5 Badges
**Artwork references:** `image_ref` is `null` (no artwork; stored internally as a `placeholder/` reference and shown as `"image_url": null`) or `badges/<name>.png` / `badges/<name>.webp`, where `<name>` is 1–63 lower-case letters, digits or hyphens. Anything else (paths, `..`, backslashes, other extensions such as `.php`, `.svg`, `.html`) → `400 fields.image_ref = "invalid"`. Public URL: `/hunter-system/assets/badges/<name>.<ext>`, served by Apache from `assets/badges/` as static images only (architecture §3). No uploads in this version: files are placed by the developer.

| Method and path | Purpose | Rules and errors |
|---|---|---|
| `GET /admin/badges` | All badges, any status | Each: `id, slug, name, description, image_ref, image_url, price, price_status, status, version, owners, price_approval` (`{ approved_by: {id, display_name}, approved_at, price }` from the audit log when `price_status` is `approved`, else `null`). |
| `POST /admin/badges` | Create `{ slug, name, description, image_ref, price }` | `201`. Starts `draft` with a **provisional** price. `slug` `^[a-z0-9][a-z0-9-]{1,59}$`, unique (`409 slug_taken`); `name` 1–80; `description` 1–255; `price` integer 1–1,000,000. `price_status`/`status` in the body → `400 unknown_field` (approval can't be bypassed). |
| `PUT /admin/badges/{id}` | `{ expected_version, name?, description?, image_ref?, price? }` (at least one) | A **different** price withdraws approval in the same transaction: `price_status → provisional`, and `on_sale → off_sale`. Owners keep their `price_paid`; the change affects only future purchases (C-16). Retired → `409 badge_retired`. Slug is immutable. |
| `POST /admin/badges/{id}/approve-price` | `{ expected_version, price }` — explicit approval of the **exact** current price | `price` must equal the current price → else `409 price_mismatch`. Already approved → `409 already_approved`; retired → `409 badge_retired`. Audit records the approving administrator, the price and the time. |
| `POST /admin/badges/{id}/status` | `{ expected_version, status }`, `status` ∈ `on_sale`, `off_sale`, `retired` | `on_sale` needs an approved current price → `409 price_not_approved` (also a database CHECK). `retired` is final (`409 badge_retired` afterwards). Retired and off-sale badges stay owned, keep `price_paid` and stay showcaseable. Audit: `publish` / `unpublish` / `retire`. |

Catalogue changes lock only the badge row (`FOR UPDATE`); purchases lock the student's wallet, then the badge row in share mode. So a change and a purchase are applied one after the other: a purchase sees the state committed before it read the badge.

### 10.6 Audit history
`GET /admin/audit-log?entity_type=&entity_id=&admin_id=&action=&since=&page=1&per_page=50` → newest first, `per_page` 1–100, `page` ≤ 100,000. `entity_type` ∈ `topic, lesson, question, badge`; `action` ∈ `create, update, publish, unpublish, retire`; ids positive integers; `since` ISO-8601 UTC. Invalid → `400`.
```jsonc
{ "entries": [ { "id": 51, "at": "…Z", "admin": { "id": 3, "display_name": "Content Admin" },
                 "action": "update", "operation": "approve_price", "entity_type": "badge", "entity_id": 9,
                 "before": { "price_coins": 40, "price_status": "provisional" },
                 "after":  { "operation": "approve_price", "price_coins": 40, "price_status": "approved" } } ],
  "page": 1, "per_page": 50, "total": 1 }
```
Audit rows are append-only (database triggers refuse update and delete).

### 10.7 Practical administrator sequences
```
Lesson:   PUT  /admin/topics/5/lesson            {"expected_revision":0,"title":"…","body_markdown":"…"}  -> 201, draft rev 1
          GET  /admin/topics/5/lesson                                                                    (review; no visit recorded)
          POST /admin/topics/5/lesson/publish    {"expected_revision":1}                                  -> published
Question: POST /admin/questions                  {topic_id 5, for_quiz true, …, choices:[…]}             -> draft rev 1  (x10)
          GET  /admin/questions?topic_id=5&status=draft                                                   (check "valid": true)
          POST /admin/questions/{id}/activate    {"expected_revision":1}                                  -> active      (x10)
Topic:    GET  /admin/topics                                                                              (content_complete true)
          PATCH /admin/topics/5                  {"expected_version":"…","status":"available"}            -> students can open it
Badge:    POST /admin/badges                     {"slug":"loop-ranger","name":…,"price":60,"image_ref":null}  -> draft, provisional
          PUT  /admin/badges/9                   {"expected_version":"…","price":50}                      -> still provisional
          POST /admin/badges/9/approve-price     {"expected_version":"…","price":50}                      -> approved (audited)
          POST /admin/badges/9/status            {"expected_version":"…","status":"on_sale"}              -> in the store
```

Not in v1: editing policies (grading, rewards, ranks); coin adjustments; uploads; **account deactivation and manual rank corrections (decided 2026-10-14: not allowed)**.

---

## 11. Endpoint summary

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Onboarding **[implemented]** | `GET /experience-levels`, `PUT /me/experience` |
| Assessment **[implemented]** | `POST /assessment`, `PUT /assessment/{id}/answers/{pos}`, `POST /assessment/{id}/submit`, `GET /assessment/result` |
| Learning **[implemented]** | `GET /me/dashboard`, `GET /topics`, `GET /topics/{id}/lesson` |
| Quizzes **[implemented]** | `POST /topics/{id}/quiz-attempts`, `GET /quiz-attempts/{id}`, `PUT /quiz-attempts/{id}/answers/{pos}`, `POST /quiz-attempts/{id}/submit`, `GET /topics/{id}/quiz-attempts` |
| Rank and coins **[implemented]** | `GET /me/rank-history`, `GET /me/coins` |
| Store and profile **[implemented]** | `GET /badges`, `POST /badges/{id}/purchase`, `GET /me/profile`, `PUT /me/showcase` |
| Admin tracking **[implemented]** | `GET /admin/students`, `GET /admin/students/{id}`, `GET /admin/students/{id}/logins`, `GET /admin/login-events` |
| Admin content **[implemented]** | topics, lessons, questions and badges as listed in §10, plus `GET /admin/audit-log` |

## 12. Decisions applied on 2026-10-14

| Item | Decision |
|---|---|
| O-02 | Login throttling also uses a keyed hash of the client IP, kept for at most 24 hours |
| O-05 | No admin account deactivation or manual rank correction in this milestone |
| O-08 | The placement result includes per-question answers and explanations after submission |
| O-09 | Explicit price approval is required before sale; a price change requires renewed approval |

**Still open:** whether to throttle registration (it isn't throttled yet), badge artwork and prices, the remaining course content, and SRS features.
