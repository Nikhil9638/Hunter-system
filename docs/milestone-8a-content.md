# Milestone 8A: course draft completion

## Status and workflow

Database, architecture, backend milestones 1–5, student integration and administrator
interface/live verification are complete. Current work is course content authoring and
validation. Topics 3–18 are authored and fixture-verified; **dev draft import remains
pending explicit administrator identification/authentication**. Do not call dev import
or human educational review complete. Next is final badge content; full application,
cross-browser/accessibility testing, final security review and deployment preparation follow.

## Authored material

16 lessons, 12 questions per topic, 192 total. G1: topic 3; G2: 4–6; G3: 7–9;
G4: 10–13; G5: 14–16; G6: 17–18. Titles/order/IDs follow the existing curriculum.
Every lesson has objectives, explanations, reviewed examples with expected outcomes,
common mistakes, recap and optional local practice. No execution/editor feature added.
Question mix is 64 concept, 80 output prediction, 48 mistake identification. All questions
have four unique choices, exactly one correct, explanations and quiz-only usage tags.
Topics 1–2 and assessment pools were not rewritten. Content is in private `content/`;
see its README for review/import/publication instructions and source references.

## Checks personally executed

- Python 3.14.8: 163 explicit executable cases passed, zero failures, each in an isolated
  temporary directory. Controlled input, expected exceptions and disposable file cases
  included. Structural/tag/choice/manifest checks passed. Official Python 3.14 tutorial
  and library references are recorded by topic. This is not a claim that conceptual
  distractors or pedagogical quality have independent human approval.
- Real fixture API: 16 lessons/192 questions imported using authenticated administrators,
  existing CSRF and optimistic revisions, with transactional service auditing. Completed
  apply repeated without duplicate questions or content writes. Explicit source revision
  rehearsed with baseline comparison and exact revision edits.
- Fixture publication: old quiz-only placeholders retired through APIs; new pools activated,
  lessons published and topics available. All 18 topics ready; new pools exactly 12 each.
  421 matching attributed lesson/question audit rows verified after the reviewed fixes.
- Import conflict negative case: deliberately stale local lesson revision rejected before
  content writes, preserving the plan. This is an importer baseline test, not a claim of
  another two-browser conflict test (that was verified in Milestone 7).
- Real API progression: one labelled student placed E, passed topics 1–18 in order;
  three quizzes per new topic selected all 12 authored questions in each pool (50 quizzes
  total per run). Correct flags, explanations and source question IDs absent before
  submission; imported answers matched snapshots and graded 5/5. First completion awarded
  10 coins, retries zero; final rank S; topic 18 unlocked no nonexistent successor.
- Installed real Chrome: 44/44 checks, no mocks. Topics 3, 11 and 18 at 1280px and
  390px: exact code/indentation, no page overflow, keyboard focus on code blocks, no
  page script errors. Six private content/source/receipt URLs blocked by Apache.
  Results: `frontend/tests/results/m8a-course.json`.
- Original fixture history: all 11,735 rows in captured attempts, snapshots, quiz/assessment
  results, rank history and ledger unchanged after import and test journeys. No database
  reset, row deletion, history bypass or backup removal.
- Dev content/audit fingerprints before and after match exactly; IR-1 audit count remains
  one and question 106 remains active revision 1, not retired. See preservation report.

An actual importer defect was found after students used the new pools: comparing the entire
admin question response treated changing `used_in_attempts` counters as content conflicts.
The importer now compares content, revision/status, IDs and choice IDs, excluding usage
counters. Relevant verification passed afterwards. Two small content corrections were
rehearsed through audited edits: a literal newline escape in string prose and clearer
f-string wording; repr is introduced before diagnostic empty-file output.

## Dev boundary and review

Dev still contains only its original two published lessons; topics 3–18 remain draft
without imported material. The requested actor clarification was not answered. Existing
dev administrators are historical test accounts, so none was silently used to attribute
course content. The importer can authenticate a specified real administrator without
reading private DB credentials. Alternatively a dedicated course administrator can be
created through the established CLI after explicit identification/authorization.

No simulated-response checks, full historical regressions, other browsers or final
security review were rerun for this content-only change. No frontend/backend production
code, schema, serving configuration, prices or product rules changed. The existing build
was used for the real-browser sample; private source protection was checked directly.

Review all lessons and questions, especially wording, prerequisite progression and
conceptual distractors. Follow `content/README.md` to import dev drafts under the identified
actor, then obtain separate publication approval. Publish only through the existing admin
UI: activate reviewed questions, publish reviewed lesson, then make topic available after
readiness succeeds. No dev publication was authorized or performed.
