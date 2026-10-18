# Milestone 8A course drafts

Current update: AI-assisted educational review covers all18 topics and216 quiz questions;
187 explicit executable cases and73 actual-component Chrome render checks pass. See
`review/course-review.md` for corrections, scope and source limitations. Topics1–2 corrections
are separate private proposals based on seed and require current-record comparison/audited
staging; they were not applied to live data. Course publication remains unapproved.
Current live import/HTTP/history verification is blocked by stopped/access-denied local
MariaDB/Apache. Prior fixture passes below are historical, not new revised-content evidence.
Dev apply now requires --backup outside htdocs; use the interactive backup-first batch in
docs/administrator-content-access.md. Deployment is explicitly out of scope.

Topics 3–18: 16 original Markdown lessons and 192 MCQs (12 per topic).
Topics 1–2, placement questions and curriculum identifiers are unchanged.
These files include answer keys: never copy them into frontend/public, dist or
other public assets. Apache's root allowlist and this directory's deny rule protect them.

Each `topics/NN` contains `lesson.md`, `questions.json`, and explicitly reviewed
`examples.json`. Stable `py-NN-II` keys identify source questions without adding
identifiers to student-facing text. `manifest.json` records objectives, group tags
and official Python 3.14 references. `tools/author_course.py` is the canonical
authoring source; edit it and regenerate rather than losing edits to generated files.

## Reproduce validation

From the project root, using installed Python 3.14.8:

```powershell
python content/tools/author_course.py
python content/tools/validate_course.py
```

The validator executes only explicit reviewed cases, with controlled stdin and a
fresh disposable working directory per case. File cases use temporary files only.
AST checks reject unapproved imports and dynamic execution calls. This is a local
content-development tool, not an app execution engine or general security sandbox.
Structural checks and execution do not replace educational review of concept answers.

## Audited import and review

`tools/import_course.py` uses existing authenticated admin HTTP APIs and CSRF.
Every write uses the returned revision/version and the existing service transaction
with real actor attribution. It never writes content directly to SQL or invents an actor.
The private `import-fixture.json` receipt records baseline records and stable key/ID
mappings. Plan/apply require identical sources and unchanged server content across
all topics before the first write. Repeat apply is a no-op; unexpected new pool rows,
stale revisions, changed choices or missing rows fail closed. Student usage counters
are excluded from content conflict signatures. A crash between API commit and receipt
save can require manual receipt reconciliation; rerun refuses duplicates rather than
guessing. Review the actual record and audit before reconciling; never delete history.

Fixture rehearsal uses the existing restricted configuration described in
`docs/milestone-7-verification.md`, with the complete backend on 8098:

```powershell
$env:HUNTER_FIXTURE_PRIVATE_DIR = Join-Path $env:TEMP 'HunterSystem-M7-Verification\complete'
$env:HUNTER_PRIVATE_DIR = $env:HUNTER_FIXTURE_PRIVATE_DIR
# For an entirely new plan only; existing receipt is intentionally retained:
# python content/tools/import_course.py plan
python content/tools/import_course.py verify
python content/tools/check_import_conflict.py
C:\xampp\php\php.exe content/tools/history_snapshot.php verify
C:\xampp\php\php.exe content/tools/verify_fixture.php
# Run browser/progression checks from frontend/:
node tests/course-journeys.mjs
```

Initial rehearsal ran `plan`, `apply`, repeated `apply`, then `publish-fixture`.
That last action is hardcoded to the attested fixture API/database/account, retires
old quiz-only placeholder pools through admin APIs, activates the authored questions,
publishes lessons and sets topics available. Existing snapshots/results remain intact.
Each command creates an explicitly labelled fixture administrator through the established
CLI and authenticates it; fixture administrator creation is the only setup mutation
performed even by content-read-only plan/verify commands. Passwords are generated in
memory, never committed. No simulated API responses are used by course-journeys.

For deliberate reviewed source changes, regenerate and validate, run `revise-source`
to compare the full existing server baseline and accept the new source hash, then
`apply`. Tag changes are refused. The revision step writes only the local plan;
apply performs any edits with exact saved revisions. Re-publish only on the fixture.

### Dev draft import still requires a real administrator

No dev content was imported in this run. An administrator email/attribution decision
was requested but not received. Existing dev admins are historical test accounts;
none was silently chosen as the course author. The importer is ready for an explicitly
identified administrator who can authenticate, or a dedicated administrator created
through `backend/bin/create-admin.php` with the user's authorization. It reads the
password interactively, does not need database/root credentials, and has no dev
publication action.

Once that actor exists and is identified, review all draft files, then run:

```powershell
python content/tools/import_course.py plan --target dev --email ADMIN_EMAIL
# Review content/import-dev.json, including actor and baseline.
python content/tools/import_course.py apply --target dev --email ADMIN_EMAIL --backup PATH_TO_LOGICAL_DUMP
python content/tools/import_course.py verify --target dev --email ADMIN_EMAIL
```

Dev plan requires topics 3–18 to remain draft with no existing lessons/quiz bank;
otherwise it stops for explicit conflict review. Draft creation is authorized,
publication is not. The authenticated actor is recorded by the API on every mutation.

## Human review and eventual publication

- Check language, examples, prerequisite progression and each concept distractor.
- Check all objectives are covered and explanations help a beginner understand mistakes.
- Review file safety, set-order independence, default-argument and shared-object warnings.
- Review the introductory treatment of `type(value).__name__` in topics 3–4.
- Confirm final titles, grouping and lesson formatting in the administrator UI.
- Obtain separate publication approval; this run does not grant it.

After approval, an authenticated administrator should review each dev lesson and its
12 questions, activate those questions, publish the lesson, then set its topic available.
The overview must report a published lesson and pool >=10 for readiness. Do this in
curriculum order using the existing UI; on a conflict compare/reload deliberately.
Changing availability never requires deleting attempts or audit rows. No badge work
or product-rule changes are part of this milestone.
