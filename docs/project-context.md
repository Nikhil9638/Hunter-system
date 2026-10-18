# Hunter System: Project Context

**Last updated:** 2026-10-15
**Current stage:** Backend milestones 1–5, student/admin interfaces and live verification are complete. Milestone 8A has 16 lessons/192 questions for topics 3–18, validated only on fixtures. Milestone 8B has six reviewable cosmetic badge proposals with private sources and allowed PNG exports, validated through real fixture API/Chrome (54 checks). No dev catalogue mutations, administrator creation or course import in 8B. Next: user review, authentic administrator/content import and separately approved publication; full application/cross-browser/accessibility testing and final security review follow. Schema remains 2026.10.14-3. See `progress.md`, `content/badges/README.md` and `administrator-content-access.md`.

## Purpose

Latest acceptance authorization: reviewed18-topic publication, six approved badges totaling180
and four audited sample retirements are approved for localhost. Execution remains blocked by
stopped MariaDB/Apache and missing authentic login; no new dev backup/activation. Offline
checks and two focused PHP security fixes pass; full live acceptance remains unverified.
See final-local-test-report.md and local-startup-demo.md. Deployment remains out of scope.

Current user steering supersedes earlier next-step notes: AI-assisted course review and approved
Shadow-title badge revisions are prepared;187 executable and73 component-render checks passed.
Dev imports/sample retirement await authentic administrator and local service startup; live
rehearsals currently blocked by MariaDB data-directory permission denial. User-approved names
and prices total180; no course publication/new badge sale. Next is authenticated local content
staging, separate publication approval, then full localhost/basic-security testing. Deployment,
hosting, external exposure and external penetration testing are explicitly out of scope.

Hunter System is a college project that teaches Python from beginner to intermediate through topic lessons, a placement assessment, topic quizzes, Solo Leveling-inspired learning ranks, virtual coins and collectible profile badges.

Python is the **subject being taught**. Questions are multiple-choice, including code-output prediction. The system does not run student Python code.

## Confirmed technology

| Layer | Choice |
|---|---|
| Frontend | React |
| Backend | PHP |
| Database | MySQL |
| Web server | Apache, through XAMPP |

No Python execution engine, other backend language or framework is added without discussing it with you first.

## Source priority

1. Your latest explicit decisions.
2. Approved project requirement documents.
3. The Hunter System SRS presentation. **Not reviewed yet:** it was not found in the workspace or attached to the chat.
4. My suggestions, always marked as proposals.

If sources conflict, I name the difference and follow your latest explicit decision.

## Confirmed student journey

1. Sign up and log in.
2. Choose an experience level: Complete beginner, Knows a little basic Python, Intermediate, Master.
3. Answer a 10-question assessment.
4. The backend assigns a knowledge level and a starting rank, and recommends a starting topic.
5. Answer five multiple-choice questions for a topic. The backend grades them and awards approved rewards.
6. After a quiz, choose for the next eligible topic: read the lesson then attempt the quiz, or attempt the quiz directly.
7. Spend coins on badges, and showcase owned badges on the profile.
8. Progress is saved so the student can return and continue.

## Confirmed administrator requirement

Administrators can track student login activity: account list, last successful login, login history with outcomes where identity is known, logout and session-expiry information, last authenticated activity, and each student's rank, current topic and progress summary. Other administrator powers are **not decided**.

## Curriculum (confirmed, in order)

1 Python introduction · 2 Variables and assignment · 3 Basic data types · 4 Input and type conversion · 5 Arithmetic operators · 6 Comparison and logical operators · 7 Conditions · 8 Loops · 9 Loop control · 10 Strings · 11 Lists · 12 Tuples and sets · 13 Dictionaries · 14 Functions · 15 Modules and imports · 16 Exceptions · 17 File handling · 18 Basic object-oriented programming

Each topic needs one lesson and a question pool. The pool size is different from the five questions in one attempt. Full topic contents are in `database-requirements.md`.

## Terms that must stay separate (documents and interface)

| Term | Meaning |
|---|---|
| Experience level | What the student says about themselves |
| Placement rank | E–S rank from the 10-question assessment (a starting point, not proof of mastery) |
| Hunter rank | Current rank, starts equal to placement rank |
| Quiz grade | A–F letter and percentage for one topic quiz attempt |
| Topic progress | Lesson visited, quiz completed and quiz passed are three different facts |
| Coins | Virtual currency, never changes rank or knowledge |

Ranks: E-Rank (Rookie), D-Rank, C-Rank, B-Rank, A-Rank, S-Rank. Only E-Rank has a name so far.

## Scope status

- **Confirmed:** everything listed above and in `decisions.md` section 1.
- **Proposed, not approved:** grading scale and pass mark, coin amounts, badge rules, assessment layout, rank mapping, activity windows and administrator content powers. See `decisions.md` section 2.
- **Open:** how rank rises after placement, starting-topic rule, badge prices and artwork, rank names D–A, administrator content powers. See `decisions.md` section 3.
- **Presentation features:** none are in scope until you choose them. The presentation has not been read.
- **Stage 1 sign-off:** you moved on with new decisions but did not explicitly sign off the Stage 1 scope questions. Treat Stage 1 as informally accepted.

## Backend rules that apply to every stage

- PHP decides scores, ranks, progression, rewards and badge prices. React is never trusted for them.
- Correct answers are not sent to the browser before submission.
- The questions assigned to each attempt are saved so grading stays consistent.
- Duplicate submissions cannot pay a reward twice.
- Badge purchases are atomic, and every coin change is recorded in a ledger.
- Student ownership and administrator permissions are enforced in the backend.
- Passwords are hashed, queries are parameterized, sessions are protected, input is validated, errors are handled safely, and secrets stay out of committed code.
# Current Codex milestone note — 2026-10-15

Milestone 7 live verification is complete. Milestone 8A topics 3–18 are authored
(16 lessons, 192 questions) and fixture-verified (Python 163 cases; real Chrome 44/44).
Dev draft import waits for an explicitly identified authenticating administrator;
no dev course publication. Human educational review remains. See
`milestone-8a-content.md` and `../content/README.md` for the current authoritative
content handoff and commands. Next: final badge content, then full application
testing, cross-browser/accessibility checks and final security review. Older status
notes below describe previous stages and must not override these results.

