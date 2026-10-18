-- =============================================================================
-- Hunter System - database verification
-- For schema version 2026.10.14-3 (MariaDB 10.4).
--
--   mysql -u <user> -p hunter_system_dev < database/verify.sql
--
-- Part A  read-only checks of the installed schema, reference data and data
--          integrity. These hold for ANY content (standard seed or fixtures).
-- Part S  assumptions about the STANDARD seed content (sample quiz pools, sample
--          badges kept off sale). On a database whose content profile is not the
--          standard seed (e.g. the isolated test fixture), they report INFO
--          "not applicable" instead of PASS/FAIL, so fixture content cannot cause
--          misleading failures and cannot hide a real structural failure.
-- Part B  integrity tests. All test records are created inside ONE transaction
--         that is ROLLED BACK at the end, so nothing is left behind. (History
--         tables are append-only by trigger, so rollback, not deletion, is the
--         cleanup method.)
-- Part C  confirms the rollback left no test records.
--
-- Output: one row per check with PASS / FAIL / INFO, then a summary.
-- A FAIL row explains what happened. The script itself never stops on a
-- failed check; expected errors are caught and compared with the expected
-- error number (1452 FK, 1062 duplicate, 1644 trigger SIGNAL, 4025 CHECK).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

-- MEMORY engine: not transactional, so results survive the ROLLBACK in Part B.
CREATE TEMPORARY TABLE verify_results (
  seq         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  section     CHAR(1)      NOT NULL,
  check_name  VARCHAR(110) NOT NULL,
  outcome     ENUM('PASS','FAIL','INFO') NOT NULL,
  detail      VARCHAR(400) NOT NULL
) ENGINE=MEMORY;

-- =============================================================================
-- PART A - schema and seed checks (read-only)
-- =============================================================================
INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A01 schema version installed',
       IF(COUNT(*) = 1, 'PASS', 'FAIL'), COALESCE(MAX(schema_version), 'schema_info row missing')
  FROM schema_info WHERE id = 1 AND schema_version = '2026.10.14-3';

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A02 object counts (35 tables, 8 views, 34 triggers)',
       IF(t.n = 35 AND v.n = 8 AND g.n = 34, 'PASS', 'FAIL'),
       CONCAT('tables=', t.n, ' views=', v.n, ' triggers=', g.n)
  FROM (SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE') t,
       (SELECT COUNT(*) n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()) v,
       (SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()) g;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A03 all tables InnoDB + utf8mb4',
       IF(SUM(ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%') = 0, 'PASS', 'FAIL'),
       CONCAT('non-conforming tables: ', SUM(ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%'))
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A04 18 topics, positions 1..18, confirmed order',
       IF(COUNT(*) = 18 AND MIN(position) = 1 AND MAX(position) = 18
          AND GROUP_CONCAT(title ORDER BY position SEPARATOR '|') =
          'Python introduction|Variables and assignment|Basic data types|Input and type conversion|Arithmetic operators|Comparison and logical operators|Conditions|Loops|Loop control|Strings|Lists|Tuples and sets|Dictionaries|Functions|Modules and imports|Exceptions|File handling|Basic object-oriented programming',
          'PASS', 'FAIL'),
       CONCAT('topics=', COUNT(*))
  FROM topics;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A05 six skill groups with documented membership',
       IF(GROUP_CONCAT(g.code ORDER BY t.position) = 'G1,G1,G1,G2,G2,G2,G3,G3,G3,G4,G4,G4,G4,G5,G5,G5,G6,G6'
          AND (SELECT COUNT(*) FROM skill_groups) = 6, 'PASS', 'FAIL'),
       GROUP_CONCAT(g.code ORDER BY t.position)
  FROM topics t JOIN skill_groups g ON g.id = t.skill_group_id;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A06 ranks E,D,C,B,A,S and E titled Rookie',
       IF(GROUP_CONCAT(code ORDER BY sort_order) = 'E,D,C,B,A,S'
          AND (SELECT title FROM ranks WHERE code = 'E') = 'Rookie', 'PASS', 'FAIL'),
       GROUP_CONCAT(CONCAT(display_name, IFNULL(CONCAT(' (', title, ')'), '')) ORDER BY sort_order)
  FROM ranks;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A07 every question: >=2 choices, exactly one correct',
       IF(SUM(x.n_choices < 2 OR x.n_correct <> 1) = 0, 'PASS', 'FAIL'),
       CONCAT('questions=', COUNT(*), ' invalid=', SUM(x.n_choices < 2 OR x.n_correct <> 1))
  FROM (SELECT q.id, COUNT(c.id) n_choices, COALESCE(SUM(c.is_correct), 0) n_correct
          FROM questions q LEFT JOIN question_choices c ON c.question_id = q.id GROUP BY q.id) x;

-- Content profile: 'standard' unless clearly labelled test-fixture content exists
-- (lessons titled "[FIXTURE]...", badges with slug "fx-..."). Decides Part S only.
SET @fixture_lessons = (SELECT COUNT(*) FROM lessons WHERE title LIKE '[FIXTURE]%');
SET @fixture_badges = (SELECT COUNT(*) FROM badges WHERE slug LIKE 'fx-%');
SET @standard_content = (@fixture_lessons = 0 AND @fixture_badges = 0);
INSERT INTO verify_results (section, check_name, outcome, detail)
VALUES ('A', 'A00 content profile (decides whether Part S seed checks apply)', 'INFO',
        CONCAT(IF(@standard_content, 'standard seed', 'NON-STANDARD (test fixture)'),
               ': fixture lessons=', @fixture_lessons, ' fixture badges=', @fixture_badges));

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'S', 'S01 [seed] quiz pool >= 10 active for topics 1 and 2',
       IF(NOT @standard_content, 'INFO', IF(SUM(topic_id = 1) >= 10 AND SUM(topic_id = 2) >= 10, 'PASS', 'FAIL')),
       CONCAT(IF(@standard_content, '', 'not applicable (non-standard content); observed '),
              'topic1=', SUM(topic_id = 1), ' topic2=', SUM(topic_id = 2))
  FROM questions WHERE for_quiz = 1 AND status = 'active' AND topic_id IN (1, 2);

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A09 assessment pool covers current group quotas (sum 10)',
       IF(SUM(x.pool < x.quota) = 0 AND SUM(x.quota) = 10, 'PASS', 'FAIL'),
       GROUP_CONCAT(CONCAT(x.code, ' quota ', x.quota, '/pool ', x.pool) ORDER BY x.code SEPARATOR '; ')
  FROM (SELECT g.code, q.question_count AS quota,
               (SELECT COUNT(*) FROM questions qq JOIN topics tt ON tt.id = qq.topic_id
                 WHERE tt.skill_group_id = g.id AND qq.for_assessment = 1 AND qq.status = 'active') AS pool
          FROM assessment_group_quotas q
          JOIN assessment_policies p ON p.id = q.policy_id AND p.is_current = 1
          JOIN skill_groups g ON g.id = q.skill_group_id) x;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A10 placement bands cover 0..10 exactly once',
       IF(SUM(x.hits <> 1) = 0 AND COUNT(*) = 11, 'PASS', 'FAIL'),
       GROUP_CONCAT(CONCAT(x.score, '=', x.rank_codes) ORDER BY x.score)
  FROM (SELECT s.seq AS score, COUNT(b.rank_id) AS hits, GROUP_CONCAT(r.code) AS rank_codes
          FROM seq_0_to_10 s
          LEFT JOIN placement_rank_bands b ON s.seq BETWEEN b.min_correct AND b.max_correct
               AND b.policy_id = (SELECT id FROM assessment_policies WHERE is_current = 1)
          LEFT JOIN ranks r ON r.id = b.rank_id
         GROUP BY s.seq) x;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A11 grade bands cover 0..5 for current quiz policy',
       IF(COUNT(*) = p.question_count + 1 AND MIN(b.correct_count) = 0 AND MAX(b.correct_count) = p.question_count, 'PASS', 'FAIL'),
       GROUP_CONCAT(CONCAT(b.correct_count, '/', p.question_count, '=', b.grade_letter, ' ', b.percent, '%') ORDER BY b.correct_count DESC SEPARATOR ', ')
  FROM quiz_grading_policies p JOIN quiz_grade_bands b ON b.policy_id = p.id
 WHERE p.is_current = 1
 GROUP BY p.id, p.question_count;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A12 exactly one current version per policy',
       IF((SELECT COUNT(*) FROM quiz_grading_policies WHERE is_current = 1) = 1
          AND (SELECT COUNT(*) FROM assessment_policies WHERE is_current = 1) = 1
          AND (SELECT COUNT(*) FROM reward_policies WHERE reward_type = 'topic_first_completion' AND is_current = 1) = 1
          AND (SELECT COUNT(*) FROM app_policies WHERE is_current = 1) = 1
          AND (SELECT COUNT(*) FROM rank_progression_policies WHERE is_current = 1) = 1, 'PASS', 'FAIL'),
       'quiz_grading, assessment, reward(topic_first_completion), rank_progression, app';

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A13 every current policy version is approved',
       IF((SELECT COUNT(*) FROM quiz_grading_policies WHERE is_current = 1 AND status = 'approved')
        + (SELECT COUNT(*) FROM assessment_policies WHERE is_current = 1 AND status = 'approved')
        + (SELECT COUNT(*) FROM reward_policies WHERE is_current = 1 AND status = 'approved')
        + (SELECT COUNT(*) FROM rank_progression_policies WHERE is_current = 1 AND status = 'approved')
        + (SELECT COUNT(*) FROM app_policies WHERE is_current = 1 AND status = 'approved') = 5, 'PASS', 'FAIL'),
       CONCAT('provisional=',
              (SELECT COUNT(*) FROM quiz_grading_policies WHERE status = 'provisional') +
              (SELECT COUNT(*) FROM assessment_policies WHERE status = 'provisional') +
              (SELECT COUNT(*) FROM reward_policies WHERE status = 'provisional') +
              (SELECT COUNT(*) FROM app_policies WHERE status = 'provisional'),
              ' approved=',
              (SELECT COUNT(*) FROM quiz_grading_policies WHERE status = 'approved') +
              (SELECT COUNT(*) FROM assessment_policies WHERE status = 'approved') +
              (SELECT COUNT(*) FROM reward_policies WHERE status = 'approved') +
              (SELECT COUNT(*) FROM app_policies WHERE status = 'approved') +
              (SELECT COUNT(*) FROM rank_progression_policies WHERE status = 'approved'),
              ' (current versions must all be approved)');

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A14 every available topic is ready (v_topic_readiness)',
       IF(SUM(is_ready = 0) = 0, 'PASS', 'FAIL'),
       CONCAT('available=', COUNT(*), ' not ready=', SUM(is_ready = 0), ' ready=[', COALESCE(GROUP_CONCAT(IF(is_ready, topic_id, NULL)), ''), ']')
  FROM v_topic_readiness WHERE content_status = 'available';

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A14b rank progression bands cover 0..18 topics exactly once',
       IF(SUM(x.hits <> 1) = 0 AND COUNT(*) = 19, 'PASS', 'FAIL'),
       GROUP_CONCAT(CONCAT(x.n, '=', x.rank_codes) ORDER BY x.n)
  FROM (SELECT s.seq AS n, COUNT(b.rank_id) AS hits, GROUP_CONCAT(r.code) AS rank_codes
          FROM seq_0_to_18 s
          LEFT JOIN rank_progression_bands b ON s.seq BETWEEN b.min_topics_passed AND b.max_topics_passed
               AND b.policy_id = (SELECT id FROM rank_progression_policies WHERE is_current = 1)
          LEFT JOIN ranks r ON r.id = b.rank_id
         GROUP BY s.seq) x;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'S', 'S02 [seed] sample badges (ids 1-4) are not purchasable; nothing on sale',
       IF(NOT @standard_content, 'INFO',
          IF(COUNT(*) = 4 AND SUM(status = 'on_sale') = 0 AND (SELECT COUNT(*) FROM badges WHERE status = 'on_sale') = 0, 'PASS', 'FAIL')),
       CONCAT(IF(@standard_content, '', 'not applicable (non-standard content); observed '),
              'sample badges=', COUNT(*), ' on_sale=', SUM(status = 'on_sale'), ' draft=', SUM(status = 'draft'),
              ' provisional price=', SUM(price_status = 'provisional'),
              ' | all badges on sale=', (SELECT COUNT(*) FROM badges WHERE status = 'on_sale'))
  FROM badges WHERE id BETWEEN 1 AND 4;

-- every on-sale badge has an approved price (structural rule, any content)
INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A14d every on-sale badge has an approved price; owned/showcase consistent',
       IF(SUM(status = 'on_sale' AND price_status <> 'approved') = 0
          AND (SELECT COUNT(*) FROM badge_showcase s LEFT JOIN badge_ownership o ON o.user_id = s.user_id AND o.badge_id = s.badge_id WHERE o.id IS NULL) = 0
          AND (SELECT COUNT(*) FROM (SELECT user_id FROM badge_showcase GROUP BY user_id
                                      HAVING COUNT(*) > (SELECT showcase_limit FROM app_policies WHERE is_current = 1)
                                          OR MAX(position) <> COUNT(*)) x) = 0, 'PASS', 'FAIL'),
       CONCAT('on sale=', SUM(status = 'on_sale'), ' on sale unapproved=', SUM(status = 'on_sale' AND price_status <> 'approved'),
              ' owned=', (SELECT COUNT(*) FROM badge_ownership), ' showcased=', (SELECT COUNT(*) FROM badge_showcase))
  FROM badges;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A15 content readiness',
       'INFO',
       CONCAT('available topics=', SUM(status = 'available'), ' draft topics=', SUM(status = 'draft'),
              ' | badges on sale=', (SELECT COUNT(*) FROM badges WHERE status = 'on_sale'),
              ' provisional badge prices=', (SELECT COUNT(*) FROM badges WHERE price_status = 'provisional'))
  FROM topics;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A16 wallet cache = ledger sum = last running balance',
       IF(SUM(cached_balance <> ledger_sum OR cached_balance <> last_balance_after) = 0 OR COUNT(*) = 0, 'PASS', 'FAIL'),
       CONCAT('students=', COUNT(*), ' mismatches=', COALESCE(SUM(cached_balance <> ledger_sum OR cached_balance <> last_balance_after), 0))
  FROM v_wallet_reconciliation;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A17 every reward claim and badge purchase has one ledger entry',
       IF(x.orphans = 0, 'PASS', 'FAIL'), CONCAT('without ledger entry=', x.orphans)
  FROM (SELECT (SELECT COUNT(*) FROM reward_claims rc WHERE NOT EXISTS (SELECT 1 FROM coin_ledger l WHERE l.reward_claim_id = rc.id))
             + (SELECT COUNT(*) FROM badge_ownership bo WHERE NOT EXISTS (SELECT 1 FROM coin_ledger l WHERE l.badge_ownership_id = bo.id)) AS orphans) x;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A18 submitted attempts <-> result rows',
       IF(x.bad = 0, 'PASS', 'FAIL'), CONCAT('inconsistent attempts=', x.bad)
  FROM (SELECT COUNT(*) AS bad FROM attempts a
         WHERE (a.status = 'submitted') <>
               (EXISTS (SELECT 1 FROM quiz_results r WHERE r.attempt_id = a.id)
                OR EXISTS (SELECT 1 FROM assessment_results r WHERE r.attempt_id = a.id))) x;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A18b login_throttle retention: no row older than 24 h, no raw values',
       IF(SUM(created_at < UTC_TIMESTAMP(3) - INTERVAL 24 HOUR) = 0 OR COUNT(*) = 0, 'PASS', 'FAIL'),
       CONCAT('rows=', COUNT(*), ' older than 24 h=', COALESCE(SUM(created_at < UTC_TIMESTAMP(3) - INTERVAL 24 HOUR), 0))
  FROM login_throttle;

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'A', 'A19 existing data volume',
       'INFO',
       CONCAT('users=', (SELECT COUNT(*) FROM users), ' attempts=', (SELECT COUNT(*) FROM attempts),
              ' ledger entries=', (SELECT COUNT(*) FROM coin_ledger), ' sessions=', (SELECT COUNT(*) FROM user_sessions));

-- =============================================================================
-- PART B - integrity tests inside one transaction (rolled back)
-- =============================================================================
START TRANSACTION;

-- ---- fixtures: two students and one administrator (no real passwords)
INSERT INTO users (role, display_name, email, password_hash)
VALUES ('student', 'Verify Student One', 'verify.s1@example.invalid', 'not-a-password-hash');
SET @s1 = LAST_INSERT_ID();
INSERT INTO student_profiles (user_id) VALUES (@s1);
INSERT INTO users (role, display_name, email, password_hash)
VALUES ('student', 'Verify Student Two', 'verify.s2@example.invalid', 'not-a-password-hash');
SET @s2 = LAST_INSERT_ID();
INSERT INTO student_profiles (user_id) VALUES (@s2);
INSERT INTO users (role, display_name, email, password_hash)
VALUES ('admin', 'Verify Admin', 'verify.admin@example.invalid', 'not-a-password-hash');
SET @adm = LAST_INSERT_ID();
UPDATE student_profiles SET experience_level_id = 4, experience_chosen_at = UTC_TIMESTAMP(3),
       onboarding_state = 'experience_chosen' WHERE user_id = @s1;

DELIMITER $$

-- ---- B01-B03 foreign keys reject missing references
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO questions (topic_id, stem, status) VALUES (250, 'orphan question', 'draft');
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B01 FK rejects question for a missing topic', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via)
  VALUES (4294967000, 'topic_quiz', 1, 1, 5, 'direct');
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B02 FK rejects attempt for a missing student', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO student_profiles (user_id) VALUES (@adm);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B03 role FK rejects a student profile for an admin', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- B04 placement assessment builder: @b_user, @b_correct (first N positions correct)
--      s1: 4/10 (positions 1-4 = G1,G1,G2,G2) -> D, recommended topic 7 (first
--      topic of G3, earliest group with a miss). Approved rule: if topic 7 is not
--      ready, the nearest earlier ready topic is offered alongside it (2 with the
--      standard seed); if it is ready, nothing is offered. Checked for any content.
SET @b_user = @s1, @b_correct = 4$$
BEGIN NOT ATOMIC
  DECLARE v_attempt INT UNSIGNED;
  DECLARE v_policy SMALLINT UNSIGNED;
  DECLARE v_correct INT;
  DECLARE v_rank TINYINT UNSIGNED;
  DECLARE v_missed_group TINYINT UNSIGNED;
  DECLARE v_topic TINYINT UNSIGNED;
  DECLARE v_offered TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    INSERT INTO verify_results (section, check_name, outcome, detail)
    VALUES ('B', 'B04 assessment builder', 'FAIL', CONCAT('builder error: ', LEFT(COALESCE(v_msg, '(no message)'), 300)));
  END;
  SELECT id INTO v_policy FROM assessment_policies WHERE is_current = 1;
  INSERT INTO attempts (user_id, kind, attempt_number, question_count) VALUES (@b_user, 'assessment', 1, 10);
  SET v_attempt = LAST_INSERT_ID();
  INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
  SELECT v_attempt, ROW_NUMBER() OVER (ORDER BY g.sort_order, x.id), x.id, x.revision, x.topic_id, x.skill_group_id, x.stem, x.code_snippet, x.explanation
    FROM (SELECT q.*, t.skill_group_id,
                 ROW_NUMBER() OVER (PARTITION BY t.skill_group_id ORDER BY q.id) AS rn
            FROM questions q JOIN topics t ON t.id = q.topic_id
           WHERE q.for_assessment = 1 AND q.status = 'active') x
    JOIN assessment_group_quotas gq ON gq.policy_id = v_policy AND gq.skill_group_id = x.skill_group_id
    JOIN skill_groups g ON g.id = x.skill_group_id
   WHERE x.rn <= gq.question_count;
  INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
  SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
    FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id
   WHERE aq.attempt_id = v_attempt;
  -- answer: positions 1-4 correct, the rest the first wrong choice
  UPDATE attempt_questions aq
     SET aq.selected_choice_id = (SELECT c.id FROM attempt_question_choices c
                                   WHERE c.attempt_question_id = aq.id AND c.is_correct = IF(aq.position <= @b_correct, 1, 0)
                                   ORDER BY c.label LIMIT 1),
         aq.answered_at = UTC_TIMESTAMP(3)
   WHERE aq.attempt_id = v_attempt;
  -- grade (PHP would do this from the snapshots inside the submit transaction)
  UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
     SET aq.is_correct = c.is_correct WHERE aq.attempt_id = v_attempt;
  SELECT SUM(is_correct) INTO v_correct FROM attempt_questions WHERE attempt_id = v_attempt;
  SELECT rank_id INTO v_rank FROM placement_rank_bands
   WHERE policy_id = v_policy AND v_correct BETWEEN min_correct AND max_correct;
  SELECT g.id INTO v_missed_group FROM attempt_questions aq JOIN skill_groups g ON g.id = aq.skill_group_id
   WHERE aq.attempt_id = v_attempt AND aq.is_correct = 0 ORDER BY g.sort_order LIMIT 1;
  IF v_missed_group IS NULL THEN
    SELECT all_correct_topic_id INTO v_topic FROM assessment_policies WHERE id = v_policy;
  ELSE
    SELECT id INTO v_topic FROM topics WHERE skill_group_id = v_missed_group ORDER BY position LIMIT 1;
  END IF;
  -- approved fallback: if the recommended topic is not ready, also record the
  -- nearest earlier ready topic; the recommendation itself is never replaced
  IF NOT (SELECT is_ready FROM v_topic_readiness WHERE topic_id = v_topic) THEN
    SELECT r.topic_id INTO v_offered FROM v_topic_readiness r
     WHERE r.is_ready = 1 AND r.position < (SELECT position FROM topics WHERE id = v_topic)
     ORDER BY r.position DESC LIMIT 1;
  END IF;
  INSERT INTO assessment_results (attempt_id, assessment_policy_id, correct_count, placement_rank_id, recommended_topic_id, offered_topic_id)
  VALUES (v_attempt, v_policy, v_correct, v_rank, v_topic, v_offered);
  UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = v_attempt;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id)
  VALUES (@b_user, NULL, v_rank, 'placement', v_attempt);
  UPDATE student_profiles
     SET placement_attempt_id = v_attempt, placement_rank_id = v_rank, current_rank_id = v_rank,
         onboarding_state = 'assessment_submitted', updated_at = UTC_TIMESTAMP(3)
   WHERE user_id = @b_user;
  INSERT INTO student_topic_progress (user_id, topic_id, unlocked_at, unlock_source)
  SELECT @b_user, t.id, IF(t.position <= (SELECT position FROM topics WHERE id = v_topic), UTC_TIMESTAMP(3), NULL),
         IF(t.position <= (SELECT position FROM topics WHERE id = v_topic), 'placement', NULL)
    FROM topics t;
  SET @b_attempt = v_attempt;
END$$
SET @assessment_attempt = @b_attempt$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B04 assessment 4/10 -> D; recommended 7 kept; offered = nearest earlier ready only if 7 not ready',
       IF(ar.correct_count = 4 AND r.code = 'D' AND sp.current_rank_id = sp.placement_rank_id
          AND ar.recommended_topic_id = 7
          AND ar.offered_topic_id <=> IF((SELECT is_ready FROM v_topic_readiness WHERE topic_id = 7), NULL,
                                         (SELECT topic_id FROM v_topic_readiness WHERE is_ready = 1 AND position < 7
                                           ORDER BY position DESC LIMIT 1))
          AND (SELECT COUNT(*) FROM rank_history WHERE user_id = @s1) = 1, 'PASS', 'FAIL'),
       CONCAT('correct=', ar.correct_count, ' placement=', r.display_name, ' recommended topic=', ar.recommended_topic_id,
              ' (ready=', (SELECT is_ready FROM v_topic_readiness WHERE topic_id = 7), ') offered topic=', COALESCE(ar.offered_topic_id, 'NULL'))
  FROM assessment_results ar
  JOIN ranks r ON r.id = ar.placement_rank_id
  JOIN student_profiles sp ON sp.user_id = @s1
 WHERE ar.attempt_id = @assessment_attempt$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B04b derived evidence: 18 topics, 10 asked, groups 2/2/2/2/1/1',
       IF(COUNT(*) = 18 AND SUM(asked_count) = 10 AND SUM(correct_count) = 4
          AND (SELECT COUNT(*) FROM v_assessment_group_results WHERE attempt_id = @assessment_attempt) = 6
          AND (SELECT GROUP_CONCAT(asked_count ORDER BY skill_group_id) FROM v_assessment_group_results WHERE attempt_id = @assessment_attempt) = '2,2,2,2,1,1',
          'PASS', 'FAIL'),
       CONCAT('topics=', COUNT(*), ' asked=', SUM(asked_count), ' correct=', SUM(correct_count),
              ' not_assessed=', SUM(evidence_label = 'not_assessed'), ' group asked=',
              (SELECT GROUP_CONCAT(asked_count ORDER BY skill_group_id) FROM v_assessment_group_results WHERE attempt_id = @assessment_attempt))
  FROM v_assessment_topic_evidence WHERE attempt_id = @assessment_attempt$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE student_profiles SET placement_rank_id = 6 WHERE user_id = @s1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B05 initial placement rank cannot be changed', IF(v_errno IN (1644, 1452), 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE student_profiles SET current_rank_id = 4 WHERE user_id = @s1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B06 current rank cannot change without a rank_history entry', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- quiz attempt builder: @b_topic, @b_num, @b_offset (pool offset), @b_correct -> @b_attempt
--      B07: s1 topic 1 attempt 1, 2/5 correct
SET @b_user = @s1, @b_topic = 1, @b_num = 1, @b_offset = 0, @b_correct = 2$$
BEGIN NOT ATOMIC
  DECLARE v_attempt INT UNSIGNED;
  DECLARE v_off INT DEFAULT @b_offset;
  DECLARE v_correct INT;
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    SET @b_attempt = NULL;
    INSERT INTO verify_results (section, check_name, outcome, detail)
    VALUES ('B', CONCAT('quiz builder (topic ', @b_topic, ' attempt ', @b_num, ')'), 'FAIL', CONCAT('builder error: ', LEFT(COALESCE(v_msg, '(no message)'), 300)));
  END;
  INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via)
  VALUES (@b_user, 'topic_quiz', @b_topic, @b_num, 5, 'direct');
  SET v_attempt = LAST_INSERT_ID();
  INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
  SELECT v_attempt, ROW_NUMBER() OVER (ORDER BY x.id), x.id, x.revision, x.topic_id, x.skill_group_id, x.stem, x.code_snippet, x.explanation
    FROM (SELECT q.*, t.skill_group_id FROM questions q JOIN topics t ON t.id = q.topic_id
           WHERE q.topic_id = @b_topic AND q.for_quiz = 1 AND q.status = 'active'
           ORDER BY q.id LIMIT 5 OFFSET v_off) x;
  INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
  SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
    FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id
   WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq
     SET aq.selected_choice_id = (SELECT c.id FROM attempt_question_choices c
                                   WHERE c.attempt_question_id = aq.id AND c.is_correct = IF(aq.position <= @b_correct, 1, 0)
                                   ORDER BY c.label LIMIT 1),
         aq.answered_at = UTC_TIMESTAMP(3)
   WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
     SET aq.is_correct = c.is_correct WHERE aq.attempt_id = v_attempt;
  SELECT SUM(is_correct) INTO v_correct FROM attempt_questions WHERE attempt_id = v_attempt;
  INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed)
  SELECT v_attempt, b.policy_id, b.correct_count, b.grade_letter, b.percent, b.correct_count >= p.pass_mark_correct
    FROM quiz_grading_policies p JOIN quiz_grade_bands b ON b.policy_id = p.id
   WHERE p.is_current = 1 AND b.correct_count = v_correct;
  UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = v_attempt;
  SET @b_attempt = v_attempt;
END$$
SET @a1 = @b_attempt$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B07 quiz 2/5 graded D 40% not passed',
       IF(r.correct_count = 2 AND r.grade_letter = 'D' AND r.percent = 40 AND r.passed = 0, 'PASS', 'FAIL'),
       CONCAT('correct=', r.correct_count, ' grade=', r.grade_letter, ' ', r.percent, '% passed=', r.passed)
  FROM quiz_results r WHERE r.attempt_id = @a1$$

-- ---- B08 first-completion reward + ledger credit (as the PHP submit transaction would)
BEGIN NOT ATOMIC
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    INSERT INTO verify_results (section, check_name, outcome, detail) VALUES ('B', 'B08 reward claim + ledger credit', 'FAIL', LEFT(COALESCE(v_msg, '(no message)'), 300));
  END;
  SELECT coin_balance INTO @bal FROM student_profiles WHERE user_id = @s1 FOR UPDATE;
  INSERT INTO reward_claims (user_id, topic_id, reward_type, attempt_id, reward_policy_id, coin_amount)
  SELECT @s1, 1, 'topic_first_completion', @a1, id, coin_amount FROM reward_policies
   WHERE reward_type = 'topic_first_completion' AND is_current = 1;
  SET @claim1 = LAST_INSERT_ID();
  INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, reward_claim_id)
  SELECT @s1, 'credit', rc.coin_amount, @bal + rc.coin_amount, 'topic_reward', rc.id FROM reward_claims rc WHERE rc.id = @claim1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B08 reward credits 10 coins; derived topic status = completed, best 2',
         IF(sp.coin_balance = 10 AND st.display_status = 'completed' AND st.completed_attempts = 1
            AND st.best_correct_count = 2 AND st.first_passed_at IS NULL, 'PASS', 'FAIL'),
         CONCAT('balance=', sp.coin_balance, ' status=', st.display_status, ' completed=', st.completed_attempts,
                ' best=', st.best_correct_count)
    FROM student_profiles sp JOIN v_student_topic_status st ON st.user_id = sp.user_id AND st.topic_id = 1
   WHERE sp.user_id = @s1;
END$$

-- ---- B09 duplicate first-completion reward is rejected (retry attempt 2, 4/5 = B, passed)
SET @b_user = @s1, @b_topic = 1, @b_num = 2, @b_offset = 5, @b_correct = 4$$
BEGIN NOT ATOMIC
  DECLARE v_attempt INT UNSIGNED;
  DECLARE v_off INT DEFAULT @b_offset;
  DECLARE v_correct INT;
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    SET @b_attempt = NULL;
    INSERT INTO verify_results (section, check_name, outcome, detail)
    VALUES ('B', CONCAT('quiz builder (topic ', @b_topic, ' attempt ', @b_num, ')'), 'FAIL', CONCAT('builder error: ', LEFT(COALESCE(v_msg, '(no message)'), 300)));
  END;
  INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via)
  VALUES (@b_user, 'topic_quiz', @b_topic, @b_num, 5, 'lesson_first');
  SET v_attempt = LAST_INSERT_ID();
  INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
  SELECT v_attempt, ROW_NUMBER() OVER (ORDER BY x.id), x.id, x.revision, x.topic_id, x.skill_group_id, x.stem, x.code_snippet, x.explanation
    FROM (SELECT q.*, t.skill_group_id FROM questions q JOIN topics t ON t.id = q.topic_id
           WHERE q.topic_id = @b_topic AND q.for_quiz = 1 AND q.status = 'active'
           ORDER BY q.id LIMIT 5 OFFSET v_off) x;
  INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
  SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
    FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id
   WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq
     SET aq.selected_choice_id = (SELECT c.id FROM attempt_question_choices c
                                   WHERE c.attempt_question_id = aq.id AND c.is_correct = IF(aq.position <= @b_correct, 1, 0)
                                   ORDER BY c.label LIMIT 1),
         aq.answered_at = UTC_TIMESTAMP(3)
   WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
     SET aq.is_correct = c.is_correct WHERE aq.attempt_id = v_attempt;
  SELECT SUM(is_correct) INTO v_correct FROM attempt_questions WHERE attempt_id = v_attempt;
  INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed)
  SELECT v_attempt, b.policy_id, b.correct_count, b.grade_letter, b.percent, b.correct_count >= p.pass_mark_correct
    FROM quiz_grading_policies p JOIN quiz_grade_bands b ON b.policy_id = p.id
   WHERE p.is_current = 1 AND b.correct_count = v_correct;
  UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = v_attempt;
  SET @b_attempt = v_attempt;
END$$
SET @a2 = @b_attempt$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO reward_claims (user_id, topic_id, reward_type, attempt_id, reward_policy_id, coin_amount)
  VALUES (@s1, 1, 'topic_first_completion', @a2, 1, 10);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B09 duplicate topic reward (same student/topic/type) rejected', IF(v_errno = 1062, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B09b retry 4/5 graded B 80% passed; no new coins',
       IF(r.grade_letter = 'B' AND r.passed = 1
          AND (SELECT coin_balance FROM student_profiles WHERE user_id = @s1) = 10
          AND (SELECT COUNT(*) FROM coin_ledger WHERE user_id = @s1) = 1, 'PASS', 'FAIL'),
       CONCAT('grade=', r.grade_letter, ' passed=', r.passed, ' balance=', (SELECT coin_balance FROM student_profiles WHERE user_id = @s1),
              ' attempt overlap with attempt 1=',
              (SELECT COUNT(*) FROM attempt_questions x JOIN attempt_questions y ON y.question_id = x.question_id
                WHERE x.attempt_id = @a1 AND y.attempt_id = @a2))
  FROM quiz_results r WHERE r.attempt_id = @a2$$

-- ---- B10 finalization happens once
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE attempts SET submitted_at = UTC_TIMESTAMP(3) WHERE id = @a1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B10 submitted attempt cannot be re-finalized/changed', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed)
  VALUES (@a1, 1, 5, 'A', 100, 1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B10b second result row for the same attempt rejected', IF(v_errno IN (1644, 1062), 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE attempt_questions SET selected_choice_id = NULL, answered_at = NULL, is_correct = NULL
   WHERE attempt_id = @a1 AND position = 1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B10c answers locked after submission', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- B11-B13 an open attempt (topic 2) with only 4 of 5 answers
BEGIN NOT ATOMIC
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    INSERT INTO verify_results (section, check_name, outcome, detail) VALUES ('B', 'B11 open-attempt fixture', 'FAIL', LEFT(COALESCE(v_msg, '(no message)'), 300));
  END;
  INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via)
  VALUES (@s1, 'topic_quiz', 2, 1, 5, 'direct');
  SET @a3 = LAST_INSERT_ID();
  INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
  SELECT @a3, ROW_NUMBER() OVER (ORDER BY x.id), x.id, x.revision, x.topic_id, 1, x.stem, x.code_snippet, x.explanation
    FROM (SELECT * FROM questions WHERE topic_id = 2 AND for_quiz = 1 AND status = 'active' ORDER BY id LIMIT 5) x;
  INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
  SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
    FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id WHERE aq.attempt_id = @a3;
  UPDATE attempt_questions aq
     SET aq.selected_choice_id = (SELECT MIN(c.id) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id),
         aq.answered_at = UTC_TIMESTAMP(3)
   WHERE aq.attempt_id = @a3 AND aq.position <= 4;
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = @a3;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B11 cannot finalize with 4 of 5 answers', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE v_foreign_choice INT UNSIGNED;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  -- choose, for position 5, a choice that belongs to position 1.
  -- (Read into a variable first: on MariaDB 10.4 a handler does not catch an FK
  --  error raised by an UPDATE whose subquery reads the same table.)
  SELECT MIN(c.id) INTO v_foreign_choice
    FROM attempt_question_choices c JOIN attempt_questions aq1 ON aq1.id = c.attempt_question_id
   WHERE aq1.attempt_id = @a3 AND aq1.position = 1;
  UPDATE attempt_questions SET selected_choice_id = v_foreign_choice, answered_at = UTC_TIMESTAMP(3)
   WHERE attempt_id = @a3 AND position = 5;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B12 answer must be a choice of its own assigned question', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via)
  VALUES (@s1, 'topic_quiz', 2, 2, 5, 'direct');
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B13 only one in-progress attempt per student per topic', IF(v_errno = 1062, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed)
  VALUES (@a3, 1, 4, 'A', 80, 1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B13b result must match a grade band of its policy', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- B14-B15 historical content survives a question-bank edit
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE attempt_questions SET stem = 'tampered' WHERE attempt_id = @a3 AND position = 5;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B14 snapshot text is immutable even while open', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', aq.position, aq.stem, COALESCE(aq.code_snippet, ''), c.label, c.choice_text, c.is_correct)
                        ORDER BY aq.position, c.label SEPARATOR '#'))
  INTO @snap_before
  FROM attempt_questions aq JOIN attempt_question_choices c ON c.attempt_question_id = aq.id
 WHERE aq.attempt_id = @a1$$

-- edit the question bank: change every stem/choice used by attempt 1 and move question 101's correct answer
UPDATE questions SET stem = CONCAT('[EDITED] ', stem), revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
 WHERE id IN (SELECT question_id FROM attempt_questions WHERE attempt_id = @a1)$$
UPDATE question_choices SET choice_text = CONCAT('[EDITED] ', choice_text)
 WHERE question_id IN (SELECT question_id FROM attempt_questions WHERE attempt_id = @a1)$$
UPDATE question_choices SET is_correct = 0 WHERE question_id = 101$$
UPDATE question_choices SET is_correct = 1 WHERE id = 1012$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B15 attempt snapshot + grade unchanged after question-bank edit',
       IF(@snap_before = x.snap_after AND r.correct_count = x.regraded
          AND (SELECT stem FROM questions WHERE id = 101) LIKE '[EDITED]%', 'PASS', 'FAIL'),
       CONCAT('snapshot hash same=', @snap_before = x.snap_after, ' stored correct=', r.correct_count,
              ' regraded from snapshot=', x.regraded,
              ' bank revision of q101=', (SELECT revision FROM questions WHERE id = 101))
  FROM (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', aq.position, aq.stem, COALESCE(aq.code_snippet, ''), c.label, c.choice_text, c.is_correct)
                                ORDER BY aq.position, c.label SEPARATOR '#')) AS snap_after,
               (SELECT SUM(c2.is_correct) FROM attempt_questions a2
                  JOIN attempt_question_choices c2 ON c2.id = a2.selected_choice_id
                 WHERE a2.attempt_id = @a1) AS regraded
          FROM attempt_questions aq JOIN attempt_question_choices c ON c.attempt_question_id = aq.id
         WHERE aq.attempt_id = @a1) x
  JOIN quiz_results r ON r.attempt_id = @a1$$

-- ---- B16-B22 coins, badges and showcase
-- admin correction credit of 40 (structure only; admin coin powers are NOT approved)
BEGIN NOT ATOMIC
  SELECT coin_balance INTO @bal FROM student_profiles WHERE user_id = @s1 FOR UPDATE;
  INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, adjusted_by_admin_id, note)
  VALUES (@s1, 'credit', 40, @bal + 40, 'adjustment', @adm, 'verify.sql test credit (rolled back)');
END$$

-- purchase badge 1 at its current catalogue price (transaction steps as PHP would run them)
BEGIN NOT ATOMIC
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    INSERT INTO verify_results (section, check_name, outcome, detail) VALUES ('B', 'B16 badge purchase', 'FAIL', LEFT(COALESCE(v_msg, '(no message)'), 300));
  END;
  SELECT coin_balance INTO @bal FROM student_profiles WHERE user_id = @s1 FOR UPDATE;
  SELECT price_coins INTO @price1 FROM badges WHERE id = 1 FOR UPDATE;
  INSERT INTO badge_ownership (user_id, badge_id, price_paid) VALUES (@s1, 1, @price1);
  SET @own1 = LAST_INSERT_ID();
  INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, badge_ownership_id)
  VALUES (@s1, 'debit', @price1, @bal - @price1, 'badge_purchase', @own1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B16 badge purchase debits the catalogue price', IF(coin_balance = 50 - @price1, 'PASS', 'FAIL'),
         CONCAT('price=', @price1, ' balance=', coin_balance)
    FROM student_profiles WHERE user_id = @s1;
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO badge_ownership (user_id, badge_id, price_paid) VALUES (@s1, 1, @price1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B17 duplicate badge ownership rejected', IF(v_errno = 1062, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- second ownership row (badge 4) to test ledger amount matching and overspending
INSERT INTO badge_ownership (user_id, badge_id, price_paid) SELECT @s1, 4, price_coins FROM badges WHERE id = 4$$
SET @own4 = LAST_INSERT_ID()$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  SELECT coin_balance INTO @bal FROM student_profiles WHERE user_id = @s1;
  INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, badge_ownership_id)
  VALUES (@s1, 'debit', 1, @bal - 1, 'badge_purchase', @own4);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B18 ledger amount must equal the price paid', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  SELECT coin_balance INTO @bal FROM student_profiles WHERE user_id = @s1;
  SELECT price_paid INTO @price4 FROM badge_ownership WHERE id = @own4;
  -- the balance (30) is below the price (100): balance_after would be negative
  INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, badge_ownership_id)
  VALUES (@s1, 'debit', @price4, 0, 'badge_purchase', @own4);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B19 ledger refuses a debit that would make the balance negative', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE student_profiles SET coin_balance = 9999 WHERE user_id = @s1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B20 cached balance cannot be edited without a ledger entry', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_e1 INT DEFAULT 0; DECLARE v_e2 INT DEFAULT 0; DECLARE v_errno INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO;
  UPDATE coin_ledger SET amount = amount + 1 WHERE user_id = @s1;
  SET v_e1 = v_errno, v_errno = 0;
  DELETE FROM coin_ledger WHERE user_id = @s1;
  SET v_e2 = v_errno;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B21 coin ledger is append-only (update and delete refused)', IF(v_e1 = 1644 AND v_e2 = 1644, 'PASS', 'FAIL'),
          CONCAT('update errno=', v_e1, ' delete errno=', v_e2));
END$$

INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'B', 'B22 ledger arithmetic: 10 reward + 40 adjustment - price = balance',
       IF(w.cached_balance = 50 - @price1 AND w.ledger_sum = w.cached_balance AND w.last_balance_after = w.cached_balance
          AND w.entry_count = 3, 'PASS', 'FAIL'),
       CONCAT('expected=', 50 - @price1, ' cached=', w.cached_balance, ' ledger_sum=', w.ledger_sum,
              ' last_balance_after=', w.last_balance_after, ' entries=', w.entry_count)
  FROM v_wallet_reconciliation w WHERE w.user_id = @s1$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE badges SET price_coins = price_coins + 500 WHERE id = 1;
  UPDATE badge_ownership SET price_paid = 1 WHERE id = @own1;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B23 price paid preserved after catalogue price change; ownership immutable',
         IF(v_errno = 1644 AND bo.price_paid = @price1 AND b.price_coins = @price1 + 500, 'PASS', 'FAIL'),
         CONCAT('price_paid=', bo.price_paid, ' new catalogue price=', b.price_coins, ' ownership update errno=', v_errno)
    FROM badge_ownership bo JOIN badges b ON b.id = bo.badge_id WHERE bo.id = @own1;
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO badge_showcase (user_id, position, badge_id) VALUES (@s1, 1, 1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B24 student can showcase a badge they own', IF(v_errno = 0, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO badge_showcase (user_id, position, badge_id) VALUES (@s2, 1, 1);   -- badge 1 is owned by s1, not s2
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B25 showcase cannot use another student''s ownership', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- B26-B30 other structural rules
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO reward_claims (user_id, topic_id, reward_type, attempt_id, reward_policy_id, coin_amount)
  VALUES (@s2, 1, 'topic_first_completion', @a1, 1, 10);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B26 reward cannot cite another student''s attempt', IF(v_errno = 1452, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- ---- B27 rank progression (approved policy: topics passed 0-2 E, 3-5 D, 6-8 C, ...)
-- s1 is D (placement) and has passed 1 topic (attempt @a2); @a1 is a failed attempt.
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
  VALUES (@s1, 2, 3, 'promotion', @a2, 1, 1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B27a promotion refused when topics passed is outside the band', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
  VALUES (@s1, 2, 3, 'promotion', @a2, 1, 7);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B27b promotion refused when topics_passed is not the real count', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
  VALUES (@s1, 2, 3, 'promotion', @a1, 1, 1);
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B27c promotion must cite a passed quiz of the student', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, changed_by_admin_id, note)
  VALUES (@s1, 2, 1, 'correction', @adm, 'attempt to lower a rank');
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B27d no rank_history entry may lower a rank (even a correction)', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

-- Fixture: s2 placed E (0/10), passes topics 1, 2 and 3 (topic 3 uses test-only
-- quiz questions inserted here and rolled back), then earns D by the policy.
BEGIN NOT ATOMIC
  DECLARE v_attempt INT UNSIGNED;
  DECLARE v_policy SMALLINT UNSIGNED;
  DECLARE v_t TINYINT UNSIGNED DEFAULT 1;
  DECLARE v_n INT DEFAULT 1;
  DECLARE v_correct INT;
  DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    INSERT INTO verify_results (section, check_name, outcome, detail)
    VALUES ('B', 'B27e rank fixture', 'FAIL', CONCAT('builder error: ', LEFT(COALESCE(v_msg, '(no message)'), 300)));
  END;
  -- test-only quiz questions for topic 3 (2 choices each, A correct)
  WHILE v_n <= 5 DO
    INSERT INTO questions (id, topic_id, for_quiz, for_assessment, stem, status)
    VALUES (9300 + v_n, 3, 1, 0, CONCAT('verify.sql test question ', v_n), 'active');
    INSERT INTO question_choices (id, question_id, label, choice_text, is_correct)
    VALUES ((9300 + v_n) * 10 + 1, 9300 + v_n, 'A', 'right', 1), ((9300 + v_n) * 10 + 2, 9300 + v_n, 'B', 'wrong', 0);
    SET v_n = v_n + 1;
  END WHILE;
  -- s2 placement: 0/10 -> E
  SELECT id INTO v_policy FROM assessment_policies WHERE is_current = 1;
  INSERT INTO attempts (user_id, kind, attempt_number, question_count) VALUES (@s2, 'assessment', 1, 10);
  SET v_attempt = LAST_INSERT_ID();
  INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
  SELECT v_attempt, ROW_NUMBER() OVER (ORDER BY g.sort_order, x.id), x.id, x.revision, x.topic_id, x.skill_group_id, x.stem, x.code_snippet, x.explanation
    FROM (SELECT q.*, t.skill_group_id, ROW_NUMBER() OVER (PARTITION BY t.skill_group_id ORDER BY q.id) AS rn
            FROM questions q JOIN topics t ON t.id = q.topic_id WHERE q.for_assessment = 1 AND q.status = 'active') x
    JOIN assessment_group_quotas gq ON gq.policy_id = v_policy AND gq.skill_group_id = x.skill_group_id
    JOIN skill_groups g ON g.id = x.skill_group_id
   WHERE x.rn <= gq.question_count;
  INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
  SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
    FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq
     SET aq.selected_choice_id = (SELECT c.id FROM attempt_question_choices c
                                   WHERE c.attempt_question_id = aq.id AND c.is_correct = 0 ORDER BY c.label LIMIT 1),
         aq.answered_at = UTC_TIMESTAMP(3)
   WHERE aq.attempt_id = v_attempt;
  UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
     SET aq.is_correct = c.is_correct WHERE aq.attempt_id = v_attempt;
  INSERT INTO assessment_results (attempt_id, assessment_policy_id, correct_count, placement_rank_id, recommended_topic_id)
  VALUES (v_attempt, v_policy, 0, 1, 1);
  UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = v_attempt;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id) VALUES (@s2, NULL, 1, 'placement', v_attempt);
  UPDATE student_profiles SET experience_level_id = 1, experience_chosen_at = UTC_TIMESTAMP(3),
         placement_attempt_id = v_attempt, placement_rank_id = 1, current_rank_id = 1, onboarding_state = 'assessment_submitted'
   WHERE user_id = @s2;
  -- pass topics 1, 2, 3 with 3/5 each
  WHILE v_t <= 3 DO
    INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via) VALUES (@s2, 'topic_quiz', v_t, 1, 5, 'direct');
    SET v_attempt = LAST_INSERT_ID();
    INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
    SELECT v_attempt, ROW_NUMBER() OVER (ORDER BY x.id), x.id, x.revision, x.topic_id, x.skill_group_id, x.stem, x.code_snippet, x.explanation
      FROM (SELECT q.*, t.skill_group_id FROM questions q JOIN topics t ON t.id = q.topic_id
             WHERE q.topic_id = v_t AND q.for_quiz = 1 AND q.status = 'active' ORDER BY q.id LIMIT 5) x;
    INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
    SELECT aq.id, c.id, c.label, c.choice_text, c.is_correct
      FROM attempt_questions aq JOIN question_choices c ON c.question_id = aq.question_id WHERE aq.attempt_id = v_attempt;
    UPDATE attempt_questions aq
       SET aq.selected_choice_id = (SELECT c.id FROM attempt_question_choices c
                                     WHERE c.attempt_question_id = aq.id AND c.is_correct = IF(aq.position <= 3, 1, 0) ORDER BY c.label LIMIT 1),
           aq.answered_at = UTC_TIMESTAMP(3)
     WHERE aq.attempt_id = v_attempt;
    UPDATE attempt_questions aq JOIN attempt_question_choices c ON c.id = aq.selected_choice_id
       SET aq.is_correct = c.is_correct WHERE aq.attempt_id = v_attempt;
    SELECT SUM(is_correct) INTO v_correct FROM attempt_questions WHERE attempt_id = v_attempt;
    INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed)
    SELECT v_attempt, b.policy_id, b.correct_count, b.grade_letter, b.percent, b.correct_count >= p.pass_mark_correct
      FROM quiz_grading_policies p JOIN quiz_grade_bands b ON b.policy_id = p.id
     WHERE p.is_current = 1 AND b.correct_count = v_correct;
    UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = v_attempt;
    SET v_t = v_t + 1;
  END WHILE;
  SET @s2_last_pass = v_attempt;
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
  VALUES (@s2, 1, 2, 'promotion', @s2_last_pass, 1, 3);
  IF v_errno = 0 THEN
    UPDATE student_profiles SET current_rank_id = 2, updated_at = UTC_TIMESTAMP(3) WHERE user_id = @s2;
  END IF;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B27e 3 topics passed: E -> D promotion recorded with evidence + policy',
         IF(v_errno = 0 AND sp.current_rank_id = 2 AND sp.placement_rank_id = 1
            AND (SELECT topics_passed FROM v_student_topics_passed WHERE user_id = @s2) = 3
            AND (SELECT COUNT(*) FROM rank_history WHERE user_id = @s2 AND reason = 'promotion' AND rank_policy_id = 1 AND topics_passed = 3) = 1,
            'PASS', 'FAIL'),
         CONCAT('errno=', v_errno, ' placement=', sp.placement_rank_id, ' current=', sp.current_rank_id,
                ' topics passed=', (SELECT topics_passed FROM v_student_topics_passed WHERE user_id = @s2))
    FROM student_profiles sp WHERE sp.user_id = @s2;
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE student_profiles SET current_rank_id = 1 WHERE user_id = @s2;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B27f current Hunter rank never decreases', IF(v_errno = 1644, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO quiz_grading_policies (id, version, status, is_current, question_count, pass_mark_correct, min_pool_size, description)
  VALUES (99, 99, 'provisional', 1, 5, 3, 10, 'second current policy');
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B28 at most one current policy version', IF(v_errno = 1062, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'insert was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_e1 INT DEFAULT 0; DECLARE v_e2 INT DEFAULT 0; DECLARE v_errno INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO;
  INSERT INTO login_events (user_id, outcome) VALUES (@s1, 'unknown_account');
  SET v_e1 = v_errno, v_errno = 0;
  INSERT INTO user_sessions (user_id, session_ref_hash, app_policy_id) VALUES (@s1, 'raw-session-token-value', 1);
  SET v_e2 = v_errno;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B29 login/session CHECKs (identity rule, hashed session ref)', IF(v_e1 = 4025 AND v_e2 = 4025, 'PASS', 'FAIL'),
          CONCAT('unknown_account with user errno=', v_e1, ' raw token errno=', v_e2));
END$$

BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  INSERT INTO user_sessions (user_id, session_ref_hash, app_policy_id)
  VALUES (@s1, SHA2('verify-session', 256), 1);
  SET @sess = LAST_INSERT_ID();
  INSERT INTO login_events (user_id, outcome, session_id) VALUES (@s1, 'success', @sess);
  UPDATE user_sessions SET ended_at = last_activity_at + INTERVAL 30 MINUTE, end_reason = 'expired' WHERE id = @sess;
  UPDATE user_sessions SET last_activity_at = last_activity_at + INTERVAL 1 HOUR WHERE id = @sess;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B30 expired session is final; admin overview reads it',
         IF(v_errno = 1644 AND o.last_successful_login_at IS NOT NULL AND o.recently_active IN (0, 1)
            AND o.hunter_rank = 'D-Rank', 'PASS', 'FAIL'),
         CONCAT('update-after-end errno=', v_errno, ' rank=', o.hunter_rank, ' current topic=', COALESCE(o.current_topic, '-'),
                ' passed=', o.topics_passed, '/', o.topics_total)
    FROM v_admin_student_overview o WHERE o.user_id = @s1;
END$$

-- ---- B31-B33 decisions of the authentication milestone (schema -3)
BEGIN NOT ATOMIC
  DECLARE v_errno INT DEFAULT 0; DECLARE v_msg VARCHAR(512) DEFAULT '';
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
  UPDATE badges SET status = 'on_sale' WHERE id = 2 AND price_status = 'provisional';
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B31 a badge cannot go on sale without an approved price', IF(v_errno = 4025, 'PASS', 'FAIL'),
          IF(v_errno = 0, 'update was accepted', CONCAT(v_errno, ': ', LEFT(COALESCE(v_msg, '(no message)'), 300))));
END$$

BEGIN NOT ATOMIC
  DECLARE v_e1 INT DEFAULT 0; DECLARE v_e2 INT DEFAULT 0; DECLARE v_e3 INT DEFAULT 0; DECLARE v_errno INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO;
  UPDATE badges SET price_status = 'approved', status = 'on_sale' WHERE id = 2;      -- explicit approval, then publish
  SET v_e1 = v_errno, v_errno = 0;
  UPDATE badges SET price_coins = price_coins + 5 WHERE id = 2;                     -- keeps approval: must fail
  SET v_e2 = v_errno, v_errno = 0;
  UPDATE badges SET price_coins = price_coins + 5, price_status = 'provisional', status = 'off_sale' WHERE id = 2;
  SET v_e3 = v_errno;
  INSERT INTO verify_results (section, check_name, outcome, detail)
  SELECT 'B', 'B32 price change withdraws approval; re-approval needed before sale',
         IF(v_e1 = 0 AND v_e2 = 1644 AND v_e3 = 0 AND b.price_status = 'provisional' AND b.status = 'off_sale', 'PASS', 'FAIL'),
         CONCAT('approve+publish errno=', v_e1, ' price change keeping approval errno=', v_e2,
                ' price change + provisional errno=', v_e3, ' now ', b.status, '/', b.price_status)
    FROM badges b WHERE b.id = 2;
END$$

BEGIN NOT ATOMIC
  DECLARE v_before INT; DECLARE v_after INT; DECLARE v_ready_before INT; DECLARE v_ready_after INT;
  SELECT quiz_pool_size, is_ready INTO v_before, v_ready_before FROM v_topic_readiness WHERE topic_id = 2;
  UPDATE question_choices SET is_correct = 0 WHERE question_id = 201;                -- no correct choice: invalid
  UPDATE questions SET status = 'retired', retired_at = UTC_TIMESTAMP(3) WHERE id IN (211, 212);
  SELECT quiz_pool_size, is_ready INTO v_after, v_ready_after FROM v_topic_readiness WHERE topic_id = 2;
  SET @min_pool = (SELECT min_pool_size FROM quiz_grading_policies WHERE is_current = 1);
  -- the pool must shrink by exactly the 3 changed questions and readiness must follow
  -- the threshold (with the standard seed: 12 ready -> 9 not ready)
  INSERT INTO verify_results (section, check_name, outcome, detail)
  VALUES ('B', 'B33 readiness counts only active valid questions (needs 10)',
          IF(v_after = v_before - 3 AND v_ready_before = (v_before >= @min_pool) AND v_ready_after = (v_after >= @min_pool)
             AND (NOT @standard_content OR (v_before = 12 AND v_ready_after = 0)), 'PASS', 'FAIL'),
          CONCAT('topic 2 pool ', v_before, ' ready=', v_ready_before, ' -> after 1 invalid + 2 retired: pool ', v_after, ' ready=', v_ready_after,
                 ' (threshold ', @min_pool, ')'));
END$$

DELIMITER ;

ROLLBACK;

-- =============================================================================
-- PART C - rollback left nothing behind
-- =============================================================================
INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'C', 'C01 test records rolled back',
       IF(COUNT(*) = 0 AND NOT EXISTS (SELECT 1 FROM questions WHERE stem LIKE '[EDITED]%')
          AND (SELECT price_coins FROM badges WHERE id = 1) < 500
          AND NOT EXISTS (SELECT 1 FROM questions WHERE id BETWEEN 9301 AND 9305), 'PASS', 'FAIL'),
       CONCAT('verify users remaining=', COUNT(*))
  FROM users WHERE email LIKE 'verify.%@example.invalid';

-- Every Part B check must have reported (a silently skipped check is a FAIL).
INSERT INTO verify_results (section, check_name, outcome, detail)
SELECT 'C', 'C02 all 43 Part B checks reported',
       IF(COUNT(*) = 43, 'PASS', 'FAIL'), CONCAT('Part B result rows=', COUNT(*), ' expected=43')
  FROM verify_results WHERE section = 'B';

SELECT seq, section, check_name, outcome, detail FROM verify_results ORDER BY seq;
SELECT SUM(outcome = 'PASS') AS passed, SUM(outcome = 'FAIL') AS failed, SUM(outcome = 'INFO') AS info
  FROM verify_results;
