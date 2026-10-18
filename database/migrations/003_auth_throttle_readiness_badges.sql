-- =============================================================================
-- Hunter System - migration 003: schema 2026.10.14-2  ->  2026.10.14-3
--
-- Decisions of 2026-10-14 (authentication milestone):
--   * login_throttle: bounded-retention table for login throttling (keyed hashes only)
--   * v_topic_readiness: a topic is ready only with >= min_pool_size (10) active
--     VALID quiz questions (>= 2 choices, exactly one correct) and a published lesson
--   * badges: on_sale requires price_status = 'approved' (CHECK); changing the
--     price while approved is refused (trigger) -> renewed approval required
--
-- Preserves all records. Result is structurally identical to a fresh install of
-- schema.sql (-3). Take a logical backup first.
--
-- Usage (the flag is required so a failed guard stops the script):
--   mysql --abort-source-on-error -u root -p hunter_system_dev -e "source C:/xampp/htdocs/hunter-system/database/migrations/003_auth_throttle_readiness_badges.sql"
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';

DELIMITER $$
BEGIN NOT ATOMIC
  IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_info') = 0
     OR NOT EXISTS (SELECT 1 FROM schema_info WHERE id = 1 AND schema_version = '2026.10.14-2') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 003: database is not at schema 2026.10.14-2; nothing changed.';
  END IF;
  IF EXISTS (SELECT 1 FROM badges WHERE status = 'on_sale' AND price_status <> 'approved') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 003: some on_sale badges have an unapproved price; approve or withdraw them first. Nothing changed.';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_throttle') > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'migration 003: login_throttle already exists. Nothing changed.';
  END IF;
END$$
DELIMITER ;

CREATE TABLE login_throttle (
  key_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  key_type       ENUM('email','ip') NOT NULL,
  window_start   DATETIME(3)       NOT NULL,
  failure_count  SMALLINT UNSIGNED NOT NULL,
  locked_until   DATETIME(3)       NULL,
  created_at     DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at     DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (key_hash),
  KEY ix_login_throttle_created (created_at),
  KEY ix_login_throttle_updated (updated_at),
  CONSTRAINT chk_lt_key CHECK (key_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_lt_count CHECK (failure_count >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE badges
  ADD CONSTRAINT chk_badges_sale_needs_approved_price CHECK (status <> 'on_sale' OR price_status = 'approved');

DELIMITER $$
CREATE TRIGGER trg_badges_bu BEFORE UPDATE ON badges FOR EACH ROW
BEGIN
  IF NEW.price_coins <> OLD.price_coins AND NEW.price_status = 'approved' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'badges: a price change requires renewed approval (set price_status = provisional)';
  END IF;
END$$
DELIMITER ;

CREATE OR REPLACE VIEW v_topic_readiness AS
SELECT x.topic_id,
       x.position,
       x.content_status,
       x.lesson_published,
       x.quiz_pool_size,
       (x.content_status = 'available' AND x.lesson_published = 1
        AND x.quiz_pool_size >= (SELECT p.min_pool_size FROM quiz_grading_policies p WHERE p.is_current = 1)) AS is_ready
  FROM (SELECT t.id AS topic_id,
               t.position,
               t.status AS content_status,
               EXISTS (SELECT 1 FROM lessons l WHERE l.topic_id = t.id AND l.status = 'published') AS lesson_published,
               (SELECT COUNT(*) FROM questions q
                 WHERE q.topic_id = t.id AND q.for_quiz = 1 AND q.status = 'active'
                   AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id) >= 2
                   AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id AND c.is_correct = 1) = 1) AS quiz_pool_size
          FROM topics t) x;

UPDATE schema_info SET schema_version = '2026.10.14-3' WHERE id = 1;

SELECT CONCAT('migration 003 complete: schema ', schema_version) AS result FROM schema_info WHERE id = 1;
