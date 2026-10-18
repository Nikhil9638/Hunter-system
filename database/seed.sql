-- =============================================================================
-- Hunter System - seed data
-- For schema version 2026.10.14-3 (MariaDB 10.4, utf8mb4).
--
-- What this loads:
--   * Reference data: experience levels, E-S ranks, six skill groups,
--     the 18 curriculum topics in confirmed order.
--   * Policies APPROVED on 2026-10-14 (version 2, current) for quiz grading,
--     assessment/placement, first-completion reward, rank progression, showcase
--     limit and activity windows. The earlier provisional version 1 rows are
--     kept as 'retired' history.
--   * Lessons + 12-question quiz pools for topics 1 and 2 (development content
--     awaiting the project owner's review). All other topics stay 'draft'.
--   * 24 assessment questions (4 per skill group) for the 10-question assessment.
--   * 4 sample badges: status 'draft', price_status 'provisional',
--     placeholder image references.
--
-- What this does NOT load: users, administrators, passwords, attempts,
-- progress, coins or any other student data.
--
-- Re-run safety: every row has a stable id. A row is inserted only when its id
-- is absent, so re-running never duplicates rows and never overwrites content
-- that has since been edited. Other conflicts (e.g. a different row already
-- using the same slug) still raise an error instead of being ignored.
-- No DROP, TRUNCATE, DELETE or UPDATE statements.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

DELIMITER $$
BEGIN NOT ATOMIC
  IF (SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_info') = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seed.sql: schema_info not found. Import database/schema.sql into this database first.';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM schema_info WHERE id = 1 AND schema_version = '2026.10.14-3') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seed.sql: schema version mismatch. This seed expects schema 2026.10.14-3.';
  END IF;
END$$
DELIMITER ;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Experience levels (C-04)
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_experience_levels AS
  SELECT id, code, label, sort_order FROM experience_levels LIMIT 0;
INSERT INTO seed_experience_levels (id, code, label, sort_order) VALUES
  (1, 'complete_beginner', 'Complete beginner',            1),
  (2, 'basic',             'Knows a little basic Python', 2),
  (3, 'intermediate',      'Intermediate',                 3),
  (4, 'master',            'Master',                       4);
INSERT INTO experience_levels (id, code, label, sort_order)
  SELECT s.id, s.code, s.label, s.sort_order FROM seed_experience_levels s
   WHERE NOT EXISTS (SELECT 1 FROM experience_levels t WHERE t.id = s.id);
SELECT 'experience_levels' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Ranks (C-24). Only E has a confirmed title.
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_ranks AS
  SELECT id, code, sort_order, display_name, title FROM ranks LIMIT 0;
INSERT INTO seed_ranks (id, code, sort_order, display_name, title) VALUES
  (1, 'E', 1, 'E-Rank', 'Rookie'),
  (2, 'D', 2, 'D-Rank', NULL),
  (3, 'C', 3, 'C-Rank', NULL),
  (4, 'B', 4, 'B-Rank', NULL),
  (5, 'A', 5, 'A-Rank', NULL),
  (6, 'S', 6, 'S-Rank', NULL);
INSERT INTO ranks (id, code, sort_order, display_name, title)
  SELECT s.id, s.code, s.sort_order, s.display_name, s.title FROM seed_ranks s
   WHERE NOT EXISTS (SELECT 1 FROM ranks t WHERE t.id = s.id);
SELECT 'ranks' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Skill groups (P-27, provisional grouping used by the assessment)
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_skill_groups AS
  SELECT id, code, name, sort_order FROM skill_groups LIMIT 0;
INSERT INTO seed_skill_groups (id, code, name, sort_order) VALUES
  (1, 'G1', 'Python basics',               1),
  (2, 'G2', 'Input and operators',         2),
  (3, 'G3', 'Control flow',                3),
  (4, 'G4', 'Text and collections',        4),
  (5, 'G5', 'Functions, modules, errors',  5),
  (6, 'G6', 'Files and objects',           6);
INSERT INTO skill_groups (id, code, name, sort_order)
  SELECT s.id, s.code, s.name, s.sort_order FROM seed_skill_groups s
   WHERE NOT EXISTS (SELECT 1 FROM skill_groups t WHERE t.id = s.id);
SELECT 'skill_groups' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Topics (C-25), confirmed order. Only topics 1-2 have a lesson and a quiz pool,
-- so only they are 'available'. The rest are 'draft' until content is ready.
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_topics AS
  SELECT id, position, slug, title, summary, skill_group_id, status FROM topics LIMIT 0;
INSERT INTO seed_topics (id, position, slug, title, summary, skill_group_id, status) VALUES
  ( 1,  1, 'python-introduction',              'Python introduction',              'Purpose of Python, basic syntax, indentation, comments, print()', 1, 'available'),
  ( 2,  2, 'variables-and-assignment',         'Variables and assignment',         'Names, assigning and updating values',                         1, 'available'),
  ( 3,  3, 'basic-data-types',                 'Basic data types',                 'Integers, floats, strings, booleans',                          1, 'draft'),
  ( 4,  4, 'input-and-type-conversion',        'Input and type conversion',        'input(), int(), float(), str()',                               2, 'draft'),
  ( 5,  5, 'arithmetic-operators',             'Arithmetic operators',             '+ - * / // % **',                                              2, 'draft'),
  ( 6,  6, 'comparison-and-logical-operators', 'Comparison and logical operators', '== != < > <= >=, and, or, not',                                2, 'draft'),
  ( 7,  7, 'conditions',                       'Conditions',                       'if, elif, else, nested conditions',                            3, 'draft'),
  ( 8,  8, 'loops',                            'Loops',                            'for, while, range()',                                          3, 'draft'),
  ( 9,  9, 'loop-control',                     'Loop control',                     'break, continue, nested loops',                                3, 'draft'),
  (10, 10, 'strings',                          'Strings',                          'Indexing, slicing, methods, formatting',                       4, 'draft'),
  (11, 11, 'lists',                            'Lists',                            'Creation, indexing, updates, methods, iteration',              4, 'draft'),
  (12, 12, 'tuples-and-sets',                  'Tuples and sets',                  'Tuples and sets',                                              4, 'draft'),
  (13, 13, 'dictionaries',                     'Dictionaries',                     'Dictionaries',                                                 4, 'draft'),
  (14, 14, 'functions',                        'Functions',                        'Parameters, return values, scope basics',                      5, 'draft'),
  (15, 15, 'modules-and-imports',              'Modules and imports',              'Modules and imports',                                          5, 'draft'),
  (16, 16, 'exceptions',                       'Exceptions',                       'try, except, else, finally',                                   5, 'draft'),
  (17, 17, 'file-handling',                    'File handling',                    'Reading, writing, context managers',                           6, 'draft'),
  (18, 18, 'basic-oop',                        'Basic object-oriented programming','Classes, objects, attributes, methods',                        6, 'draft');
INSERT INTO topics (id, position, slug, title, summary, skill_group_id, status)
  SELECT s.id, s.position, s.slug, s.title, s.summary, s.skill_group_id, s.status FROM seed_topics s
   WHERE NOT EXISTS (SELECT 1 FROM topics t WHERE t.id = s.id);
SELECT 'topics' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Policies. Version 1 rows were the provisional development settings; they are
-- kept as 'retired' history. Version 2 rows hold the values APPROVED on
-- 2026-10-14 and are current. (A migrated 2026.10.14-1 database ends up with the
-- same ids and values; see migrations/002.)
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_quiz_grading_policies AS
  SELECT id, version, status, is_current, question_count, pass_mark_correct, min_pool_size, description, approved_at
    FROM quiz_grading_policies LIMIT 0;
INSERT INTO seed_quiz_grading_policies VALUES
  (1, 1, 'retired', NULL, 5, 3, 10,
   'Superseded provisional development setting (P-01, P-02). Replaced by version 2.', NULL),
  (2, 2, 'approved', 1, 5, 3, 10,
   'Approved 2026-10-14: five-question quiz, pass at 3/5 (grade C or better), A-F scale. Topic ready when its active quiz pool has at least 10 questions.',
   '2026-10-14 00:00:00.000');
INSERT INTO quiz_grading_policies (id, version, status, is_current, question_count, pass_mark_correct, min_pool_size, description, approved_at)
  SELECT s.id, s.version, s.status, s.is_current, s.question_count, s.pass_mark_correct, s.min_pool_size, s.description, s.approved_at
    FROM seed_quiz_grading_policies s
   WHERE NOT EXISTS (SELECT 1 FROM quiz_grading_policies t WHERE t.id = s.id)
   ORDER BY s.id;
SELECT 'quiz_grading_policies' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_quiz_grade_bands AS
  SELECT policy_id, correct_count, grade_letter, percent FROM quiz_grade_bands LIMIT 0;
INSERT INTO seed_quiz_grade_bands VALUES
  (1, 5, 'A', 100), (1, 4, 'B', 80), (1, 3, 'C', 60),
  (1, 2, 'D', 40),  (1, 1, 'E', 20), (1, 0, 'F', 0),
  (2, 5, 'A', 100), (2, 4, 'B', 80), (2, 3, 'C', 60),
  (2, 2, 'D', 40),  (2, 1, 'E', 20), (2, 0, 'F', 0);
INSERT INTO quiz_grade_bands (policy_id, correct_count, grade_letter, percent)
  SELECT s.policy_id, s.correct_count, s.grade_letter, s.percent FROM seed_quiz_grade_bands s
   WHERE NOT EXISTS (SELECT 1 FROM quiz_grade_bands t WHERE t.policy_id = s.policy_id AND t.correct_count = s.correct_count);
SELECT 'quiz_grade_bands' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_assessment_policies AS
  SELECT id, version, status, is_current, question_count, starting_topic_rule, fallback_rule, all_correct_topic_id, description, approved_at
    FROM assessment_policies LIMIT 0;
INSERT INTO seed_assessment_policies VALUES
  (1, 1, 'retired', NULL, 10, 'earliest_missed_group_first_topic', 'nearest_earlier_ready_topic', 18,
   'Superseded provisional development setting (P-19, P-27, P-28). Replaced by version 2.', NULL),
  (2, 2, 'approved', 1, 10, 'earliest_missed_group_first_topic', 'nearest_earlier_ready_topic', 18,
   'Approved 2026-10-14: 2/2/2/2/1/1 layout; 0-2 E, 3-4 D, 5-6 C, 7-8 B, 9 A, 10 S; first topic of earliest missed group (all correct: 18); if not ready, also offer nearest earlier ready topic.',
   '2026-10-14 00:00:00.000');
INSERT INTO assessment_policies (id, version, status, is_current, question_count, starting_topic_rule, fallback_rule, all_correct_topic_id, description, approved_at)
  SELECT s.id, s.version, s.status, s.is_current, s.question_count, s.starting_topic_rule, s.fallback_rule, s.all_correct_topic_id, s.description, s.approved_at
    FROM seed_assessment_policies s
   WHERE NOT EXISTS (SELECT 1 FROM assessment_policies t WHERE t.id = s.id)
   ORDER BY s.id;
SELECT 'assessment_policies' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_assessment_group_quotas AS
  SELECT policy_id, skill_group_id, question_count FROM assessment_group_quotas LIMIT 0;
INSERT INTO seed_assessment_group_quotas VALUES
  (1, 1, 2), (1, 2, 2), (1, 3, 2), (1, 4, 2), (1, 5, 1), (1, 6, 1),
  (2, 1, 2), (2, 2, 2), (2, 3, 2), (2, 4, 2), (2, 5, 1), (2, 6, 1);
INSERT INTO assessment_group_quotas (policy_id, skill_group_id, question_count)
  SELECT s.policy_id, s.skill_group_id, s.question_count FROM seed_assessment_group_quotas s
   WHERE NOT EXISTS (SELECT 1 FROM assessment_group_quotas t WHERE t.policy_id = s.policy_id AND t.skill_group_id = s.skill_group_id);
SELECT 'assessment_group_quotas' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_placement_rank_bands AS
  SELECT policy_id, min_correct, max_correct, rank_id FROM placement_rank_bands LIMIT 0;
INSERT INTO seed_placement_rank_bands VALUES
  (1, 0, 2, 1), (1, 3, 4, 2), (1, 5, 6, 3), (1, 7, 8, 4), (1, 9, 9, 5), (1, 10, 10, 6),
  (2, 0, 2, 1),   -- E
  (2, 3, 4, 2),   -- D
  (2, 5, 6, 3),   -- C
  (2, 7, 8, 4),   -- B
  (2, 9, 9, 5),   -- A
  (2, 10, 10, 6); -- S
INSERT INTO placement_rank_bands (policy_id, min_correct, max_correct, rank_id)
  SELECT s.policy_id, s.min_correct, s.max_correct, s.rank_id FROM seed_placement_rank_bands s
   WHERE NOT EXISTS (SELECT 1 FROM placement_rank_bands t WHERE t.policy_id = s.policy_id AND t.min_correct = s.min_correct);
SELECT 'placement_rank_bands' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_reward_policies AS
  SELECT id, reward_type, version, status, is_current, coin_amount, description, approved_at FROM reward_policies LIMIT 0;
INSERT INTO seed_reward_policies VALUES
  (1, 'topic_first_completion', 1, 'retired', NULL, 10,
   'Superseded provisional development setting (P-10..P-14). Replaced by version 2.', NULL),
  (2, 'topic_first_completion', 2, 'approved', 1, 10,
   'Approved 2026-10-14: 10 coins once per student per topic on the first completed quiz, even if failed. No coins for partial attempts, retries or the placement assessment.',
   '2026-10-14 00:00:00.000');
INSERT INTO reward_policies (id, reward_type, version, status, is_current, coin_amount, description, approved_at)
  SELECT s.id, s.reward_type, s.version, s.status, s.is_current, s.coin_amount, s.description, s.approved_at FROM seed_reward_policies s
   WHERE NOT EXISTS (SELECT 1 FROM reward_policies t WHERE t.id = s.id)
   ORDER BY s.id;
SELECT 'reward_policies' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_rank_progression_policies AS
  SELECT id, version, status, is_current, description, approved_at FROM rank_progression_policies LIMIT 0;
INSERT INTO seed_rank_progression_policies VALUES
  (1, 1, 'approved', 1,
   'Approved 2026-10-14: rank by distinct topics passed (incl. below placement): 0-2 E, 3-5 D, 6-8 C, 9-11 B, 12-14 A, 15-18 S. Current rank = highest of placement, previous and earned; never decreases.',
   '2026-10-14 00:00:00.000');
INSERT INTO rank_progression_policies (id, version, status, is_current, description, approved_at)
  SELECT s.id, s.version, s.status, s.is_current, s.description, s.approved_at FROM seed_rank_progression_policies s
   WHERE NOT EXISTS (SELECT 1 FROM rank_progression_policies t WHERE t.id = s.id);
SELECT 'rank_progression_policies' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_rank_progression_bands AS
  SELECT policy_id, min_topics_passed, max_topics_passed, rank_id FROM rank_progression_bands LIMIT 0;
INSERT INTO seed_rank_progression_bands VALUES
  (1, 0, 2, 1),     -- E
  (1, 3, 5, 2),     -- D
  (1, 6, 8, 3),     -- C
  (1, 9, 11, 4),    -- B
  (1, 12, 14, 5),   -- A
  (1, 15, 18, 6);   -- S
INSERT INTO rank_progression_bands (policy_id, min_topics_passed, max_topics_passed, rank_id)
  SELECT s.policy_id, s.min_topics_passed, s.max_topics_passed, s.rank_id FROM seed_rank_progression_bands s
   WHERE NOT EXISTS (SELECT 1 FROM rank_progression_bands t WHERE t.policy_id = s.policy_id AND t.min_topics_passed = s.min_topics_passed);
SELECT 'rank_progression_bands' AS seeded_table, ROW_COUNT() AS rows_inserted;

CREATE TEMPORARY TABLE seed_app_policies AS
  SELECT id, version, status, is_current, showcase_limit, recently_active_minutes, session_idle_minutes,
         activity_touch_seconds, description, approved_at FROM app_policies LIMIT 0;
INSERT INTO seed_app_policies VALUES
  (1, 1, 'retired', NULL, 3, 15, 30, 60,
   'Superseded provisional development setting (P-16, P-29, P-30, P-31). Replaced by version 2.', NULL),
  (2, 2, 'approved', 1, 3, 15, 30, 60,
   'Approved 2026-10-14: showcase up to 3 owned badges; recently active = 15 min; idle expiry = 30 min; activity update about every 60 s.',
   '2026-10-14 00:00:00.000');
INSERT INTO app_policies (id, version, status, is_current, showcase_limit, recently_active_minutes, session_idle_minutes,
                          activity_touch_seconds, description, approved_at)
  SELECT s.id, s.version, s.status, s.is_current, s.showcase_limit, s.recently_active_minutes, s.session_idle_minutes,
         s.activity_touch_seconds, s.description, s.approved_at FROM seed_app_policies s
   WHERE NOT EXISTS (SELECT 1 FROM app_policies t WHERE t.id = s.id)
   ORDER BY s.id;
SELECT 'app_policies' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Lessons for topics 1 and 2 (development content; review before release)
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_lessons AS
  SELECT id, topic_id, title, body, status, revision FROM lessons LIMIT 0;
INSERT INTO seed_lessons (id, topic_id, title, body, status, revision) VALUES
(1, 1, 'Python introduction',
'# Python introduction

Python is a general-purpose programming language known for readable code. People use it for automation scripts, data analysis, web back ends and for learning to program.

## Your first program

```python
print("Hello, World!")
```

`print()` displays a value on the screen. Text values (strings) are written inside quotes. The quotes mark where the text starts and ends; they are not displayed.

## More about print()

- `print("Hi", "there")` prints several values separated by one space: `Hi there`.
- `print(2 + 3)` prints `5`, because `2 + 3` without quotes is a calculation.
- `print("2" + "3")` prints `23`, because `+` joins two strings.
- `print()` with nothing inside prints an empty line.
- `\\n` inside a string starts a new line.

## Comments

A comment starts with `#`. Python ignores everything after `#` on that line, so comments are notes for people.

```python
# This line is ignored
print("Shown")  # this part is ignored too
```

## Indentation

Python uses indentation (spaces at the start of a line) to group statements into blocks, for example the body of an `if` statement. Indenting a line for no reason causes an `IndentationError`, and Python does not run the program at all.

## Case sensitivity

Python is case-sensitive. `print` works; `Print` and `PRINT` are unknown names.
', 'published', 1),
(2, 2, 'Variables and assignment',
'# Variables and assignment

A **variable** is a name that refers to a value.

```python
count = 0
name = "Ana"
```

The `=` sign is the **assignment operator**: it stores the value on the right under the name on the left. (Checking equality uses `==`, which comes in a later topic.)

## Updating a value

```python
x = 5
x = x + 2   # uses the old value 5, then stores 7
```

Shorthand operators do the same thing: `x += 2`, `x -= 2`, `x *= 3`.

## Naming rules

- Use letters, digits and underscores: `total_score`, `item2`.
- A name cannot start with a digit (`2nd_place` is invalid).
- No hyphens or spaces (`my-score` is invalid).
- Keywords such as `class`, `if` and `for` cannot be names.
- Names are case-sensitive: `name` and `Name` are different variables.

## Several names at once

```python
x, y = 3, 4      # x is 3, y is 4
a, b = b, a      # swaps the values of a and b
a = b = 0        # both names get 0
```

## Assignment does not link two names

```python
a = 10
b = a
a = 20
print(b)   # 10
```

`b = a` gives `b` the value `a` had at that moment. Reassigning `a` later does not change `b`.

## Common mistakes

- Using a name before giving it a value raises a `NameError`.
- A variable can be reassigned to a value of a different type, for example from `4` to `"four"`. The latest assignment wins.
', 'published', 1);
INSERT INTO lessons (id, topic_id, title, body, status, revision, published_at)
  SELECT s.id, s.topic_id, s.title, s.body, s.status, s.revision, UTC_TIMESTAMP(3) FROM seed_lessons s
   WHERE NOT EXISTS (SELECT 1 FROM lessons t WHERE t.id = s.id);
SELECT 'lessons' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Questions. Stable ids: topic * 100 + n. Quiz pool: n = 1..12.
-- Assessment pool: n = 51+. Choice ids: question_id * 10 + 1..4.
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_questions AS
  SELECT id, topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status FROM questions LIMIT 0;

-- Topic 1: Python introduction (quiz pool)
INSERT INTO seed_questions VALUES
(101, 1, 1, 0, 'easy', 'What does this code display?', 'print("Hello, World!")',
 'print() shows the value of the string. The quotes mark the start and end of the string and are not displayed.', 'active'),
(102, 1, 1, 0, 'easy', 'Which symbol starts a single-line comment in Python?', NULL,
 'Python ignores everything after # on the same line.', 'active'),
(103, 1, 1, 0, 'easy', 'How does Python mark a block of code, such as the body of an if statement?', NULL,
 'Python uses indentation (leading spaces) to group statements into blocks.', 'active'),
(104, 1, 1, 0, 'easy', 'What does this code display?', 'print("A")
# print("B")
print("C")',
 'The middle line is a comment, so only A and C are printed, each on its own line.', 'active'),
(105, 1, 1, 0, 'easy', 'What does this code display?', 'print(2 + 3)',
 'Without quotes, 2 + 3 is an arithmetic expression, so Python prints its value.', 'active'),
(106, 1, 1, 0, 'easy', 'What does this code display?', 'print("2" + "3")',
 'Both values are strings, so + joins (concatenates) them.', 'active'),
(107, 1, 1, 0, 'easy', 'Which statement about Python is true?', NULL,
 'Python is a general-purpose language. Lines do not need semicolons, and you do not compile Python to machine code yourself before running it.', 'active'),
(108, 1, 1, 0, 'easy', 'What does this code display?', 'print("Hi", "there")',
 'When print() receives several values, it separates them with one space by default.', 'active'),
(109, 1, 1, 0, 'medium', 'What happens when this program is run?', 'print("Start")
    print("End")',
 'The second line is indented for no reason. Python checks the whole file before running it, so it reports an IndentationError and prints nothing.', 'active'),
(110, 1, 1, 0, 'easy', 'Python is case-sensitive. Which line runs without an error?', NULL,
 'The built-in function is called print, in lowercase, and its arguments go inside round brackets.', 'active'),
(111, 1, 1, 0, 'medium', 'What does this code display?', 'print("Line1\\nLine2")',
 '\\n inside a string is a newline character, so the text is split over two lines.', 'active'),
(112, 1, 1, 0, 'medium', 'What does print() display when it is called with nothing inside the brackets?', NULL,
 'print() always ends its output with a newline, so with no values it prints an empty line.', 'active');

-- Topic 2: Variables and assignment (quiz pool)
INSERT INTO seed_questions VALUES
(201, 2, 1, 0, 'easy', 'What does this code display?', 'x = 5
x = x + 2
print(x)',
 'x + 2 is calculated with the old value (5), and the result 7 is assigned back to x.', 'active'),
(202, 2, 1, 0, 'easy', 'Which of these is a valid Python variable name?', NULL,
 'Names may contain letters, digits and underscores. They cannot start with a digit, cannot contain hyphens and cannot be a keyword such as class.', 'active'),
(203, 2, 1, 0, 'medium', 'What does this code display?', 'a = 10
b = a
a = 20
print(b)',
 'b = a gives b the value 10. Assigning a new value to a afterwards does not change b.', 'active'),
(204, 2, 1, 0, 'easy', 'In the statement count = 0, what does the = sign do?', NULL,
 'In Python, = is the assignment operator. Equality is tested with ==.', 'active'),
(205, 2, 1, 0, 'easy', 'What does this code display?', 'x, y = 3, 4
print(x, y)',
 'Multiple assignment gives x the first value and y the second. print() separates them with a space.', 'active'),
(206, 2, 1, 0, 'easy', 'What does this code display?', 'score = 10
score += 5
print(score)',
 'score += 5 is shorthand for score = score + 5.', 'active'),
(207, 2, 1, 0, 'easy', 'What does this code display?', 'name = "Ana"
Name = "Ben"
print(name)',
 'Names are case-sensitive, so name and Name are two different variables.', 'active'),
(208, 2, 1, 0, 'medium', 'What does this code display?', 'a = 1
b = 2
a, b = b, a
print(a, b)',
 'The right-hand side (2, 1) is evaluated first and then assigned to a and b, which swaps them.', 'active'),
(209, 2, 1, 0, 'easy', 'What happens when this program runs, if total has never been assigned a value?', 'print(total)',
 'Using a name that has not been assigned a value raises a NameError.', 'active'),
(210, 2, 1, 0, 'easy', 'What does this code display?', 'x = 4
x = "four"
print(x)',
 'A variable can be reassigned to a value of a different type. The latest assignment wins.', 'active'),
(211, 2, 1, 0, 'medium', 'What does this code display?', 'n = 7
n -= 2
n *= 3
print(n)',
 'n -= 2 makes n equal to 5, then n *= 3 makes it 15.', 'active'),
(212, 2, 1, 0, 'medium', 'What does this code display?', 'a = b = 0
b = 5
print(a, b)',
 'a = b = 0 gives both names the value 0. Reassigning b afterwards does not change a.', 'active');

-- Assessment pool: 4 questions per skill group (quota 2/2/2/2/1/1 needs at most 2 per group)
INSERT INTO seed_questions VALUES
-- G1 Python basics
(151, 1, 0, 1, 'easy', 'Which line correctly prints the word Python?', NULL,
 'Text must be a string in quotes, passed to print() inside round brackets. print "Python" is old Python 2 syntax and is a SyntaxError in Python 3.', 'active'),
(251, 2, 0, 1, 'medium', 'What does this code display?', 'x = 3
y = x
x = x * 2
print(x, y)',
 'y gets the value 3. x is then reassigned to 6, which does not affect y.', 'active'),
(351, 3, 0, 1, 'easy', 'What does this code display?', 'print(type(3.0))',
 '3.0 has a decimal point, so it is a float.', 'active'),
(352, 3, 0, 1, 'easy', 'Which of these values is a string?', NULL,
 'Quotes make a string. 42 is an int, 4.2 is a float and True is a bool.', 'active'),
-- G2 Input and operators
(451, 4, 0, 1, 'medium', 'The user types 5 and presses Enter. What does this code display?', 'n = input()
print(n * 2)',
 'input() always returns a string. Multiplying the string "5" by 2 repeats it, giving 55.', 'active'),
(452, 4, 0, 1, 'easy', 'Which expression converts the string "12" into the integer 12?', NULL,
 'int() converts a string of digits into an integer. float("12") gives 12.0, which is a float.', 'active'),
(551, 5, 0, 1, 'medium', 'What does this code display?', 'print(17 // 5, 17 % 5)',
 '// is floor division (17 // 5 is 3) and % gives the remainder (17 % 5 is 2).', 'active'),
(651, 6, 0, 1, 'easy', 'What does this code display?', 'print(5 > 3 and 2 > 4)',
 'and is True only when both sides are True. 2 > 4 is False, so the result is False.', 'active'),
-- G3 Control flow
(751, 7, 0, 1, 'easy', 'What does this code display?', 'x = 7
if x > 10:
    print("big")
elif x > 5:
    print("medium")
else:
    print("small")',
 'x > 10 is False and x > 5 is True, so only the elif branch runs.', 'active'),
(851, 8, 0, 1, 'easy', 'What does this code display?', 'for i in range(3):
    print(i, end=" ")',
 'range(3) produces 0, 1 and 2. It stops before 3.', 'active'),
(852, 8, 0, 1, 'medium', 'What does this code display?', 'count = 0
while count < 3:
    count += 1
print(count)',
 'The loop body runs while count is 0, 1 and 2. After the third pass count is 3 and the condition is False.', 'active'),
(951, 9, 0, 1, 'medium', 'What does this code display?', 'for n in range(1, 6):
    if n == 4:
        break
    print(n, end=" ")',
 'break ends the loop as soon as n is 4, before 4 is printed.', 'active'),
-- G4 Text and collections
(1051, 10, 0, 1, 'medium', 'What does this code display?', 's = "Python"
print(s[1:4])',
 'The slice starts at index 1 (y) and stops before index 4, giving yth.', 'active'),
(1151, 11, 0, 1, 'easy', 'What does this code display?', 'nums = [1, 2, 3]
nums.append(4)
print(len(nums))',
 'append() adds one item to the end of the list, so it now has 4 items.', 'active'),
(1251, 12, 0, 1, 'medium', 'What does this code display?', 'print(len({1, 2, 2, 3}))',
 'A set keeps only unique values, so {1, 2, 2, 3} contains 3 items.', 'active'),
(1351, 13, 0, 1, 'easy', 'What does this code display?', 'd = {"a": 1, "b": 2}
print(d["b"])',
 'Square brackets with a key look up the value stored for that key. The value for "b" is 2.', 'active'),
-- G5 Functions, modules, errors
(1451, 14, 0, 1, 'medium', 'What does this code display?', 'def add(a, b=2):
    return a + b

print(add(3))',
 'Only one argument is given, so b uses its default value 2 and the function returns 3 + 2.', 'active'),
(1452, 14, 0, 1, 'medium', 'What happens when this code runs?', 'def f():
    x = 1

f()
print(x)',
 'x is a local variable inside f. It does not exist outside the function, so print(x) raises a NameError.', 'active'),
(1551, 15, 0, 1, 'easy', 'Which statement imports only the sqrt function from the math module?', NULL,
 'from module import name imports one name so it can be used directly, as in sqrt(16).', 'active'),
(1651, 16, 0, 1, 'medium', 'What does this code display?', 'try:
    print(10 / 0)
except ZeroDivisionError:
    print("oops")
finally:
    print("done")',
 'Dividing by zero raises ZeroDivisionError, so the except block prints oops. The finally block always runs and prints done.', 'active'),
-- G6 Files and objects
(1751, 17, 0, 1, 'easy', 'Which mode passed to open() writes to a file and replaces any existing contents?', NULL,
 '"w" opens for writing and empties the file first. "a" appends, "r" reads, and "x" fails if the file already exists.', 'active'),
(1752, 17, 0, 1, 'medium', 'What is the main benefit of opening a file with a with statement, as in: with open("data.txt") as f:', NULL,
 'The with statement is a context manager: it closes the file automatically when the block ends, even if an error occurs.', 'active'),
(1851, 18, 0, 1, 'easy', 'What does this code display?', 'class Dog:
    def __init__(self, name):
        self.name = name

d = Dog("Rex")
print(d.name)',
 '__init__ runs when the object is created and stores "Rex" in the attribute name.', 'active'),
(1852, 18, 0, 1, 'medium', 'What does this code display?', 'class Counter:
    def __init__(self):
        self.value = 0

    def increase(self):
        self.value += 1

c = Counter()
c.increase()
c.increase()
print(c.value)',
 'Each call to increase() adds 1 to the value attribute, which started at 0.', 'active');

INSERT INTO questions (id, topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status)
  SELECT s.id, s.topic_id, s.for_quiz, s.for_assessment, s.difficulty, s.stem, s.code_snippet, s.explanation, s.status
    FROM seed_questions s
   WHERE NOT EXISTS (SELECT 1 FROM questions t WHERE t.id = s.id);
SELECT 'questions' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Answer choices (exactly one correct per question)
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_question_choices AS
  SELECT id, question_id, label, choice_text, is_correct FROM question_choices LIMIT 0;
INSERT INTO seed_question_choices (id, question_id, label, choice_text, is_correct) VALUES
-- Topic 1
(1011, 101, 'A', 'Hello, World!', 1),
(1012, 101, 'B', '"Hello, World!"', 0),
(1013, 101, 'C', 'print Hello, World!', 0),
(1014, 101, 'D', 'Nothing; the code causes an error.', 0),
(1021, 102, 'A', '//', 0),
(1022, 102, 'B', '#', 1),
(1023, 102, 'C', '/*', 0),
(1024, 102, 'D', '--', 0),
(1031, 103, 'A', 'Curly braces { }', 0),
(1032, 103, 'B', 'Semicolons', 0),
(1033, 103, 'C', 'Indentation', 1),
(1034, 103, 'D', 'The keyword end', 0),
(1041, 104, 'A', 'A, B and C, each on its own line', 0),
(1042, 104, 'B', 'A and C, each on its own line', 1),
(1043, 104, 'C', 'Only A', 0),
(1044, 104, 'D', 'An error, because a line is commented out', 0),
(1051, 105, 'A', '2 + 3', 0),
(1052, 105, 'B', '5', 1),
(1053, 105, 'C', '23', 0),
(1054, 105, 'D', '"5"', 0),
(1061, 106, 'A', '5', 0),
(1062, 106, 'B', '23', 1),
(1063, 106, 'C', '2 + 3', 0),
(1064, 106, 'D', 'An error', 0),
(1071, 107, 'A', 'Every Python line must end with a semicolon.', 0),
(1072, 107, 'B', 'Python can only be used to build websites.', 0),
(1073, 107, 'C', 'Python is a general-purpose language used for tasks such as automation, data analysis and web development.', 1),
(1074, 107, 'D', 'You must compile Python code to machine code yourself before running it.', 0),
(1081, 108, 'A', 'Hithere', 0),
(1082, 108, 'B', 'Hi there', 1),
(1083, 108, 'C', 'Hi, there', 0),
(1084, 108, 'D', '"Hi" "there"', 0),
(1091, 109, 'A', 'Start and End are printed on separate lines.', 0),
(1092, 109, 'B', 'Start is printed, then an error is shown.', 0),
(1093, 109, 'C', 'An IndentationError is reported and nothing is printed.', 1),
(1094, 109, 'D', 'End is printed first, then Start.', 0),
(1101, 110, 'A', 'Print("Hi")', 0),
(1102, 110, 'B', 'PRINT("Hi")', 0),
(1103, 110, 'C', 'print["Hi"]', 0),
(1104, 110, 'D', 'print("Hi")', 1),
(1111, 111, 'A', 'Line1\\nLine2', 0),
(1112, 111, 'B', 'Line1 and Line2 on separate lines', 1),
(1113, 111, 'C', 'Line1Line2', 0),
(1114, 111, 'D', 'Line1 nLine2', 0),
(1121, 112, 'A', 'Nothing at all, not even a new line', 0),
(1122, 112, 'B', 'An empty line', 1),
(1123, 112, 'C', 'None', 0),
(1124, 112, 'D', 'An error', 0),
-- Topic 2
(2011, 201, 'A', '5', 0),
(2012, 201, 'B', '7', 1),
(2013, 201, 'C', 'x + 2', 0),
(2014, 201, 'D', '52', 0),
(2021, 202, 'A', '2nd_place', 0),
(2022, 202, 'B', 'my-score', 0),
(2023, 202, 'C', 'total_score', 1),
(2024, 202, 'D', 'class', 0),
(2031, 203, 'A', '10', 1),
(2032, 203, 'B', '20', 0),
(2033, 203, 'C', 'a', 0),
(2034, 203, 'D', 'An error', 0),
(2041, 204, 'A', 'It checks whether count is equal to 0.', 0),
(2042, 204, 'B', 'It assigns the value 0 to the name count.', 1),
(2043, 204, 'C', 'It creates a constant that can never change.', 0),
(2044, 204, 'D', 'It prints 0.', 0),
(2051, 205, 'A', '3 4', 1),
(2052, 205, 'B', '(3, 4)', 0),
(2053, 205, 'C', '7', 0),
(2054, 205, 'D', '4 3', 0),
(2061, 206, 'A', '5', 0),
(2062, 206, 'B', '10', 0),
(2063, 206, 'C', '15', 1),
(2064, 206, 'D', '105', 0),
(2071, 207, 'A', 'Ana', 1),
(2072, 207, 'B', 'Ben', 0),
(2073, 207, 'C', 'Ana Ben', 0),
(2074, 207, 'D', 'An error', 0),
(2081, 208, 'A', '1 2', 0),
(2082, 208, 'B', '2 1', 1),
(2083, 208, 'C', '2 2', 0),
(2084, 208, 'D', '1 1', 0),
(2091, 209, 'A', 'It prints 0.', 0),
(2092, 209, 'B', 'It prints nothing.', 0),
(2093, 209, 'C', 'It raises a NameError.', 1),
(2094, 209, 'D', 'It prints the word total.', 0),
(2101, 210, 'A', '4', 0),
(2102, 210, 'B', 'four', 1),
(2103, 210, 'C', 'An error, because x already holds a number', 0),
(2104, 210, 'D', 'x', 0),
(2111, 211, 'A', '15', 1),
(2112, 211, 'B', '19', 0),
(2113, 211, 'C', '21', 0),
(2114, 211, 'D', '5', 0),
(2121, 212, 'A', '5 5', 0),
(2122, 212, 'B', '0 0', 0),
(2123, 212, 'C', '0 5', 1),
(2124, 212, 'D', '5 0', 0),
-- Assessment G1
(1511, 151, 'A', 'print(Python)', 0),
(1512, 151, 'B', 'print "Python"', 0),
(1513, 151, 'C', 'print("Python")', 1),
(1514, 151, 'D', 'echo("Python")', 0),
(2511, 251, 'A', '6 6', 0),
(2512, 251, 'B', '6 3', 1),
(2513, 251, 'C', '3 6', 0),
(2514, 251, 'D', '3 3', 0),
(3511, 351, 'A', '<class ''int''>', 0),
(3512, 351, 'B', '<class ''float''>', 1),
(3513, 351, 'C', '<class ''str''>', 0),
(3514, 351, 'D', '<class ''decimal''>', 0),
(3521, 352, 'A', '42', 0),
(3522, 352, 'B', '4.2', 0),
(3523, 352, 'C', '"42"', 1),
(3524, 352, 'D', 'True', 0),
-- Assessment G2
(4511, 451, 'A', '10', 0),
(4512, 451, 'B', '55', 1),
(4513, 451, 'C', '5 5', 0),
(4514, 451, 'D', 'An error', 0),
(4521, 452, 'A', 'str(12)', 0),
(4522, 452, 'B', 'float("12")', 0),
(4523, 452, 'C', 'int("12")', 1),
(4524, 452, 'D', 'input("12")', 0),
(5511, 551, 'A', '3.4 2', 0),
(5512, 551, 'B', '3 2', 1),
(5513, 551, 'C', '3 3', 0),
(5514, 551, 'D', '2 3', 0),
(6511, 651, 'A', 'True', 0),
(6512, 651, 'B', 'False', 1),
(6513, 651, 'C', 'None', 0),
(6514, 651, 'D', 'An error', 0),
-- Assessment G3
(7511, 751, 'A', 'big', 0),
(7512, 751, 'B', 'medium', 1),
(7513, 751, 'C', 'small', 0),
(7514, 751, 'D', 'medium and small, on separate lines', 0),
(8511, 851, 'A', '1 2 3', 0),
(8512, 851, 'B', '0 1 2 3', 0),
(8513, 851, 'C', '0 1 2', 1),
(8514, 851, 'D', '3', 0),
(8521, 852, 'A', '2', 0),
(8522, 852, 'B', '3', 1),
(8523, 852, 'C', '4', 0),
(8524, 852, 'D', 'Nothing; the loop never ends.', 0),
(9511, 951, 'A', '1 2 3', 1),
(9512, 951, 'B', '1 2 3 4', 0),
(9513, 951, 'C', '1 2 3 5', 0),
(9514, 951, 'D', '4', 0),
-- Assessment G4
(10511, 1051, 'A', 'Pyt', 0),
(10512, 1051, 'B', 'yth', 1),
(10513, 1051, 'C', 'ytho', 0),
(10514, 1051, 'D', 'thon', 0),
(11511, 1151, 'A', '3', 0),
(11512, 1151, 'B', '4', 1),
(11513, 1151, 'C', '5', 0),
(11514, 1151, 'D', '[1, 2, 3, 4]', 0),
(12511, 1251, 'A', '4', 0),
(12512, 1251, 'B', '3', 1),
(12513, 1251, 'C', '2', 0),
(12514, 1251, 'D', 'An error', 0),
(13511, 1351, 'A', '1', 0),
(13512, 1351, 'B', '"b"', 0),
(13513, 1351, 'C', '2', 1),
(13514, 1351, 'D', 'An error', 0),
-- Assessment G5
(14511, 1451, 'A', '3', 0),
(14512, 1451, 'B', '5', 1),
(14513, 1451, 'C', 'An error, because b is missing', 0),
(14514, 1451, 'D', 'None', 0),
(14521, 1452, 'A', 'It prints 1.', 0),
(14522, 1452, 'B', 'It prints None.', 0),
(14523, 1452, 'C', 'It raises a NameError.', 1),
(14524, 1452, 'D', 'It prints 0.', 0),
(15511, 1551, 'A', 'import sqrt from math', 0),
(15512, 1551, 'B', 'from math import sqrt', 1),
(15513, 1551, 'C', 'import math.sqrt', 0),
(15514, 1551, 'D', 'include math.sqrt', 0),
(16511, 1651, 'A', 'oops and done, each on its own line', 1),
(16512, 1651, 'B', 'Only oops', 0),
(16513, 1651, 'C', 'Only done', 0),
(16514, 1651, 'D', 'An error message, then done', 0),
-- Assessment G6
(17511, 1751, 'A', '"r"', 0),
(17512, 1751, 'B', '"a"', 0),
(17513, 1751, 'C', '"w"', 1),
(17514, 1751, 'D', '"x"', 0),
(17521, 1752, 'A', 'The file is closed automatically when the block ends, even if an error occurs.', 1),
(17522, 1752, 'B', 'The file is read faster.', 0),
(17523, 1752, 'C', 'The file is created if it does not exist.', 0),
(17524, 1752, 'D', 'Other programs cannot open the file afterwards.', 0),
(18511, 1851, 'A', 'Dog', 0),
(18512, 1851, 'B', 'name', 0),
(18513, 1851, 'C', 'Rex', 1),
(18514, 1851, 'D', 'An error', 0),
(18521, 1852, 'A', '0', 0),
(18522, 1852, 'B', '1', 0),
(18523, 1852, 'C', '2', 1),
(18524, 1852, 'D', 'An error', 0);

INSERT INTO question_choices (id, question_id, label, choice_text, is_correct)
  SELECT s.id, s.question_id, s.label, s.choice_text, s.is_correct FROM seed_question_choices s
   WHERE NOT EXISTS (SELECT 1 FROM question_choices t WHERE t.id = s.id);
SELECT 'question_choices' AS seeded_table, ROW_COUNT() AS rows_inserted;

-- -----------------------------------------------------------------------------
-- Sample badges: NOT on sale ('draft'), PROVISIONAL prices and artwork,
-- placeholder images. They stay unavailable for purchase until approved.
-- -----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_badges AS
  SELECT id, slug, name, description, image_ref, price_coins, price_status, status FROM badges LIMIT 0;
INSERT INTO seed_badges VALUES
  (1, 'syntax-scout',     'Syntax Scout',     'Sample badge for development. Price is provisional.', 'placeholder/badges/syntax-scout.png',     20,  'provisional', 'draft'),
  (2, 'variable-voyager', 'Variable Voyager', 'Sample badge for development. Price is provisional.', 'placeholder/badges/variable-voyager.png', 40,  'provisional', 'draft'),
  (3, 'loop-ranger',      'Loop Ranger',      'Sample badge for development. Price is provisional.', 'placeholder/badges/loop-ranger.png',      60,  'provisional', 'draft'),
  (4, 'gate-breaker',     'Gate Breaker',     'Sample badge for development. Price is provisional.', 'placeholder/badges/gate-breaker.png',     100, 'provisional', 'draft');
INSERT INTO badges (id, slug, name, description, image_ref, price_coins, price_status, status)
  SELECT s.id, s.slug, s.name, s.description, s.image_ref, s.price_coins, s.price_status, s.status FROM seed_badges s
   WHERE NOT EXISTS (SELECT 1 FROM badges t WHERE t.id = s.id);
SELECT 'badges' AS seeded_table, ROW_COUNT() AS rows_inserted;

COMMIT;
