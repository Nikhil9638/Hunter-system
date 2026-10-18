-- =============================================================================
-- Hunter System - migration 002: schema 2026.10.14-1  ->  2026.10.14-2
--
-- Applies the changes approved on 2026-10-14 to an EXISTING -1 database while
-- preserving its records. The result is structurally identical to a fresh
-- install of schema.sql (-2) + seed.sql (verified by comparison, see README).
--
--   S1  assessment_group_results / assessment_topic_evidence -> derived views
--   S2  quiz statistics removed from student_topic_progress -> derived view
--   Rank progression policy (topics passed) + promotion evidence on rank_history
--   Placement fallback: assessment_results.offered_topic_id (+ policy fallback_rule)
--   Readiness threshold: quiz_grading_policies.min_pool_size
--   Email-only login: users.username removed, lower-case email CHECK
--   Badges: 'retired' status
--   Policies: provisional v1 rows retired; approved values inserted as v2 (current)
--
-- SAFETY
--   * Take a logical backup first (mysqldump --routines --triggers --events).
--   * MariaDB DDL commits implicitly, so this migration is NOT atomic. Every
--     precondition is therefore checked in the guard block BEFORE any change:
--       - schema version is exactly 2026.10.14-1
--       - no stored evidence / progress statistic differs from what the views
--         derive (so removing them loses no information)
--       - no username is set; every email is already lower-case
--       - version-2 policy ids are free
--     If a guard fails, nothing has been changed.
--   * No DROP DATABASE, no TRUNCATE, no DELETE. Dropped objects: 6 triggers,
--     2 derivable tables (verified equal to the derivation), 3 FK indexes and
--     7 derivable columns, 1 unused column (username, verified empty).
--
-- Usage (--abort-source-on-error is REQUIRED: without it the client keeps
-- executing after a failed guard):
--   mysql --abort-source-on-error -u root -p hunter_system_dev -e "source C:/xampp/htdocs/hunter-system/database/migrations/002_v2_simplify_and_approvals.sql"
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Guard block (read-only)
-- -----------------------------------------------------------------------------
DELIMITER $$
BEGIN NOT ATOMIC
  IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_info') = 0
     OR NOT EXISTS (SELECT 1 FROM schema_info WHERE id = 1 AND schema_version = '2026.10.14-1') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: database is not at schema 2026.10.14-1; nothing changed.';
  END IF;

  -- S1: stored per-group results must equal the snapshot derivation
  IF EXISTS (SELECT 1 FROM assessment_group_results s
              WHERE s.asked_count <> (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = s.attempt_id AND aq.skill_group_id = s.skill_group_id)
                 OR s.correct_count <> (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = s.attempt_id AND aq.skill_group_id = s.skill_group_id))
     OR EXISTS (SELECT 1 FROM assessment_topic_evidence s
              WHERE s.asked_count <> (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = s.attempt_id AND aq.topic_id = s.topic_id)
                 OR s.correct_count <> (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = s.attempt_id AND aq.topic_id = s.topic_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: stored assessment evidence differs from the snapshot derivation; investigate before migrating. Nothing changed.';
  END IF;

  -- S2: stored progress statistics must equal the attempts/results derivation
  IF EXISTS (SELECT 1 FROM student_topic_progress p
              WHERE p.completed_attempt_count <> (SELECT COUNT(*) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                                                    WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id)
                 OR NOT (p.best_correct_count <=> (SELECT MAX(r.correct_count) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                                                     WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id))
                 OR (p.first_passed_attempt_id IS NULL) <> (NOT EXISTS (SELECT 1 FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                                                                      WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id AND r.passed = 1))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: stored progress statistics differ from attempts/results; investigate before migrating. Nothing changed.';
  END IF;

  IF EXISTS (SELECT 1 FROM users WHERE username IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: users.username holds data; decide how to keep it before migrating. Nothing changed.';
  END IF;
  IF EXISTS (SELECT 1 FROM users WHERE CAST(email AS BINARY) <> CAST(LOWER(email) AS BINARY)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: some emails are not lower-case; normalize them first. Nothing changed.';
  END IF;
  IF EXISTS (SELECT 1 FROM quiz_grading_policies WHERE id = 2 OR version = 2)
     OR EXISTS (SELECT 1 FROM assessment_policies WHERE id = 2 OR version = 2)
     OR EXISTS (SELECT 1 FROM reward_policies WHERE id = 2)
     OR EXISTS (SELECT 1 FROM app_policies WHERE id = 2 OR version = 2)
     OR (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'rank_progression%') > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 002: version-2 policy ids or rank progression tables already exist. Nothing changed.';
  END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- S1: derived assessment evidence
-- -----------------------------------------------------------------------------
DROP TRIGGER trg_agr_bi;
DROP TRIGGER trg_agr_bu;
DROP TRIGGER trg_agr_bd;
DROP TRIGGER trg_ate_bi;
DROP TRIGGER trg_ate_bu;
DROP TRIGGER trg_ate_bd;
DROP TABLE assessment_group_results;
DROP TABLE assessment_topic_evidence;

-- -----------------------------------------------------------------------------
-- S2: progress keeps only unlock and lesson facts
-- -----------------------------------------------------------------------------
ALTER TABLE student_topic_progress
  DROP FOREIGN KEY fk_stp_first_completed,
  DROP FOREIGN KEY fk_stp_first_completed_result,
  DROP FOREIGN KEY fk_stp_best,
  DROP FOREIGN KEY fk_stp_best_result,
  DROP FOREIGN KEY fk_stp_first_passed,
  DROP FOREIGN KEY fk_stp_first_passed_result;
ALTER TABLE student_topic_progress
  DROP CONSTRAINT chk_stp_completed,
  DROP CONSTRAINT chk_stp_best,
  DROP CONSTRAINT chk_stp_passed,
  DROP INDEX fk_stp_first_completed,
  DROP INDEX fk_stp_best,
  DROP INDEX fk_stp_first_passed,
  DROP COLUMN completed_attempt_count,
  DROP COLUMN first_completed_at,
  DROP COLUMN first_completed_attempt_id,
  DROP COLUMN best_correct_count,
  DROP COLUMN best_attempt_id,
  DROP COLUMN first_passed_at,
  DROP COLUMN first_passed_attempt_id;

-- -----------------------------------------------------------------------------
-- Email-only login
-- -----------------------------------------------------------------------------
ALTER TABLE users
  DROP INDEX uq_users_username,
  DROP COLUMN username,
  ADD CONSTRAINT chk_users_email_lower CHECK (CAST(email AS BINARY) = CAST(LOWER(email) AS BINARY));

-- -----------------------------------------------------------------------------
-- Policy columns, placement fallback, retired badges
-- -----------------------------------------------------------------------------
ALTER TABLE quiz_grading_policies
  ADD COLUMN min_pool_size TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER pass_mark_correct,
  ADD CONSTRAINT chk_qgp_pool CHECK (min_pool_size >= question_count);
ALTER TABLE quiz_grading_policies ALTER COLUMN min_pool_size DROP DEFAULT;

ALTER TABLE assessment_policies
  ADD COLUMN fallback_rule ENUM('nearest_earlier_ready_topic') NOT NULL DEFAULT 'nearest_earlier_ready_topic' AFTER starting_topic_rule;
ALTER TABLE assessment_policies ALTER COLUMN fallback_rule DROP DEFAULT;

ALTER TABLE assessment_results
  ADD COLUMN offered_topic_id TINYINT UNSIGNED NULL AFTER recommended_topic_id,
  ADD KEY ix_assessment_results_offered (offered_topic_id),
  ADD CONSTRAINT fk_assessment_results_offered FOREIGN KEY (offered_topic_id) REFERENCES topics (id),
  ADD CONSTRAINT chk_ar_offered CHECK (offered_topic_id IS NULL OR offered_topic_id <> recommended_topic_id);

ALTER TABLE badges
  MODIFY status ENUM('draft','on_sale','off_sale','retired') NOT NULL DEFAULT 'draft';

-- -----------------------------------------------------------------------------
-- Rank progression
-- -----------------------------------------------------------------------------
CREATE TABLE rank_progression_policies (
  id           SMALLINT UNSIGNED NOT NULL,
  version      SMALLINT UNSIGNED NOT NULL,
  status       ENUM('provisional','approved','retired') NOT NULL DEFAULT 'provisional',
  is_current   TINYINT UNSIGNED  NULL,
  description  VARCHAR(255)      NOT NULL,
  created_at   DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  approved_at  DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rank_progression_policies_version (version),
  UNIQUE KEY uq_rank_progression_policies_current (is_current),
  CONSTRAINT chk_rpp_current CHECK (is_current = 1),
  CONSTRAINT chk_rpp_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'retired'),
  CONSTRAINT chk_rpp_retired_not_current CHECK (status <> 'retired' OR is_current IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rank_progression_bands (
  policy_id          SMALLINT UNSIGNED NOT NULL,
  min_topics_passed  TINYINT UNSIGNED  NOT NULL,
  max_topics_passed  TINYINT UNSIGNED  NOT NULL,
  rank_id            TINYINT UNSIGNED  NOT NULL,
  PRIMARY KEY (policy_id, min_topics_passed),
  UNIQUE KEY uq_rank_progression_bands_max (policy_id, max_topics_passed),
  UNIQUE KEY uq_rank_progression_bands_rank (policy_id, rank_id),
  KEY ix_rank_progression_bands_rank (rank_id),
  CONSTRAINT fk_rank_progression_bands_policy FOREIGN KEY (policy_id) REFERENCES rank_progression_policies (id),
  CONSTRAINT fk_rank_progression_bands_rank FOREIGN KEY (rank_id) REFERENCES ranks (id),
  CONSTRAINT chk_rpb_range CHECK (min_topics_passed <= max_topics_passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rank_history
  ADD COLUMN rank_policy_id SMALLINT UNSIGNED NULL AFTER evidence_attempt_id,
  ADD COLUMN topics_passed TINYINT UNSIGNED NULL AFTER rank_policy_id,
  ADD CONSTRAINT fk_rank_history_policy_band FOREIGN KEY (rank_policy_id, to_rank_id) REFERENCES rank_progression_bands (policy_id, rank_id),
  ADD CONSTRAINT chk_rh_promotion CHECK ((reason = 'promotion') = (rank_policy_id IS NOT NULL)
                                         AND (rank_policy_id IS NULL) = (topics_passed IS NULL));

-- -----------------------------------------------------------------------------
-- Triggers (same bodies as schema.sql -2)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_student_profiles_bu BEFORE UPDATE ON student_profiles FOR EACH ROW
BEGIN
  DECLARE v_ledger_balance INT UNSIGNED DEFAULT 0;
  DECLARE v_history_rank TINYINT UNSIGNED DEFAULT NULL;
  IF OLD.placement_attempt_id IS NOT NULL
     AND (NOT (NEW.placement_attempt_id <=> OLD.placement_attempt_id)
          OR NOT (NEW.placement_rank_id <=> OLD.placement_rank_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: the initial placement is permanent (I-11)';
  END IF;
  IF NEW.coin_balance <> OLD.coin_balance THEN
    SELECT COALESCE((SELECT balance_after FROM coin_ledger WHERE user_id = NEW.user_id ORDER BY id DESC LIMIT 1), 0)
      INTO v_ledger_balance;
    IF NEW.coin_balance <> v_ledger_balance THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: coin_balance can only change by inserting a coin_ledger entry (I-12)';
    END IF;
  END IF;
  IF NOT (NEW.current_rank_id <=> OLD.current_rank_id) THEN
    IF OLD.current_rank_id IS NOT NULL AND
       (SELECT sort_order FROM ranks WHERE id = NEW.current_rank_id) < (SELECT sort_order FROM ranks WHERE id = OLD.current_rank_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: the Hunter rank never decreases';
    END IF;
    SELECT (SELECT to_rank_id FROM rank_history WHERE user_id = NEW.user_id ORDER BY id DESC LIMIT 1)
      INTO v_history_rank;
    IF NOT (NEW.current_rank_id <=> v_history_rank) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: current rank must equal the latest rank_history entry';
    END IF;
  END IF;
END$$

CREATE OR REPLACE TRIGGER trg_rank_history_bi BEFORE INSERT ON rank_history FOR EACH ROW
BEGIN
  DECLARE v_current TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_passed INT DEFAULT 0;
  DECLARE v_min TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_max TINYINT UNSIGNED DEFAULT NULL;
  SELECT current_rank_id INTO v_current FROM student_profiles WHERE user_id = NEW.user_id FOR UPDATE;
  IF NEW.reason <> 'placement' THEN
    IF NOT (NEW.from_rank_id <=> v_current) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history: from_rank must equal the student''s current rank';
    END IF;
    IF (SELECT sort_order FROM ranks WHERE id = NEW.to_rank_id) <= (SELECT sort_order FROM ranks WHERE id = NEW.from_rank_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history: a rank change must raise the rank (ranks never decrease)';
    END IF;
  END IF;
  IF NEW.reason = 'promotion' THEN
    -- evidence: a passed quiz of this student
    IF NOT EXISTS (SELECT 1 FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                    WHERE a.id = NEW.evidence_attempt_id AND a.user_id = NEW.user_id AND r.passed = 1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history: a promotion must cite a passed quiz of this student';
    END IF;
    -- topics_passed must be the real count, and must fall in the cited band
    SELECT COUNT(DISTINCT a.topic_id) INTO v_passed
      FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
     WHERE a.user_id = NEW.user_id AND r.passed = 1;
    IF NEW.topics_passed <> v_passed THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history: topics_passed does not match the passed quiz results';
    END IF;
    SELECT min_topics_passed, max_topics_passed INTO v_min, v_max
      FROM rank_progression_bands WHERE policy_id = NEW.rank_policy_id AND rank_id = NEW.to_rank_id;
    IF v_min IS NOT NULL AND NOT (v_passed BETWEEN v_min AND v_max) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history: topics_passed is outside the band for the promoted rank';
    END IF;
  END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Views (same definitions as schema.sql -2)
-- -----------------------------------------------------------------------------
CREATE VIEW v_topic_readiness AS
SELECT t.id AS topic_id,
       t.position,
       t.status AS content_status,
       EXISTS (SELECT 1 FROM lessons l WHERE l.topic_id = t.id AND l.status = 'published') AS lesson_published,
       (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id AND q.for_quiz = 1 AND q.status = 'active') AS quiz_pool_size,
       (t.status = 'available'
        AND EXISTS (SELECT 1 FROM lessons l WHERE l.topic_id = t.id AND l.status = 'published')
        AND (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id AND q.for_quiz = 1 AND q.status = 'active')
            >= (SELECT p.min_pool_size FROM quiz_grading_policies p WHERE p.is_current = 1)) AS is_ready
  FROM topics t;

CREATE VIEW v_assessment_group_results AS
SELECT r.attempt_id,
       g.id AS skill_group_id,
       g.code AS skill_group_code,
       (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.skill_group_id = g.id) AS asked_count,
       (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.skill_group_id = g.id) AS correct_count
  FROM assessment_results r CROSS JOIN skill_groups g;

CREATE VIEW v_assessment_topic_evidence AS
SELECT x.attempt_id, x.topic_id, x.position, x.asked_count, x.correct_count,
       IF(x.asked_count = 0, 'not_assessed', 'sampled') AS evidence_label
  FROM (SELECT r.attempt_id, t.id AS topic_id, t.position,
               (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.topic_id = t.id) AS asked_count,
               (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.topic_id = t.id) AS correct_count
          FROM assessment_results r CROSS JOIN topics t) x;

CREATE OR REPLACE VIEW v_student_topic_status AS
SELECT s.*,
       CASE
         WHEN s.first_passed_at IS NOT NULL THEN 'passed'
         WHEN s.open_attempt_id IS NOT NULL THEN 'in_progress'
         WHEN s.completed_attempts > 0 THEN 'completed'
         WHEN s.unlocked_at IS NOT NULL THEN 'available'
         ELSE 'locked'
       END AS display_status
  FROM (SELECT p.user_id,
               t.id AS topic_id,
               t.position,
               t.title,
               t.status AS content_status,
               p.unlocked_at,
               p.unlock_source,
               p.first_lesson_visit_at,
               p.last_lesson_visit_at,
               p.lesson_visit_count,
               (SELECT COUNT(*) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                 WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id) AS completed_attempts,
               (SELECT MIN(a.submitted_at) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                 WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id) AS first_completed_at,
               (SELECT MAX(r.correct_count) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                 WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id) AS best_correct_count,
               (SELECT MIN(a.submitted_at) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                 WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id AND r.passed = 1) AS first_passed_at,
               (SELECT a.id FROM attempts a
                 WHERE a.user_id = p.user_id AND a.topic_id = p.topic_id AND a.status = 'in_progress' LIMIT 1) AS open_attempt_id
          FROM student_topic_progress p
          JOIN topics t ON t.id = p.topic_id) s;

CREATE VIEW v_student_topics_passed AS
SELECT sp.user_id,
       (SELECT COUNT(DISTINCT a.topic_id) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
         WHERE a.user_id = sp.user_id AND r.passed = 1) AS topics_passed
  FROM student_profiles sp;

CREATE OR REPLACE VIEW v_admin_student_overview AS
SELECT u.id AS user_id,
       u.display_name,
       u.email,
       u.status AS account_status,
       (SELECT MAX(le.occurred_at) FROM login_events le
         WHERE le.user_id = u.id AND le.outcome = 'success') AS last_successful_login_at,
       (SELECT MAX(s.last_activity_at) FROM user_sessions s WHERE s.user_id = u.id) AS last_activity_at,
       COALESCE((SELECT MAX(s.last_activity_at) FROM user_sessions s WHERE s.user_id = u.id)
                >= UTC_TIMESTAMP(3) - INTERVAL (SELECT ap.recently_active_minutes FROM app_policies ap WHERE ap.is_current = 1) MINUTE,
                0) AS recently_active,
       r.display_name AS hunter_rank,
       (SELECT t.title FROM student_topic_progress p JOIN topics t ON t.id = p.topic_id
         WHERE p.user_id = u.id AND p.unlocked_at IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
                            WHERE a.user_id = u.id AND a.topic_id = p.topic_id AND r.passed = 1)
         ORDER BY t.position LIMIT 1) AS current_topic,
       (SELECT COUNT(DISTINCT a.topic_id) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
         WHERE a.user_id = u.id AND r.passed = 1) AS topics_passed,
       (SELECT COUNT(*) FROM topics) AS topics_total
  FROM users u
  JOIN student_profiles sp ON sp.user_id = u.id
  LEFT JOIN ranks r ON r.id = sp.current_rank_id
 WHERE u.role = 'student';

-- -----------------------------------------------------------------------------
-- Approved policies (data, one transaction). Values and texts match seed.sql -2.
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE quiz_grading_policies
   SET status = 'retired', is_current = NULL,
       description = 'Superseded provisional development setting (P-01, P-02). Replaced by version 2.'
 WHERE id = 1;
INSERT INTO quiz_grading_policies (id, version, status, is_current, question_count, pass_mark_correct, min_pool_size, description, approved_at)
VALUES (2, 2, 'approved', 1, 5, 3, 10,
        'Approved 2026-10-14: five-question quiz, pass at 3/5 (grade C or better), A-F scale. Topic ready when its active quiz pool has at least 10 questions.',
        '2026-10-14 00:00:00.000');
INSERT INTO quiz_grade_bands (policy_id, correct_count, grade_letter, percent) VALUES
  (2, 5, 'A', 100), (2, 4, 'B', 80), (2, 3, 'C', 60), (2, 2, 'D', 40), (2, 1, 'E', 20), (2, 0, 'F', 0);

UPDATE assessment_policies
   SET status = 'retired', is_current = NULL,
       description = 'Superseded provisional development setting (P-19, P-27, P-28). Replaced by version 2.'
 WHERE id = 1;
INSERT INTO assessment_policies (id, version, status, is_current, question_count, starting_topic_rule, fallback_rule, all_correct_topic_id, description, approved_at)
VALUES (2, 2, 'approved', 1, 10, 'earliest_missed_group_first_topic', 'nearest_earlier_ready_topic', 18,
        'Approved 2026-10-14: 2/2/2/2/1/1 layout; 0-2 E, 3-4 D, 5-6 C, 7-8 B, 9 A, 10 S; first topic of earliest missed group (all correct: 18); if not ready, also offer nearest earlier ready topic.',
        '2026-10-14 00:00:00.000');
INSERT INTO assessment_group_quotas (policy_id, skill_group_id, question_count) VALUES
  (2, 1, 2), (2, 2, 2), (2, 3, 2), (2, 4, 2), (2, 5, 1), (2, 6, 1);
INSERT INTO placement_rank_bands (policy_id, min_correct, max_correct, rank_id) VALUES
  (2, 0, 2, 1), (2, 3, 4, 2), (2, 5, 6, 3), (2, 7, 8, 4), (2, 9, 9, 5), (2, 10, 10, 6);

UPDATE reward_policies
   SET status = 'retired', is_current = NULL,
       description = 'Superseded provisional development setting (P-10..P-14). Replaced by version 2.'
 WHERE id = 1;
INSERT INTO reward_policies (id, reward_type, version, status, is_current, coin_amount, description, approved_at)
VALUES (2, 'topic_first_completion', 2, 'approved', 1, 10,
        'Approved 2026-10-14: 10 coins once per student per topic on the first completed quiz, even if failed. No coins for partial attempts, retries or the placement assessment.',
        '2026-10-14 00:00:00.000');

INSERT INTO rank_progression_policies (id, version, status, is_current, description, approved_at)
VALUES (1, 1, 'approved', 1,
        'Approved 2026-10-14: rank by distinct topics passed (incl. below placement): 0-2 E, 3-5 D, 6-8 C, 9-11 B, 12-14 A, 15-18 S. Current rank = highest of placement, previous and earned; never decreases.',
        '2026-10-14 00:00:00.000');
INSERT INTO rank_progression_bands (policy_id, min_topics_passed, max_topics_passed, rank_id) VALUES
  (1, 0, 2, 1), (1, 3, 5, 2), (1, 6, 8, 3), (1, 9, 11, 4), (1, 12, 14, 5), (1, 15, 18, 6);

UPDATE app_policies
   SET status = 'retired', is_current = NULL,
       description = 'Superseded provisional development setting (P-16, P-29, P-30, P-31). Replaced by version 2.'
 WHERE id = 1;
INSERT INTO app_policies (id, version, status, is_current, showcase_limit, recently_active_minutes, session_idle_minutes,
                          activity_touch_seconds, description, approved_at)
VALUES (2, 2, 'approved', 1, 3, 15, 30, 60,
        'Approved 2026-10-14: showcase up to 3 owned badges; recently active = 15 min; idle expiry = 30 min; activity update about every 60 s.',
        '2026-10-14 00:00:00.000');

UPDATE schema_info SET schema_version = '2026.10.14-2' WHERE id = 1;

COMMIT;

SELECT CONCAT('migration 002 complete: schema ', schema_version) AS result FROM schema_info WHERE id = 1;
