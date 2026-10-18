# Hunter System: Database Requirements

**Stage:** 4, Database requirements (implemented in Stage 5, schema `2026.10.14-2`)
**Last updated:** 2026-10-14
**Status:** Q-01 to Q-06 were answered on 2026-10-14. The approvals (A-01 to A-08) are summarised in section 0 and recorded in full in `decisions.md`. The original proposal text below is kept for traceability.

This document says **what information the system must store, why, and what rules protect it**. It does not say how the tables are built. Tables, columns, keys, constraints, indexes and the ER diagram come in Stage 5, after you approve this stage.

## How to read the labels

| Label | Meaning |
|---|---|
| **CONFIRMED** | You stated it explicitly. It is a requirement. |
| **PROPOSED** | My suggestion or your suggested default. It is **not** approved until you say so. |
| **OPEN** | Not decided yet. A question is needed. |
| **APPROVED (A-xx)** | Decided on 2026-10-14. Overrides the matching P-xx wording. |

## Words used in this project (keep these labels apart everywhere, including the interface)

| Term | Meaning | Who sets it |
|---|---|---|
| **Experience level** | What the student *says* about themselves at onboarding (Complete beginner, Knows a little basic Python, Intermediate, Master). | The student |
| **Placement rank** | The E–S rank given from the 10-question assessment. It is a starting point, not proof of mastery. | Backend (PHP) |
| **Hunter rank** | The student's current rank inside the app. It starts equal to the placement rank and may rise later through verified learning. | Backend (PHP) |
| **Quiz grade** | The letter grade (A–F) and percentage for one five-question topic quiz attempt. | Backend (PHP) |
| **Topic progress** | Lesson visited, quiz completed, quiz passed. These are three different facts. | Backend (PHP) |
| **Coins** | Virtual currency. Spending it never changes rank or knowledge. | Backend (PHP) |

A quiz grade of "B" and a Hunter rank of "B" are different things. The interface should always write "Quiz grade: B" and "Hunter rank: B-Rank".

---

## 0. Approval status (2026-10-14)

| Group | Outcome | Changes against the original proposal |
|---|---|---|
| Quizzes (Q-01) | **A-01** approves P-01 to P-05, P-07 and P-09 | Correct answers **and explanations are shown after every submission**, never before. Quiz results are labelled as quiz performance, not proof of mastery. |
| Coins (Q-02) | **A-02** approves P-10 to P-13 | None |
| Placement (Q-04) | **A-03** approves P-19, P-22, P-26, P-27 and P-28 | **Fallback added:** if the recommended topic isn't ready, the recommendation is kept and the nearest earlier ready topic is offered separately. D–S keep letter names (P-25 resolved). |
| Rank (Q-03) | **A-04** | Rank is based on distinct topics passed (E 0–2, D 3–5, C 6–8, B 9–11, A 12–14, S 15–18), including topics below placement. Current rank = highest of placement, previously achieved and earned. It never decreases. Promotions are recorded with evidence and policy version. Supersedes P-24. |
| Badges (Q-05) | **A-05** approves P-15 to P-17 | Retired badges stay owned and can be showcased. Sample prices and artwork stay provisional and not for sale. |
| Admin (Q-06) | **A-06** approves P-29 to P-33, P-35 and P-36 | **Content management approved** (topics, lessons, questions, badges) with an audit trail. |
| Authentication | **A-07** | Email login with a separate display name. Passwords of 12+ characters; spaces and Unicode allowed; no composition rules; no silent truncation. Login throttling with bounded retention. First admin created via CLI. Section 3.1's "email and/or username" is resolved as **email**. |
| Database simplification | **A-08** | S1 and S2: assessment group results, per-topic evidence and quiz statistics are derived from immutable attempt records rather than stored twice. |

Still open (see `decisions.md` §4):
- the readiness threshold of 10 quiz questions (P-08 / D-12)
- whether a hashed IP may be stored for login throttling (section 3.11 still says IPs aren't stored)
- PHP `intl` for Unicode normalization
- badge prices and artwork
- administrator account actions
- content review

## 1. Confirmed decisions

| ID | Decision |
|---|---|
| C-01 | Hunter System is a college project that teaches Python from beginner to intermediate through lessons, assessments, topic quizzes, ranks, coins and collectible badges. |
| C-02 | Stack: React frontend, PHP backend, MySQL database, Apache through XAMPP. |
| C-03 | Python is the subject being taught. Questions are multiple-choice, including code-output prediction. The system does **not** execute student Python code. |
| C-04 | During onboarding the student selects an experience level (four options listed above). |
| C-05 | Self-selected experience is stored separately from assessed knowledge. Choosing "Master" never grants a high rank by itself. |
| C-06 | The assessment has exactly 10 questions. |
| C-07 | Assessment results determine the starting rank and the recommended starting topic. |
| C-08 | The system preserves the assigned question identities, question versions or snapshots, submitted answers, scoring rules, result and recommendation for every assessment. |
| C-09 | Each topic quiz contains five questions. |
| C-10 | A student may read the lesson before a quiz or attempt the quiz directly. |
| C-11 | Passing depends on the quiz grade. |
| C-12 | Completing questions earns coins. (This is not silently narrowed to "only passing earns coins".) |
| C-13 | Coins buy badges in a store. Owned badges can be showcased on the profile. |
| C-14 | Badges have a name, description, image reference, coin price and availability. |
| C-15 | Buying a badge deducts coins and records ownership atomically (all together or not at all). |
| C-16 | The price actually paid is preserved even if the store price changes later. |
| C-17 | Students can showcase **owned** badges only. |
| C-18 | Purchases do not increase knowledge level or Hunter rank. |
| C-19 | A ledger records every coin credit and every purchase deduction with amount, source, student and timestamp. |
| C-20 | Students can see their current learning level and progress. |
| C-21 | Administrators can track student login activity: account list, last successful login, login history with outcomes where identity is known, logout and session-expiry information, last authenticated activity, and each student's rank, current topic and progress summary. |
| C-22 | "Recently active" is defined by a documented activity window. A login record alone never means a student is continuously online. A closed browser may produce no logout event. |
| C-23 | Raw session tokens and passwords are never stored in activity logs. A safe session reference may be stored. |
| C-24 | Ranks are Solo Leveling-inspired learning ranks inside our app: E-Rank (Rookie), D, C, B, A, S. |
| C-25 | The curriculum is the 18-topic sequence below. Each topic needs a lesson and a question pool. The size of the pool is different from the five questions in one attempt. |
| C-26 | Initial placement rank is stored separately from later progression. |
| C-27 | Backend (PHP) alone decides scores, ranks, progression, rewards and badge prices. React is never trusted for these. |
| C-28 | Correct answers are never sent to the browser before submission. The questions assigned to each attempt are saved so grading stays consistent. |
| C-29 | Duplicate submissions must not award rewards twice. |
| C-30 | Passwords are stored as hashes. Queries are parameterized. Secrets stay out of committed code. |

### The curriculum (CONFIRMED, in this order)

| # | Topic | Covers |
|---|---|---|
| 1 | Python introduction | purpose, basic syntax, indentation, comments, `print()` |
| 2 | Variables and assignment | names, assigning and updating values |
| 3 | Basic data types | integers, floats, strings, booleans |
| 4 | Input and type conversion | `input()`, `int()`, `float()`, `str()` |
| 5 | Arithmetic operators | `+ - * / // % **` |
| 6 | Comparison and logical operators | `== != < > <= >=`, `and`, `or`, `not` |
| 7 | Conditions | `if`, `elif`, `else`, nested conditions |
| 8 | Loops | `for`, `while`, `range()` |
| 9 | Loop control | `break`, `continue`, nested loops |
| 10 | Strings | indexing, slicing, methods, formatting |
| 11 | Lists | creation, indexing, updates, methods, iteration |
| 12 | Tuples and sets | |
| 13 | Dictionaries | |
| 14 | Functions | parameters, return values, scope basics |
| 15 | Modules and imports | |
| 16 | Exceptions | `try`, `except`, `else`, `finally` |
| 17 | File handling | reading, writing, context managers |
| 18 | Basic object-oriented programming | classes, objects, attributes, methods |

---

## 2. Proposed defaults (original text; see section 0 for what was approved)

As written before 2026-10-14. Most are now approved or superseded; section 0 and `decisions.md` §3 give the current status of each P-xx.

### 2.1 Quiz grades and progression

| ID | Proposal |
|---|---|
| P-01 | Grade scale for a five-question quiz: 5/5 = A (100%), 4/5 = B (80%), 3/5 = C (60%), 2/5 = D (40%), 1/5 = E (20%), 0/5 = F (0%). |
| P-02 | **Passing** means at least 3/5 (grade C or better). |
| P-03 | Unlimited retries. |
| P-04 | Passing a topic unlocks the next topic in the sequence. |
| P-05 | A failed attempt never locks anything. The lesson and a retry stay available. |
| P-06 | Lesson visit, quiz completion and quiz passing are stored as three separate facts. |
| P-07 | Each retry assigns a fresh set of five questions, preferring questions the student has not seen in that topic. If the pool runs out of unseen questions, previously seen ones may be reused. |
| P-08 | Pool size: at least 10 questions per topic (twice the attempt size), so retries are not identical. This is a content target for you to write, not a database limit. |
| P-09 | An attempt counts as **completed** only when all five questions have an answer and the student submits. A started but unsubmitted attempt stays "in progress" and can be resumed. It never earns coins and is not a failure. |

### 2.2 Coins

| ID | Proposal |
|---|---|
| P-10 | Completing all five answers and submitting a topic quiz earns **10 coins, once per student per topic**, even if the grade is below passing. |
| P-11 | Partial attempts earn no coins. |
| P-12 | Retries can improve the grade and unlock the next topic, but do not repeat the completion reward. |
| P-13 | The initial assessment awards no coins unless you approve it separately. |
| P-14 | The amount (10) is stored as a rule that can be changed later, and the amount actually paid is stored on every ledger entry. |

### 2.3 Badges

| ID | Proposal |
|---|---|
| P-15 | Each badge can be bought once per student. |
| P-16 | A student can showcase up to three owned badges. |
| P-17 | Badges are purchase-only in this version (no earning badges for achievements). |
| P-18 | Badge prices and artwork are **not decided**. Any number in this document is only an example. |

### 2.4 Assessment, rank and starting topic

| ID | Proposal |
|---|---|
| P-19 | Assessment score to **placement rank**: 0–2 correct = E, 3–4 = D, 5–6 = C, 7–8 = B, 9 = A, 10 = S. |
| P-20 | The placement rank is a starting point from a short test. It is **not** proof of Python mastery, and the interface should say so. |
| P-21 | The initial placement rank is kept forever and never overwritten. The current Hunter rank is stored separately and starts equal to it. |
| P-22 | One official placement assessment per student in this version. If retakes are ever allowed, they are stored as new attempts and never replace the first placement. |
| P-23 | Self-selected experience is used only to give context (for example, shown beside the measured result). It does not change grading, rank or starting topic. |
| P-24 | Hunter rank can rise **only** from verified backend-graded quiz results, never from coins, badges, purchases or self-selected experience. The exact formula is OPEN (see Q-03). Every rank change is recorded with the old rank, new rank, reason, evidence and time. |
| P-25 | Rank names for D, C, B and A are not yet chosen. Only E-Rank has a name ("Rookie"). |
| P-26 | If the student is placed at a later topic, earlier topics stay open for study, but they are **not counted as passed** until the student actually passes their quizzes. How a topic became available ("placement" or "passed previous topic") is recorded. |

### 2.5 Assessment design (answering the "10 questions cannot cover 18 topics" problem)

Ten questions cannot reliably measure 18 topics one by one. **P-27** proposes grouping the topics into six broad skill groups and asking a balanced number of questions from each:

| Skill group | Topics | Assessment questions (proposed) |
|---|---|---|
| G1 Python basics | 1–3 | 2 |
| G2 Input and operators | 4–6 | 2 |
| G3 Control flow | 7–9 | 2 |
| G4 Text and collections | 10–13 | 2 |
| G5 Functions, modules, errors | 14–16 | 1 |
| G6 Files and objects | 17–18 | 1 |
| **Total** | | **10** |

Rules that go with P-27:

- Every assessment question is tagged with one skill group **and** one topic.
- A topic with no assessment question is labelled **"Not assessed"**. With this layout at least 8 of the 18 topics will always be "Not assessed".
- A topic with exactly one assessment question is labelled "Sampled (1 question)" and shows only whether that question was answered correctly. It never shows a mastery percentage.
- Within a group, the question's topic should vary between students so coverage differs, but every student gets exactly 10 questions and the group counts above.
- **P-28 (starting topic):** The recommended starting topic is the first topic, in curriculum order, belonging to the earliest skill group in which the student missed at least one question. If the student answered all 10 correctly, the recommendation is topic 18 (the last topic). *This is only a proposal. See Q-04.*

### 2.6 Administrator and login tracking

| ID | Proposal |
|---|---|
| P-29 | **Recently active** means the student's last authenticated activity was within the last **15 minutes**. |
| P-30 | **Session idle expiry:** a session with no authenticated activity for **30 minutes** expires. |
| P-31 | Last-activity time is updated at most about once a minute per session (not on every request), so it is accurate to roughly a minute. |
| P-32 | Failed login attempts are recorded. If the email or username matches a known student, the attempt is linked to that student. If not, the attempt is recorded without any identity and **without storing what was typed**, because a mistyped password could end up in the log. |
| P-33 | Administrators may (not yet approved) manage questions, lessons and badges, and every such change is written to an audit trail (who, what, when). The only confirmed admin requirement is login and progress tracking. |
| P-34 | Student accounts are deactivated rather than deleted, so history stays consistent. |
| P-35 | Administrator screens show rank, current topic and progress summary. Coin balances are not shown to administrators unless you approve it. |
| P-36 | The **progress summary** is "topics passed out of 18", counted only from passed quizzes. Placement results never count as progress. |

---

## 3. Stored information, grouped by feature

"Why" explains the reason each group exists, so you can defend it in your presentation.

### 3.1 Accounts and sign-in
**Why:** to know who the student is, protect their data, and tell students from administrators.

- Student identity: display name and email. **Email is the login identifier** (A-07); there is no username.
- Password **hash** only (never the password).
- Role: student or administrator.
- Account status: active or deactivated (P-34).
- When the account was created.

### 3.2 Onboarding
**Why:** to keep what the student *claims* separate from what we *measure*.

- Self-selected experience level and when it was chosen.
- Onboarding state: not started, experience chosen, assessment submitted.

### 3.3 Curriculum content
**Why:** the lessons and questions are the product. Every attempt must be traceable to the exact content the student saw.

- **Topics:** position in the sequence (1–18), title, short description, skill group.
- **Lessons:** one per topic; content, status (draft or published), version.
- **Questions** (the pool for each topic and the assessment): question text, optional code snippet, answer choices, which choice is correct, optional explanation, topic, skill group, difficulty, status (active or retired), version.
- Whether a question may be used in the assessment, in topic quizzes, or both.

### 3.4 Placement assessment
**Why:** to prove, afterwards, exactly how a placement was decided.

- The assessment attempt: student, start time, submit time, state.
- The **10 assigned questions**, in order, each with a **snapshot** of its text, choices and correct answer as they were when assigned (C-08).
- The student's submitted answers.
- The **scoring rule used** (which score-to-rank mapping, and its version).
- The result: correct count, **placement rank**, and the per-skill-group results.
- The per-topic evidence label for all 18 topics: Not assessed, or Sampled with correct or incorrect.
- The recommendation: starting topic, and which recommendation rule produced it.

### 3.5 Topic progress and quiz attempts
**Why:** to separate "visited the lesson", "completed a quiz" and "passed a quiz", and to allow resuming.

- **Lesson visits:** student, topic, first visit time, latest visit time. A visit is not a completion.
- **Quiz attempts:** student, topic, attempt number, start time, submit time, state (in progress, submitted).
- The **five assigned questions** for each attempt, in order, with **snapshots** as in 3.4.
- Submitted answers per question and whether each was correct.
- Result: correct count, **quiz grade** (letter and percentage), passed or not, and which grading scale was used.
- Whether the attempt was started after reading the lesson or directly (to show the two-choice flow worked; nothing else depends on it).
- **Topic status per student:** locked, available, in progress, completed, passed. Also the best grade so far, when it was first passed, and how the topic became available (placement or passing the previous topic, P-26).

### 3.6 Ranks
**Why:** to keep the first placement untouched and still allow progression.

- Initial placement rank (from 3.4), kept permanently.
- Current Hunter rank.
- Rank history: old rank, new rank, reason, the evidence it was based on, time.

### 3.7 Coins
**Why:** to make money-like data auditable.

- **Coin ledger:** one entry per credit or deduction: student, direction (credit or debit), amount, source type (for example "topic quiz first completion" or "badge purchase"), reference to the thing that caused it, time. Entries are never edited or deleted.
- **Reward eligibility record:** one per student per reward (for example, "first completion of topic 7") showing the reward was granted, when, and by which attempt. This is stored separately from attempts so a retry or duplicate request cannot pay the same reward twice (C-29).
- **Balance:** must always equal credits minus debits from the ledger. It may be stored for speed as long as it can be checked against the ledger.
- **Reward rules:** the amount for each reward type (P-10, P-14), so it can change without changing history.

### 3.8 Badge store, ownership and showcase
**Why:** to keep what a badge is, who owns it, and what they display as three separate things.

- **Badge catalogue:** name, description, image reference, current coin price, availability (on sale or not).
- **Ownership:** student, badge, **price actually paid**, purchase time, link to the ledger debit entry.
- **Showcase:** student, badge, display position (one to three positions, P-16). Only owned badges may appear.

### 3.9 Login activity and sessions (administrator tracking)
**Why:** to show administrators accurate activity without claiming more than the data proves.

- **Login attempts:** time, outcome (success, wrong password, account deactivated, unknown account), the student if the identity is known (P-32). No typed passwords or typed unknown identifiers.
- **Sessions:** a safe session reference (a hashed or random identifier, **never the raw token**), the student, start time, **last authenticated activity time**, end time, and end reason: **logged out**, **expired** or (if ever added) **ended by administrator**.
- A session that simply stops being used has no logout event. Its end reason is "expired", and its end time is last activity plus the idle limit.
- From these, administrators can see: last successful login, login history, session ending information, and recently-active status (P-29).

### 3.10 Administrator content changes (PROPOSED, with P-33)
**Why:** if admins can edit content, we must know who changed what.

- Audit trail: administrator, what was changed (question, lesson or badge), what it was before and after, time.

### 3.11 What is deliberately NOT stored

- Raw passwords or raw session tokens, anywhere.
- Student-written Python code (this version has no code execution).
- Correct answers for the student's browser before they submit (this is an access rule, see section 6).
- IP addresses, device or browser details: not requested, so not stored unless you approve it.

---

## 4. User action to data (reads and writes)

"Reads" and "Writes" name the information groups from section 3. Every write below happens in the backend, never directly from the browser.

| # | Action | Reads | Writes | Notes |
|---|---|---|---|---|
| 1 | Student signs up | Accounts (is the email or username already used) | Accounts (new student, password hash) | Role is always "student". The role is never taken from the browser. |
| 2 | Student logs in (success) | Accounts | Login attempts (success), Sessions (new) | Updates "last successful login". |
| 3 | Login fails | Accounts | Login attempts (failure, with student link only if known) | No typed password stored. |
| 4 | Student chooses experience level | Onboarding | Onboarding (experience, state) | No rank or knowledge is changed. |
| 5 | Student starts the assessment | Onboarding, Curriculum (questions) | Placement assessment (attempt, 10 assigned questions with snapshots) | If an unfinished attempt exists, resume it instead of creating another. |
| 6 | Student submits the assessment | Placement assessment (assigned snapshots) | Placement assessment (answers, scoring rule, result, per-group and per-topic evidence, recommendation), Ranks (placement and current), Topic status (available topics) | One transaction. Submitting twice must return the same stored result. |
| 7 | Student views dashboard and progress | Ranks, Topic status, Quiz attempts, Placement result | Sessions (last activity) | Evidence labels are shown honestly: "Not assessed", never an invented percentage. |
| 8 | Student reads a lesson | Curriculum (lesson), Topic status | Lesson visits | Reading does not complete or pass anything. |
| 9 | Student starts a topic quiz (with or without reading the lesson) | Topic status, Curriculum (pool), earlier attempts (to prefer unseen questions) | Quiz attempts (new attempt, 5 assigned questions with snapshots) | Refused if the topic is locked. If an attempt is in progress, resume it. Correct answers are not sent. |
| 10 | Student submits the quiz | Quiz attempts (assigned snapshots), Reward eligibility, Topic status | Quiz attempts (answers, result), Topic status, Reward eligibility, Coin ledger (first completion only), Ranks (only if the approved rank rule says so) | One transaction. See section 8 for duplicate and simultaneous submissions. |
| 11 | Student retries a topic quiz | Same as 9 | Same as 9 and 10 | A retry never pays the completion reward again (P-12). |
| 12 | Student views the badge store | Badge catalogue, Ownership, Coin ledger or balance | none (Sessions: last activity) | Shows which badges are owned and which are affordable. |
| 13 | Student buys a badge | Badge catalogue (**current price, read inside the transaction**), Ownership, Balance | Coin ledger (debit), Ownership (price paid) | One transaction. Rank and knowledge are untouched (C-18). |
| 14 | Student showcases or removes a badge | Ownership, Showcase | Showcase | Only owned badges, at most three. |
| 15 | Student views profile | Accounts, Ranks, Ownership, Showcase | none | Other students' profiles are not part of this version. |
| 16 | Student logs out | Sessions | Sessions (end time, end reason "logged out") | |
| 17 | Session expires (no action by the student) | Sessions | Sessions (end reason "expired", end time = last activity + idle limit) | Happens by system rule, not by a student click. |
| 18 | Administrator logs in | Accounts | Login attempts, Sessions | Administrator logins are tracked like student logins. |
| 19 | Administrator views student list | Accounts, Login attempts, Sessions, Ranks, Topic status | Sessions (admin's own last activity) | Shows last login, recently active or not, rank, current topic, progress summary. |
| 20 | Administrator views one student's login history | Login attempts, Sessions | none | |
| 21 | Administrator edits a question (PROPOSED) | Curriculum | Curriculum (new version), Audit trail | Old version kept. History is unchanged (section 7). |

---

## 5. Relationships in plain language

These are facts about how the information connects. Stage 5 turns them into keys and constraints.

- A **student** has one account, one self-selected experience level, one placement rank, and one current Hunter rank.
- A student has at most one official **placement assessment** (P-22). That assessment has exactly **10 assigned questions** and one result.
- A **skill group** contains several **topics**. A topic belongs to exactly one skill group.
- A **topic** has one current lesson (with older versions kept) and a **pool of questions**. A question belongs to one topic.
- A student has **one progress record per topic**, showing locked, available, in progress, completed or passed.
- A student can have **many quiz attempts** for one topic. Each attempt has exactly **five assigned questions**, and each assigned question points back to the question version it was copied from.
- A student has **many coin ledger entries**. Each entry says why it exists by pointing at exactly one cause: a first-completion reward (and the attempt that earned it) or a badge purchase.
- A **reward eligibility record** belongs to one student and one reward (for example, topic 7 first completion). It points to the attempt that earned it and to the ledger entry that paid it.
- A **badge** can be owned by many students, and a student can own many badges. Each ownership record keeps the price paid and points to the ledger entry that paid for it.
- The **showcase** is a chosen subset of a student's owned badges, at most three.
- A **session** belongs to exactly one student. A **login attempt** belongs to a student only when the student is known.
- An **audit entry** (PROPOSED) belongs to one administrator and one changed item.

---

## 6. Data ownership and access permissions

Enforced in PHP on every request. React is only a display.

| Information | Student | Administrator | Backend only |
|---|---|---|---|
| Own account details | Read | Read (not the password hash) | Write |
| Other students' details | No | Read (account list, rank, current topic, progress summary, login information) | n/a |
| Password hash | Never read | Never read | Compare at login |
| Experience level | Read, set once during onboarding | Read | Validate and store |
| Question text and choices | Read only for questions assigned to the student | Read (proposed content management) | n/a |
| **Correct answers and explanations** | **No, not before submission** (explanations after submission are PROPOSED, to be decided with feedback timing) | Read (proposed content management) | Used for grading |
| Scores, grades, pass or fail, rank, topic unlocks | Read own | Read (rank, progress summary) | **Only the backend writes these** |
| Coin ledger and balance | Read own | Not shown unless approved (P-35) | **Only the backend writes these** |
| Reward eligibility | No | No | Backend only |
| Badge catalogue | Read badges on sale | Read all (edit is PROPOSED) | Price decided by backend at purchase |
| Own ownership and showcase | Read own, choose showcase from owned | Read | Backend verifies ownership and the limit of three |
| Login attempts and sessions | Not shown | Read (administrator tracking) | Backend writes. Raw tokens never stored. |
| Audit trail (PROPOSED) | No | Read | Backend writes |

Ownership rule: a student's request can only touch rows belonging to **that student's own session**. The student ID is taken from the server-side session, **never** from a value the browser sends.

---

## 7. History and content-editing requirements

1. **Attempts are self-contained snapshots.** Each assessment or quiz attempt stores a copy of the question text, choices and correct answer as they were when the question was assigned. Editing a question later never changes any past attempt, grade, reward or rank.
2. **Content is versioned.** Editing a question or lesson creates a new version. Old versions stay available so past attempts can still point to them.
3. **Retire, do not delete.** A question, lesson or badge that has ever been used is retired (hidden from new use), not deleted.
4. **Rules are versioned.** The grading scale, the score-to-rank mapping, the starting-topic rule and the coin reward amounts each have a version. A result records the version used. Changing a rule later never re-grades history.
5. **Append-only records.** Coin ledger entries, login attempts, rank history, audit entries and submitted attempts are never edited or deleted. A mistake is fixed by adding a new correcting entry. Sessions are the one exception: their end time and end reason are filled in once when the session ends.
6. **Badge price history.** Changing a badge price affects only future purchases. Ownership keeps the price paid. Taking a badge off sale hides it from the store but owners keep it, and it can still be showcased (PROPOSED).
7. **Curriculum order is fixed** for this version. Reordering topics later needs a separate decision, because "next topic" depends on the order.
8. **Time is set by the server**, never by the browser.

---

## 8. Integrity and transaction requirements

A **transaction** means a group of changes that all succeed or all fail together.

### Quiz submission (one transaction)
- **I-01** Finalizing an attempt, writing its answers and result, updating topic progress, recording reward eligibility and creating the ledger credit happen together. If any step fails, none are kept.
- **I-02** An attempt can be finalized **only once**. Any later submit for the same attempt returns the stored result and changes nothing.
- **I-03** The database itself must refuse a second first-completion reward for the same student and topic, not only the PHP code. This protects against bugs and simultaneous requests.
- **I-04** Two simultaneous submits of the same attempt must produce one result and one reward.
- **I-05** A submission is accepted only if every one of the five assigned questions has an answer, each answer belongs to the assigned question, and the chosen option belongs to that question (P-09).
- **I-06** The grade, pass result, unlock and coin amount are calculated by PHP from the saved snapshots. Any score, grade or coin value sent by the browser is ignored.
- **I-07** Only one in-progress quiz attempt per student per topic at a time, even if the student opens two tabs.
- **I-08** A student cannot start a quiz for a locked topic.

### Assessment
- **I-09** An assessment attempt has exactly 10 assigned questions that follow the skill-group layout (P-27, once you approve it).
- **I-10** Submitting an assessment is finalized once, in one transaction that writes the result, placement rank, current rank, starting topic recommendation and topic availability.
- **I-11** The initial placement rank is never changed after it is written.

### Coins and badges
- **I-12** Every change to coins creates a ledger entry. Nothing changes a balance without one.
- **I-13** The balance equals the sum of credits minus debits and is **never negative**. A reconciliation check must be possible at any time.
- **I-14** A badge purchase is one transaction that: (1) reads the badge's **current price and availability inside the transaction**, (2) confirms ownership does not already exist, (3) confirms the balance is enough, (4) writes the ledger debit, and (5) writes ownership with the price paid.
- **I-15** Simultaneous purchases by the same student are processed one at a time for that student, so they cannot overspend.
- **I-16** The database must refuse a second ownership record for the same student and badge (P-15).
- **I-17** The price comes only from the database, never from the browser.

### Showcase
- **I-18** A badge can be showcased only if the student owns it. No more than three (P-16) can be showcased. Both checks happen in the backend.

### Sessions and logs
- **I-19** Session references stored for tracking are hashed or random identifiers, never the raw token (C-23).
- **I-20** Login logs never contain passwords or typed unknown identifiers (P-32).
- **I-21** Last-activity updates must not slow normal requests (P-31).

### General
- **I-22** Records never point to something missing. Content or accounts that have history are deactivated or retired, not removed.
- **I-23** Every history record has a server-generated timestamp.

---

## 9. Concrete acceptance examples

All coin amounts and the 15- and 30-minute windows are the **PROPOSED defaults**. Badge prices are **examples only**, not approved prices. Names are made up.

### AE-1: Score 2/5 on a first attempt
- **Given** Asha has topic 7 (Conditions) available, no earlier attempts, and 0 coins.
- **When** she answers all five questions and submits, with 2 correct.
- **Then** the result is **Quiz grade D (40%)**, not passed. Topic 7 is marked *completed, not passed*. She receives the first-completion reward of **10 coins** (one ledger credit, one reward eligibility record). **Topic 8 stays locked.** Her lesson and a retry are still available. Her Hunter rank does not change.

### AE-2: Retry scores 4/5
- **Given** Asha's situation after AE-1 (balance 10).
- **When** she starts a new attempt (new set of five questions, unseen ones preferred) and scores 4/5.
- **Then** the result is **Quiz grade B (80%)**, passed. Topic 8 is unlocked and the time it was first passed is recorded. **No new coins** are credited: the ledger still has one credit and her balance is still 10. Her best grade becomes B, and attempt #1 is unchanged.

### AE-3: Duplicate submission does not duplicate coins
- **Given** Asha has finished all answers for her first attempt at topic 8, and her phone sends the submit request twice (a double tap or a network retry), possibly at the same instant.
- **Then** the attempt is finalized once. The second request returns the same stored result. There is exactly **one** 10-coin credit. The balance rises by 10 once. Even if a coding mistake let both through, the database refuses a second reward for the same student and topic (I-03).

### AE-4: Two simultaneous purchases cannot overspend
- **Given** Ravi has 50 coins. Badge X costs 40 coins and badge Y costs 30 coins (**example prices only**). He clicks buy on both from two tabs at the same moment.
- **Then** only one purchase succeeds (the total of 70 exceeds 50). The other is rejected with "not enough coins". His balance after is 10 or 20, **never negative**. The ledger has exactly one debit and he owns exactly one badge. If both tabs were buying the **same** badge, one succeeds and the other is told he already owns it.

### AE-5: An administrator edits a question without corrupting history
- **Given** question Q (version 1) asked "What does `print(7 // 2)` display?" and Meena answered "3" yesterday, graded correct against the snapshot saved in her attempt.
- **When** an administrator edits Q (PROPOSED permission) to a different question and correct answer.
- **Then** Q becomes version 2 for **new** attempts. Meena's attempt still shows the version 1 text and her answer, and is still graded correct. Her score, grade, coins and rank are unchanged. The audit trail shows who made the edit, when, and the before and after.

### AE-6: A session expires without an explicit logout
- **Given** Karan logs in at 09:00, uses the app until 09:20, then closes the browser. There is no logout.
- **Then** at **09:40** the administrator screen shows *last successful login 09:00, last activity 09:20, not recently active* (20 minutes is outside the 15-minute window), while the session is not yet formally expired. At or after **09:50** (last activity plus the 30-minute idle limit) the session shows end reason **expired** with end time 09:50, not "logged out". The screen never says "online" or "currently logged in". It only says what the records prove.

### AE-7: An unassessed topic shows no invented mastery percentage
- **Given** Ravi scored 5/10 on the assessment and was placed at rank C. No assessment question was about topic 17 (File handling). Exactly one question was about topic 3 (Basic data types), and he answered it correctly.
- **Then** topic 17 shows **"Not assessed"** with no percentage. Topic 3 shows "Sampled: 1 of 1 correct in the placement check", **not** "100% mastery". Overall progress is a real count such as "2 of 18 topics passed" (PROPOSED measure, P-36), based only on passed quizzes.

### AE-8: Choosing "Master" does not grant a high rank
- **Given** Divya selects *Master* but answers 3 of 10 assessment questions correctly.
- **Then** her stored experience level is *Master*, her **placement rank is D** (P-19), and her Hunter rank is D. The two are shown separately.

### AE-9: Buying a badge does not change rank
- **Given** Divya is rank D and buys an affordable badge.
- **Then** coins go down by the price paid, ownership is recorded, and her rank and topic progress are unchanged. No rank history entry is created.

### AE-10: Showcase limits
- **Given** Divya owns four badges and has three showcased.
- **When** she tries to showcase a fourth, or a badge she does not own.
- **Then** the backend refuses both requests and the showcase is unchanged.

---

## 10. Open questions (answered 2026-10-14; see section 0)

| ID | Group | Question | Options |
|---|---|---|---|
| Q-01 | Grading | Approve P-01 to P-09 (grade scale, pass mark of 3/5, unlimited retries, unlock on pass, fresh questions on retry, pool of at least 10, completion means all five answered)? When are correct answers and explanations shown (after every submission, only after passing, or never)? | A: show after every submission (my suggestion, as it helps learning). B: only after passing. C: never. |
| Q-02 | Rewards | Approve P-10 to P-14 (10 coins once per student per topic on first completion, partial attempts earn nothing, retries earn nothing, assessment earns nothing)? | Approve, or change the amount or the rule. |
| Q-03 | Assessment and rank | How can Hunter rank rise? I am **not inventing a formula**. These are principle-level options. | A: by the number of topics passed. B: by topics passed *and* the grades earned. C: by passing a short checkpoint quiz at the end of each skill group. Thresholds for any option would be decided later. |
| Q-04 | Assessment and rank | Approve P-19 (score to placement rank), P-22 (one placement per student), P-26 (earlier topics open but not counted as passed) and P-28 (starting-topic rule)? Do you want to name the D, C, B and A ranks (P-25)? | Approve, change, or name the ranks. |
| Q-05 | Badges | Approve P-15 to P-17 (one purchase per badge, showcase of three, purchase-only)? Prices and artwork are still undecided and will be asked for when we reach badge content. | Approve or change. |
| Q-06 | Admin | Approve P-29 to P-31 (15-minute active window, 30-minute idle expiry, activity updated about once per minute), P-32 (failed logins recorded without typed text), P-35 (no coin balances shown to admins) and P-36 (progress as topics passed out of 18)? Which content powers should administrators have (P-33)? | A: login and progress tracking only, with content loaded by you directly into the database (simplest). B: also add and edit questions, lessons and badges, with an audit trail. |

---

## 11. Next stage

Stage 5 is implemented: see `database-design.md` (schema `2026.10.14-2`). Next come the architecture and API contracts (`architecture.md`, `api-contract.md`), then the backend implementation.

