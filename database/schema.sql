-- =============================================================================
-- Hunter System - database schema
-- Schema version : 2026.10.14-3
--                  (-2: derived assessment evidence and quiz statistics (S1, S2),
--                   rank progression policy, placement fallback offer, email-only
--                   login, retired badges. Upgrade path for -1 databases:
--                   database/migrations/002_v2_simplify_and_approvals.sql)
--                  (-3: login_throttle (bounded retention), readiness counts only
--                   valid quiz questions, badge sale requires an approved price and
--                   a price change withdraws approval. Upgrade path for -2:
--                   database/migrations/003_auth_throttle_readiness_badges.sql)
-- Target server  : MariaDB 10.4.32 (XAMPP). Uses MariaDB features: CHECK
--                  constraints, PERSISTENT generated columns, UTC_TIMESTAMP()
--                  column defaults and anonymous BEGIN NOT ATOMIC blocks.
-- Engine/charset : InnoDB, utf8mb4 / utf8mb4_unicode_ci
-- Time           : every DATETIME column stores UTC. Defaults use UTC_TIMESTAMP(),
--                  so they do not depend on the server or session time zone.
--                  Display conversion belongs to the application.
--
-- Usage (see database/README.md):
--   1. Create an EMPTY database, e.g. hunter_system_dev.
--   2. mysql -u <user> -p hunter_system_dev < database/schema.sql
--
-- Safety:
--   * No DROP, TRUNCATE or DELETE statements.
--   * The guard block below refuses to run unless the selected database is
--     empty, so an existing (possibly different) schema is never hidden or
--     merged. Re-running against an installed schema stops with a clear error.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION time_zone = '+00:00';
SET SESSION default_storage_engine = 'InnoDB';

DELIMITER $$
BEGIN NOT ATOMIC
  IF DATABASE() IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema.sql: no database selected. Create an empty database and select it first.';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema.sql: the selected database already contains tables. Refusing to install over an existing schema. Use a new empty database.';
  END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- 0. Schema marker
-- -----------------------------------------------------------------------------
CREATE TABLE schema_info (
  id              TINYINT UNSIGNED NOT NULL,
  schema_version  VARCHAR(40)      NOT NULL,
  installed_at    DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT chk_schema_info_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Accounts
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  role            ENUM('student','admin') NOT NULL DEFAULT 'student',
  display_name    VARCHAR(80)      NOT NULL,
  email           VARCHAR(254)     NOT NULL,     -- login identifier, stored lower-case
  password_hash   VARCHAR(255)     NOT NULL,     -- PHP password_hash(..., PASSWORD_ARGON2ID)
  status          ENUM('active','deactivated') NOT NULL DEFAULT 'active',
  created_at      DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at      DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  deactivated_at  DATETIME(3)      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_id_role (id, role),          -- target for role-checked composite FKs
  KEY ix_users_role_status (role, status),         -- administrator account list
  CONSTRAINT chk_users_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) > 0),
  CONSTRAINT chk_users_email CHECK (email LIKE '%_@_%'),
  CONSTRAINT chk_users_email_lower CHECK (CAST(email AS BINARY) = CAST(LOWER(email) AS BINARY)),
  CONSTRAINT chk_users_deactivation CHECK ((status = 'deactivated') = (deactivated_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Reference data
-- -----------------------------------------------------------------------------
CREATE TABLE experience_levels (
  id          TINYINT UNSIGNED NOT NULL,
  code        VARCHAR(30)      NOT NULL,
  label       VARCHAR(60)      NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_experience_levels_code (code),
  UNIQUE KEY uq_experience_levels_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ranks (
  id            TINYINT UNSIGNED NOT NULL,
  code          CHAR(1)          NOT NULL,
  sort_order    TINYINT UNSIGNED NOT NULL,     -- 1 = lowest (E) ... 6 = highest (S)
  display_name  VARCHAR(20)      NOT NULL,     -- 'E-Rank'
  title         VARCHAR(40)      NULL,         -- only E has a confirmed title ('Rookie')
  PRIMARY KEY (id),
  UNIQUE KEY uq_ranks_code (code),
  UNIQUE KEY uq_ranks_sort (sort_order),
  CONSTRAINT chk_ranks_code CHECK (code IN ('E','D','C','B','A','S'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skill_groups (
  id          TINYINT UNSIGNED NOT NULL,
  code        CHAR(2)          NOT NULL,       -- 'G1'..'G6'
  name        VARCHAR(60)      NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skill_groups_code (code),
  UNIQUE KEY uq_skill_groups_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Curriculum content
-- -----------------------------------------------------------------------------
CREATE TABLE topics (
  id              TINYINT UNSIGNED NOT NULL,
  position        TINYINT UNSIGNED NOT NULL,   -- curriculum order (1..18), fixed for this version
  slug            VARCHAR(60)      NOT NULL,
  title           VARCHAR(80)      NOT NULL,
  summary         VARCHAR(255)     NOT NULL,
  skill_group_id  TINYINT UNSIGNED NOT NULL,
  status          ENUM('draft','available','retired') NOT NULL DEFAULT 'draft',
                  -- 'available' = lesson published and quiz pool ready for students
  created_at      DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at      DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_topics_position (position),
  UNIQUE KEY uq_topics_slug (slug),
  KEY ix_topics_group_position (skill_group_id, position),
  CONSTRAINT fk_topics_skill_group FOREIGN KEY (skill_group_id) REFERENCES skill_groups (id),
  CONSTRAINT chk_topics_position CHECK (position >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lessons (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  topic_id      TINYINT UNSIGNED  NOT NULL,
  title         VARCHAR(120)      NOT NULL,
  body          MEDIUMTEXT        NOT NULL,    -- Markdown
  status        ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  revision      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at    DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at    DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  published_at  DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lessons_topic (topic_id),      -- one lesson per topic
  CONSTRAINT fk_lessons_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT chk_lessons_revision CHECK (revision >= 1),
  CONSTRAINT chk_lessons_published CHECK (status <> 'published' OR published_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  topic_id        TINYINT UNSIGNED  NOT NULL,  -- skill group is derived from the topic
  for_quiz        TINYINT(1)        NOT NULL DEFAULT 1,
  for_assessment  TINYINT(1)        NOT NULL DEFAULT 0,
  difficulty      ENUM('easy','medium','hard') NOT NULL DEFAULT 'easy',
  stem            TEXT              NOT NULL,
  code_snippet    TEXT              NULL,
  explanation     TEXT              NULL,
  status          ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
  revision        SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- incremented on every content edit
  created_at      DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at      DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  retired_at      DATETIME(3)       NULL,
  PRIMARY KEY (id),
  KEY ix_questions_quiz_pool (topic_id, for_quiz, status),
  KEY ix_questions_assessment_pool (for_assessment, status, topic_id),
  CONSTRAINT fk_questions_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT chk_questions_usage CHECK (for_quiz IN (0,1) AND for_assessment IN (0,1) AND for_quiz + for_assessment >= 1),
  CONSTRAINT chk_questions_revision CHECK (revision >= 1),
  CONSTRAINT chk_questions_retired CHECK ((status = 'retired') = (retired_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_choices (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id  INT UNSIGNED NOT NULL,
  label        CHAR(1)      NOT NULL,          -- 'A'..'F', also the display order
  choice_text  TEXT         NOT NULL,
  is_correct   TINYINT(1)   NOT NULL DEFAULT 0,
  correct_key  TINYINT UNSIGNED AS (IF(is_correct = 1, 1, NULL)) PERSISTENT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_choices_label (question_id, label),
  UNIQUE KEY uq_question_choices_one_correct (question_id, correct_key),  -- at most one correct choice
  CONSTRAINT fk_question_choices_question FOREIGN KEY (question_id) REFERENCES questions (id),
  CONSTRAINT chk_question_choices_label CHECK (label IN ('A','B','C','D','E','F')),
  CONSTRAINT chk_question_choices_is_correct CHECK (is_correct IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Versioned policies (rule values). One row per version; a version is never
--    edited once results reference it (composite FKs make referenced values
--    immutable). is_current marks the version PHP uses for new work; the
--    UNIQUE key on the nullable column allows at most one current version.
-- -----------------------------------------------------------------------------
CREATE TABLE quiz_grading_policies (
  id                 SMALLINT UNSIGNED NOT NULL,
  version            SMALLINT UNSIGNED NOT NULL,
  status             ENUM('provisional','approved','retired') NOT NULL DEFAULT 'provisional',
  is_current         TINYINT UNSIGNED  NULL,
  question_count     TINYINT UNSIGNED  NOT NULL,
  pass_mark_correct  TINYINT UNSIGNED  NOT NULL,
  min_pool_size      TINYINT UNSIGNED  NOT NULL,  -- active quiz questions a topic needs to count as ready
  description        VARCHAR(255)      NOT NULL,
  created_at         DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  approved_at        DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quiz_grading_policies_version (version),
  UNIQUE KEY uq_quiz_grading_policies_current (is_current),
  CONSTRAINT chk_qgp_current CHECK (is_current = 1),
  CONSTRAINT chk_qgp_question_count CHECK (question_count = 5),            -- C-09 (confirmed)
  CONSTRAINT chk_qgp_pass_mark CHECK (pass_mark_correct BETWEEN 1 AND question_count),
  CONSTRAINT chk_qgp_pool CHECK (min_pool_size >= question_count),
  CONSTRAINT chk_qgp_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'retired'),
  CONSTRAINT chk_qgp_retired_not_current CHECK (status <> 'retired' OR is_current IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_grade_bands (
  policy_id      SMALLINT UNSIGNED NOT NULL,
  correct_count  TINYINT UNSIGNED  NOT NULL,
  grade_letter   ENUM('A','B','C','D','E','F') NOT NULL,
  percent        TINYINT UNSIGNED  NOT NULL,
  PRIMARY KEY (policy_id, correct_count),
  UNIQUE KEY uq_quiz_grade_bands_full (policy_id, correct_count, grade_letter, percent),
  CONSTRAINT fk_quiz_grade_bands_policy FOREIGN KEY (policy_id) REFERENCES quiz_grading_policies (id),
  CONSTRAINT chk_quiz_grade_bands_percent CHECK (percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_policies (
  id                    SMALLINT UNSIGNED NOT NULL,
  version               SMALLINT UNSIGNED NOT NULL,
  status                ENUM('provisional','approved','retired') NOT NULL DEFAULT 'provisional',
  is_current            TINYINT UNSIGNED  NULL,
  question_count        TINYINT UNSIGNED  NOT NULL,
  starting_topic_rule   ENUM('earliest_missed_group_first_topic') NOT NULL,
  fallback_rule         ENUM('nearest_earlier_ready_topic') NOT NULL,   -- used when the recommended topic is not ready
  all_correct_topic_id  TINYINT UNSIGNED  NOT NULL,
  description           VARCHAR(255)      NOT NULL,
  created_at            DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  approved_at           DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assessment_policies_version (version),
  UNIQUE KEY uq_assessment_policies_current (is_current),
  CONSTRAINT fk_assessment_policies_topic FOREIGN KEY (all_correct_topic_id) REFERENCES topics (id),
  CONSTRAINT chk_ap_current CHECK (is_current = 1),
  CONSTRAINT chk_ap_question_count CHECK (question_count = 10),            -- C-06 (confirmed)
  CONSTRAINT chk_ap_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'retired'),
  CONSTRAINT chk_ap_retired_not_current CHECK (status <> 'retired' OR is_current IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_group_quotas (
  policy_id       SMALLINT UNSIGNED NOT NULL,
  skill_group_id  TINYINT UNSIGNED  NOT NULL,
  question_count  TINYINT UNSIGNED  NOT NULL,
  PRIMARY KEY (policy_id, skill_group_id),
  CONSTRAINT fk_assessment_group_quotas_policy FOREIGN KEY (policy_id) REFERENCES assessment_policies (id),
  CONSTRAINT fk_assessment_group_quotas_group FOREIGN KEY (skill_group_id) REFERENCES skill_groups (id),
  CONSTRAINT chk_agq_count CHECK (question_count <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_rank_bands (
  policy_id    SMALLINT UNSIGNED NOT NULL,
  min_correct  TINYINT UNSIGNED  NOT NULL,
  max_correct  TINYINT UNSIGNED  NOT NULL,
  rank_id      TINYINT UNSIGNED  NOT NULL,
  PRIMARY KEY (policy_id, min_correct),
  UNIQUE KEY uq_placement_rank_bands_max (policy_id, max_correct),
  CONSTRAINT fk_placement_rank_bands_policy FOREIGN KEY (policy_id) REFERENCES assessment_policies (id),
  CONSTRAINT fk_placement_rank_bands_rank FOREIGN KEY (rank_id) REFERENCES ranks (id),
  CONSTRAINT chk_prb_range CHECK (min_correct <= max_correct AND max_correct <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reward_policies (
  id           SMALLINT UNSIGNED NOT NULL,
  reward_type  ENUM('topic_first_completion') NOT NULL,
  version      SMALLINT UNSIGNED NOT NULL,
  status       ENUM('provisional','approved','retired') NOT NULL DEFAULT 'provisional',
  is_current   TINYINT UNSIGNED  NULL,
  coin_amount  INT UNSIGNED      NOT NULL,
  description  VARCHAR(255)      NOT NULL,
  created_at   DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  approved_at  DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reward_policies_version (reward_type, version),
  UNIQUE KEY uq_reward_policies_current (reward_type, is_current),
  UNIQUE KEY uq_reward_policies_full (id, reward_type, coin_amount),   -- target for reward_claims FK
  CONSTRAINT chk_rp_current CHECK (is_current = 1),
  CONSTRAINT chk_rp_amount CHECK (coin_amount > 0),
  CONSTRAINT chk_rp_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'retired'),
  CONSTRAINT chk_rp_retired_not_current CHECK (status <> 'retired' OR is_current IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hunter rank earned from topics passed (approved 2026-10-14). Each band maps a
-- range of passed topics to a rank; the rank never decreases (trigger).
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
  UNIQUE KEY uq_rank_progression_bands_rank (policy_id, rank_id),   -- target for rank_history FK
  KEY ix_rank_progression_bands_rank (rank_id),
  CONSTRAINT fk_rank_progression_bands_policy FOREIGN KEY (policy_id) REFERENCES rank_progression_policies (id),
  CONSTRAINT fk_rank_progression_bands_rank FOREIGN KEY (rank_id) REFERENCES ranks (id),
  CONSTRAINT chk_rpb_range CHECK (min_topics_passed <= max_topics_passed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_policies (
  id                       SMALLINT UNSIGNED NOT NULL,
  version                  SMALLINT UNSIGNED NOT NULL,
  status                   ENUM('provisional','approved','retired') NOT NULL DEFAULT 'provisional',
  is_current               TINYINT UNSIGNED  NULL,
  showcase_limit           TINYINT UNSIGNED  NOT NULL,
  recently_active_minutes  SMALLINT UNSIGNED NOT NULL,
  session_idle_minutes     SMALLINT UNSIGNED NOT NULL,
  activity_touch_seconds   SMALLINT UNSIGNED NOT NULL,
  description              VARCHAR(255)      NOT NULL,
  created_at               DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  approved_at              DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_policies_version (version),
  UNIQUE KEY uq_app_policies_current (is_current),
  CONSTRAINT chk_app_current CHECK (is_current = 1),
  CONSTRAINT chk_app_values CHECK (showcase_limit BETWEEN 1 AND 20 AND recently_active_minutes > 0
                                   AND session_idle_minutes > 0 AND activity_touch_seconds > 0),
  CONSTRAINT chk_app_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'retired'),
  CONSTRAINT chk_app_retired_not_current CHECK (status <> 'retired' OR is_current IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Student profile (one per student). This row is also the student's
--    wallet and the lock target that serializes coin, attempt and rank work.
-- -----------------------------------------------------------------------------
CREATE TABLE student_profiles (
  user_id               INT UNSIGNED     NOT NULL,
  user_role             ENUM('student','admin') NOT NULL DEFAULT 'student',
  experience_level_id   TINYINT UNSIGNED NULL,
  experience_chosen_at  DATETIME(3)      NULL,
  onboarding_state      ENUM('not_started','experience_chosen','assessment_submitted') NOT NULL DEFAULT 'not_started',
  placement_attempt_id  INT UNSIGNED     NULL,   -- FK added after attempts / assessment_results exist
  placement_rank_id     TINYINT UNSIGNED NULL,   -- initial placement rank, permanent once set
  current_rank_id       TINYINT UNSIGNED NULL,   -- Hunter rank
  coin_balance          INT UNSIGNED     NOT NULL DEFAULT 0,   -- cache of the ledger, trigger-maintained
  created_at            DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at            DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_student_profiles_placement_attempt (placement_attempt_id),
  KEY ix_student_profiles_current_rank (current_rank_id),
  CONSTRAINT fk_student_profiles_user FOREIGN KEY (user_id, user_role) REFERENCES users (id, role),
  CONSTRAINT fk_student_profiles_experience FOREIGN KEY (experience_level_id) REFERENCES experience_levels (id),
  CONSTRAINT fk_student_profiles_placement_rank FOREIGN KEY (placement_rank_id) REFERENCES ranks (id),
  CONSTRAINT fk_student_profiles_current_rank FOREIGN KEY (current_rank_id) REFERENCES ranks (id),
  CONSTRAINT chk_sp_role CHECK (user_role = 'student'),
  CONSTRAINT chk_sp_experience CHECK ((experience_level_id IS NULL) = (experience_chosen_at IS NULL)),
  CONSTRAINT chk_sp_onboarding CHECK (onboarding_state = 'not_started' OR experience_level_id IS NOT NULL),
  CONSTRAINT chk_sp_placement CHECK ((placement_attempt_id IS NULL) = (placement_rank_id IS NULL)
                                     AND (placement_rank_id IS NULL) = (current_rank_id IS NULL)
                                     AND (onboarding_state = 'assessment_submitted') = (placement_attempt_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Attempts (shared by the placement assessment and topic quizzes)
-- -----------------------------------------------------------------------------
CREATE TABLE attempts (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED      NOT NULL,
  kind            ENUM('assessment','topic_quiz') NOT NULL,
  topic_id        TINYINT UNSIGNED  NULL,           -- required for topic quizzes only
  topic_key       TINYINT UNSIGNED  AS (COALESCE(topic_id, 0)) PERSISTENT,
  attempt_number  SMALLINT UNSIGNED NOT NULL,       -- per student + kind + topic
  status          ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  open_key        TINYINT UNSIGNED  AS (IF(status = 'in_progress', 1, NULL)) PERSISTENT,
  question_count  TINYINT UNSIGNED  NOT NULL,       -- 10 (assessment) or 5 (topic quiz)
  started_via     ENUM('lesson_first','direct') NULL,
  started_at      DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  submitted_at    DATETIME(3)       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempts_number (user_id, kind, topic_key, attempt_number),
  UNIQUE KEY uq_attempts_one_open (user_id, kind, topic_key, open_key),   -- I-07: one in-progress per student per topic
  UNIQUE KEY uq_attempts_id_user (id, user_id),
  UNIQUE KEY uq_attempts_id_user_topic (id, user_id, topic_id),
  UNIQUE KEY uq_attempts_id_kind (id, kind),
  KEY ix_attempts_topic (topic_id),
  CONSTRAINT fk_attempts_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  CONSTRAINT fk_attempts_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT chk_attempts_number CHECK (attempt_number >= 1),
  CONSTRAINT chk_attempts_kind CHECK (
       (kind = 'assessment' AND topic_id IS NULL     AND started_via IS NULL     AND question_count = 10)
    OR (kind = 'topic_quiz' AND topic_id IS NOT NULL AND started_via IS NOT NULL AND question_count = 5)),
  CONSTRAINT chk_attempts_submitted CHECK ((status = 'submitted') = (submitted_at IS NOT NULL)),
  CONSTRAINT chk_attempts_times CHECK (submitted_at IS NULL OR submitted_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assigned question snapshot + the student's response (1:1, so kept together).
CREATE TABLE attempt_questions (
  id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  attempt_id          INT UNSIGNED      NOT NULL,
  position            TINYINT UNSIGNED  NOT NULL,
  question_id         INT UNSIGNED      NOT NULL,   -- source question (for "unseen first" and analytics)
  question_revision   SMALLINT UNSIGNED NOT NULL,   -- revision the snapshot was copied from
  topic_id            TINYINT UNSIGNED  NOT NULL,   -- snapshot of tagging, for per-topic evidence
  skill_group_id      TINYINT UNSIGNED  NOT NULL,   -- snapshot of tagging, for per-group results
  stem                TEXT              NOT NULL,   -- snapshot
  code_snippet        TEXT              NULL,       -- snapshot
  explanation         TEXT              NULL,       -- snapshot
  selected_choice_id  INT UNSIGNED      NULL,       -- FK added below (must be a choice of THIS row)
  answered_at         DATETIME(3)       NULL,
  is_correct          TINYINT(1)        NULL,       -- set by PHP grading inside the submit transaction
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt_questions_position (attempt_id, position),
  UNIQUE KEY uq_attempt_questions_question (attempt_id, question_id),
  KEY ix_attempt_questions_question (question_id),
  CONSTRAINT fk_attempt_questions_attempt FOREIGN KEY (attempt_id) REFERENCES attempts (id),
  CONSTRAINT fk_attempt_questions_question FOREIGN KEY (question_id) REFERENCES questions (id),
  CONSTRAINT fk_attempt_questions_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT fk_attempt_questions_group FOREIGN KEY (skill_group_id) REFERENCES skill_groups (id),
  CONSTRAINT chk_aq_position CHECK (position BETWEEN 1 AND 10),
  CONSTRAINT chk_aq_answered CHECK ((selected_choice_id IS NULL) = (answered_at IS NULL)),
  CONSTRAINT chk_aq_correct CHECK (is_correct IS NULL OR (is_correct IN (0,1) AND selected_choice_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attempt_question_choices (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_question_id  INT UNSIGNED NOT NULL,
  source_choice_id     INT UNSIGNED NOT NULL,
  label                CHAR(1)      NOT NULL,
  choice_text          TEXT         NOT NULL,      -- snapshot
  is_correct           TINYINT(1)   NOT NULL,      -- snapshot; never sent to the browser before submission
  correct_key          TINYINT UNSIGNED AS (IF(is_correct = 1, 1, NULL)) PERSISTENT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aqc_label (attempt_question_id, label),
  UNIQUE KEY uq_aqc_one_correct (attempt_question_id, correct_key),
  UNIQUE KEY uq_aqc_source (attempt_question_id, source_choice_id),
  UNIQUE KEY uq_aqc_id_question (id, attempt_question_id),   -- target for the selected-choice FK
  KEY ix_aqc_source_choice (source_choice_id),
  CONSTRAINT fk_aqc_attempt_question FOREIGN KEY (attempt_question_id) REFERENCES attempt_questions (id),
  CONSTRAINT fk_aqc_source_choice FOREIGN KEY (source_choice_id) REFERENCES question_choices (id),
  CONSTRAINT chk_aqc_label CHECK (label IN ('A','B','C','D','E','F')),
  CONSTRAINT chk_aqc_is_correct CHECK (is_correct IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The selected answer must be one of the choices snapshotted for the same assigned question.
ALTER TABLE attempt_questions
  ADD CONSTRAINT fk_attempt_questions_selected_choice
  FOREIGN KEY (selected_choice_id, id) REFERENCES attempt_question_choices (id, attempt_question_id);

-- -----------------------------------------------------------------------------
-- 7. Results (one row per finalized attempt; the PK makes finalization unique)
-- -----------------------------------------------------------------------------
CREATE TABLE quiz_results (
  attempt_id         INT UNSIGNED      NOT NULL,
  attempt_kind       ENUM('assessment','topic_quiz') NOT NULL DEFAULT 'topic_quiz',
  grading_policy_id  SMALLINT UNSIGNED NOT NULL,
  correct_count      TINYINT UNSIGNED  NOT NULL,
  grade_letter       ENUM('A','B','C','D','E','F') NOT NULL,
  percent            TINYINT UNSIGNED  NOT NULL,
  passed             TINYINT(1)        NOT NULL,
  graded_at          DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (attempt_id),
  KEY ix_quiz_results_policy_band (grading_policy_id, correct_count, grade_letter, percent),
  CONSTRAINT fk_quiz_results_attempt FOREIGN KEY (attempt_id, attempt_kind) REFERENCES attempts (id, kind),
  CONSTRAINT fk_quiz_results_band FOREIGN KEY (grading_policy_id, correct_count, grade_letter, percent)
    REFERENCES quiz_grade_bands (policy_id, correct_count, grade_letter, percent),
  CONSTRAINT chk_qr_kind CHECK (attempt_kind = 'topic_quiz'),
  CONSTRAINT chk_qr_passed CHECK (passed IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_results (
  attempt_id            INT UNSIGNED      NOT NULL,
  attempt_kind          ENUM('assessment','topic_quiz') NOT NULL DEFAULT 'assessment',
  assessment_policy_id  SMALLINT UNSIGNED NOT NULL,
  correct_count         TINYINT UNSIGNED  NOT NULL,
  placement_rank_id     TINYINT UNSIGNED  NOT NULL,
  recommended_topic_id  TINYINT UNSIGNED  NOT NULL,   -- the assessment's own recommendation; never replaced
  offered_topic_id      TINYINT UNSIGNED  NULL,       -- nearest earlier ready topic offered when the recommendation was not ready; NULL = none needed or none ready
  graded_at             DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_assessment_results_rank (attempt_id, placement_rank_id),   -- target for student_profiles FK
  KEY ix_assessment_results_policy (assessment_policy_id),
  KEY ix_assessment_results_offered (offered_topic_id),
  CONSTRAINT fk_assessment_results_attempt FOREIGN KEY (attempt_id, attempt_kind) REFERENCES attempts (id, kind),
  CONSTRAINT fk_assessment_results_policy FOREIGN KEY (assessment_policy_id) REFERENCES assessment_policies (id),
  CONSTRAINT fk_assessment_results_rank FOREIGN KEY (placement_rank_id) REFERENCES ranks (id),
  CONSTRAINT fk_assessment_results_topic FOREIGN KEY (recommended_topic_id) REFERENCES topics (id),
  CONSTRAINT fk_assessment_results_offered FOREIGN KEY (offered_topic_id) REFERENCES topics (id),
  CONSTRAINT chk_ar_kind CHECK (attempt_kind = 'assessment'),
  CONSTRAINT chk_ar_correct CHECK (correct_count <= 10),
  CONSTRAINT chk_ar_offered CHECK (offered_topic_id IS NULL OR offered_topic_id <> recommended_topic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-group results and per-topic evidence are DERIVED from the immutable
-- attempt snapshot (views v_assessment_group_results, v_assessment_topic_evidence).

-- Placement link on the profile: same student, finalized assessment, same rank.
ALTER TABLE student_profiles
  ADD CONSTRAINT fk_student_profiles_placement_attempt
    FOREIGN KEY (placement_attempt_id, user_id) REFERENCES attempts (id, user_id),
  ADD CONSTRAINT fk_student_profiles_placement_result
    FOREIGN KEY (placement_attempt_id, placement_rank_id) REFERENCES assessment_results (attempt_id, placement_rank_id);

-- -----------------------------------------------------------------------------
-- 8. Topic progress (one row per student per topic). Only facts that cannot be
--    derived are stored: when/how the topic was unlocked and lesson visits.
--    Quiz completed / best / passed are DERIVED from attempts + quiz_results
--    (view v_student_topic_status), so they can never disagree with the results.
-- -----------------------------------------------------------------------------
CREATE TABLE student_topic_progress (
  user_id                INT UNSIGNED      NOT NULL,
  topic_id               TINYINT UNSIGNED  NOT NULL,
  unlocked_at            DATETIME(3)       NULL,
  unlock_source          ENUM('placement','passed_previous') NULL,
  first_lesson_visit_at  DATETIME(3)       NULL,
  last_lesson_visit_at   DATETIME(3)       NULL,
  lesson_visit_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at             DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (user_id, topic_id),
  KEY ix_stp_topic (topic_id),
  CONSTRAINT fk_stp_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  CONSTRAINT fk_stp_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT chk_stp_unlock CHECK ((unlocked_at IS NULL) = (unlock_source IS NULL)),
  CONSTRAINT chk_stp_lesson CHECK ((first_lesson_visit_at IS NULL) = (lesson_visit_count = 0)
                                   AND (first_lesson_visit_at IS NULL) = (last_lesson_visit_at IS NULL)
                                   AND (last_lesson_visit_at IS NULL OR last_lesson_visit_at >= first_lesson_visit_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Rank history (append-only). Promotions follow rank_progression_policies:
--    current rank = highest of placement, previously achieved and earned rank.
--    The rank never decreases (triggers).
-- -----------------------------------------------------------------------------
CREATE TABLE rank_history (
  id                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  user_id              INT UNSIGNED     NOT NULL,
  from_rank_id         TINYINT UNSIGNED NULL,      -- NULL only for the initial placement
  to_rank_id           TINYINT UNSIGNED NOT NULL,
  reason               ENUM('placement','promotion','correction') NOT NULL,
  placement_key        TINYINT UNSIGNED AS (IF(reason = 'placement', 1, NULL)) PERSISTENT,
  evidence_attempt_id  INT UNSIGNED     NULL,      -- placement: the assessment; promotion: the passing quiz
  rank_policy_id       SMALLINT UNSIGNED NULL,     -- promotion: rank progression policy version used
  topics_passed        TINYINT UNSIGNED NULL,      -- promotion: distinct topics passed at that moment
  changed_by_admin_id  INT UNSIGNED     NULL,
  changed_by_role      ENUM('student','admin') NOT NULL DEFAULT 'admin',
  note                 VARCHAR(255)     NULL,
  created_at           DATETIME(3)      NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rank_history_one_placement (user_id, placement_key),
  KEY ix_rank_history_user_time (user_id, created_at),
  CONSTRAINT fk_rank_history_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  CONSTRAINT fk_rank_history_from FOREIGN KEY (from_rank_id) REFERENCES ranks (id),
  CONSTRAINT fk_rank_history_to FOREIGN KEY (to_rank_id) REFERENCES ranks (id),
  CONSTRAINT fk_rank_history_evidence FOREIGN KEY (evidence_attempt_id, user_id) REFERENCES attempts (id, user_id),
  CONSTRAINT fk_rank_history_admin FOREIGN KEY (changed_by_admin_id, changed_by_role) REFERENCES users (id, role),
  -- the promoted-to rank must be a band of the cited policy version
  CONSTRAINT fk_rank_history_policy_band FOREIGN KEY (rank_policy_id, to_rank_id) REFERENCES rank_progression_bands (policy_id, rank_id),
  CONSTRAINT chk_rh_role CHECK (changed_by_role = 'admin'),
  CONSTRAINT chk_rh_placement CHECK ((reason = 'placement') = (from_rank_id IS NULL)),
  CONSTRAINT chk_rh_evidence CHECK (reason = 'correction' OR evidence_attempt_id IS NOT NULL),
  CONSTRAINT chk_rh_promotion CHECK ((reason = 'promotion') = (rank_policy_id IS NOT NULL)
                                     AND (rank_policy_id IS NULL) = (topics_passed IS NULL)),
  CONSTRAINT chk_rh_correction CHECK (reason <> 'correction' OR (changed_by_admin_id IS NOT NULL AND note IS NOT NULL)),
  CONSTRAINT chk_rh_change CHECK (from_rank_id IS NULL OR from_rank_id <> to_rank_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Coins: reward claims, badge catalogue/ownership/showcase, ledger
-- -----------------------------------------------------------------------------
CREATE TABLE reward_claims (
  id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED      NOT NULL,
  topic_id          TINYINT UNSIGNED  NOT NULL,
  reward_type       ENUM('topic_first_completion') NOT NULL,
  attempt_id        INT UNSIGNED      NOT NULL,
  reward_policy_id  SMALLINT UNSIGNED NOT NULL,
  coin_amount       INT UNSIGNED      NOT NULL,
  claimed_at        DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_reward_claims_once (user_id, topic_id, reward_type),   -- I-03
  UNIQUE KEY uq_reward_claims_ledger_target (id, user_id, coin_amount),
  KEY ix_reward_claims_attempt (attempt_id, user_id, topic_id),
  KEY ix_reward_claims_policy (reward_policy_id, reward_type, coin_amount),
  CONSTRAINT fk_reward_claims_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  CONSTRAINT fk_reward_claims_topic FOREIGN KEY (topic_id) REFERENCES topics (id),
  CONSTRAINT fk_reward_claims_attempt FOREIGN KEY (attempt_id, user_id, topic_id) REFERENCES attempts (id, user_id, topic_id),
  CONSTRAINT fk_reward_claims_result FOREIGN KEY (attempt_id) REFERENCES quiz_results (attempt_id),
  CONSTRAINT fk_reward_claims_policy FOREIGN KEY (reward_policy_id, reward_type, coin_amount)
    REFERENCES reward_policies (id, reward_type, coin_amount),
  CONSTRAINT chk_rc_amount CHECK (coin_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE badges (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(60)       NOT NULL,
  name          VARCHAR(80)       NOT NULL,
  description   VARCHAR(255)      NOT NULL,
  image_ref     VARCHAR(255)      NOT NULL,
  price_coins   INT UNSIGNED      NOT NULL,     -- current store price; history lives in badge_ownership.price_paid
  price_status  ENUM('provisional','approved') NOT NULL DEFAULT 'provisional',
  status        ENUM('draft','on_sale','off_sale','retired') NOT NULL DEFAULT 'draft',
                -- off_sale: temporarily hidden; retired: permanently withdrawn. Owners keep both and may showcase them.
  created_at    DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  updated_at    DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_badges_slug (slug),
  KEY ix_badges_status (status),
  CONSTRAINT chk_badges_price CHECK (price_coins > 0),
  -- approved 2026-10-14: a badge is published for sale only with an explicitly approved price
  CONSTRAINT chk_badges_sale_needs_approved_price CHECK (status <> 'on_sale' OR price_status = 'approved')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE badge_ownership (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED      NOT NULL,
  badge_id      SMALLINT UNSIGNED NOT NULL,
  price_paid    INT UNSIGNED      NOT NULL,
  purchased_at  DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_badge_ownership_once (user_id, badge_id),            -- I-16
  UNIQUE KEY uq_badge_ownership_ledger_target (id, user_id, price_paid),
  KEY ix_badge_ownership_badge (badge_id),
  CONSTRAINT fk_badge_ownership_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  CONSTRAINT fk_badge_ownership_badge FOREIGN KEY (badge_id) REFERENCES badges (id),
  CONSTRAINT chk_bo_price CHECK (price_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE badge_showcase (
  user_id        INT UNSIGNED      NOT NULL,
  position       TINYINT UNSIGNED  NOT NULL,
  badge_id       SMALLINT UNSIGNED NOT NULL,
  showcased_at   DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (user_id, position),
  UNIQUE KEY uq_badge_showcase_badge (user_id, badge_id),
  KEY ix_badge_showcase_badge (badge_id),
  -- the showcased badge must be owned by THIS student
  CONSTRAINT fk_badge_showcase_ownership FOREIGN KEY (user_id, badge_id) REFERENCES badge_ownership (user_id, badge_id),
  CONSTRAINT chk_bs_position CHECK (position BETWEEN 1 AND 20)   -- structural cap; the policy limit (3) is enforced in PHP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coin_ledger (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id               INT UNSIGNED  NOT NULL,
  direction             ENUM('credit','debit') NOT NULL,
  amount                INT UNSIGNED  NOT NULL,
  signed_amount         INT AS (IF(direction = 'credit', CAST(amount AS SIGNED), -CAST(amount AS SIGNED))) VIRTUAL,
  balance_after         INT UNSIGNED  NOT NULL,     -- running balance; verified by trigger
  entry_type            ENUM('topic_reward','badge_purchase','adjustment') NOT NULL,
  reward_claim_id       INT UNSIGNED  NULL,
  badge_ownership_id    INT UNSIGNED  NULL,
  adjusted_by_admin_id  INT UNSIGNED  NULL,
  adjusted_by_role      ENUM('student','admin') NOT NULL DEFAULT 'admin',
  note                  VARCHAR(255)  NULL,
  created_at            DATETIME(3)   NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_coin_ledger_reward_claim (reward_claim_id),
  UNIQUE KEY uq_coin_ledger_badge_ownership (badge_ownership_id),
  KEY ix_coin_ledger_user (user_id, id),
  KEY ix_coin_ledger_claim_fk (reward_claim_id, user_id, amount),
  KEY ix_coin_ledger_ownership_fk (badge_ownership_id, user_id, amount),
  CONSTRAINT fk_coin_ledger_student FOREIGN KEY (user_id) REFERENCES student_profiles (user_id),
  -- amount and student must match the cause exactly
  CONSTRAINT fk_coin_ledger_reward_claim FOREIGN KEY (reward_claim_id, user_id, amount)
    REFERENCES reward_claims (id, user_id, coin_amount),
  CONSTRAINT fk_coin_ledger_badge_ownership FOREIGN KEY (badge_ownership_id, user_id, amount)
    REFERENCES badge_ownership (id, user_id, price_paid),
  CONSTRAINT fk_coin_ledger_admin FOREIGN KEY (adjusted_by_admin_id, adjusted_by_role) REFERENCES users (id, role),
  CONSTRAINT chk_cl_amount CHECK (amount > 0),
  CONSTRAINT chk_cl_role CHECK (adjusted_by_role = 'admin'),
  CONSTRAINT chk_cl_cause CHECK (
       (entry_type = 'topic_reward'   AND direction = 'credit' AND reward_claim_id IS NOT NULL
        AND badge_ownership_id IS NULL AND adjusted_by_admin_id IS NULL)
    OR (entry_type = 'badge_purchase' AND direction = 'debit'  AND badge_ownership_id IS NOT NULL
        AND reward_claim_id IS NULL AND adjusted_by_admin_id IS NULL)
    OR (entry_type = 'adjustment' AND reward_claim_id IS NULL AND badge_ownership_id IS NULL
        AND adjusted_by_admin_id IS NOT NULL AND note IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Sign-in tracking
-- -----------------------------------------------------------------------------
CREATE TABLE user_sessions (
  id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED      NOT NULL,
  session_ref_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,  -- SHA-256 hex of the session id; never the raw token
  app_policy_id     SMALLINT UNSIGNED NOT NULL,   -- idle-expiry rule in force when the session started
  started_at        DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  last_activity_at  DATETIME(3)       NOT NULL DEFAULT UTC_TIMESTAMP(3),
  ended_at          DATETIME(3)       NULL,
  end_reason        ENUM('logged_out','expired','ended_by_admin') NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_ref (session_ref_hash),
  UNIQUE KEY uq_user_sessions_id_user (id, user_id),
  KEY ix_user_sessions_user_activity (user_id, last_activity_at),
  KEY ix_user_sessions_open (ended_at, last_activity_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_user_sessions_policy FOREIGN KEY (app_policy_id) REFERENCES app_policies (id),
  CONSTRAINT chk_us_ref CHECK (session_ref_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_us_end CHECK ((ended_at IS NULL) = (end_reason IS NULL)),
  CONSTRAINT chk_us_times CHECK (last_activity_at >= started_at AND (ended_at IS NULL OR ended_at >= last_activity_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttling (approved 2026-10-14). Bounded retention, no raw credentials:
-- key_hash = HMAC-SHA256(secret kept outside the web root, 'email:'+normalized email
-- or 'ip:'+client IP). Rows are deleted 24 h after creation at the latest, and as
-- soon as they are idle and unlocked. Not history: the only table PHP deletes from.
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

CREATE TABLE login_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED    NULL,     -- NULL when the identifier matched no account
  outcome      ENUM('success','wrong_password','account_deactivated','unknown_account') NOT NULL,
  session_id   BIGINT UNSIGNED NULL,     -- set for successful logins only
  occurred_at  DATETIME(3)     NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_events_session (session_id),
  KEY ix_login_events_user_time (user_id, occurred_at),
  KEY ix_login_events_user_outcome_time (user_id, outcome, occurred_at),
  KEY ix_login_events_time (occurred_at),
  KEY ix_login_events_session_user (session_id, user_id),
  CONSTRAINT fk_login_events_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_login_events_session FOREIGN KEY (session_id, user_id) REFERENCES user_sessions (id, user_id),
  CONSTRAINT chk_le_identity CHECK ((outcome = 'unknown_account') = (user_id IS NULL)),
  CONSTRAINT chk_le_session CHECK ((outcome = 'success') = (session_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Administrator audit trail (structure for P-33; admin powers not approved)
-- -----------------------------------------------------------------------------
CREATE TABLE audit_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_user_id  INT UNSIGNED    NOT NULL,
  admin_role     ENUM('student','admin') NOT NULL DEFAULT 'admin',
  action         ENUM('create','update','retire','publish','unpublish','adjust_coins',
                      'deactivate_user','reactivate_user','approve_policy','correct_rank') NOT NULL,
  entity_type    ENUM('topic','lesson','question','question_choice','badge','policy','user',
                      'coin_ledger','rank_history') NOT NULL,
  entity_id      BIGINT UNSIGNED NOT NULL,   -- polymorphic reference: no FK by design
  before_data    LONGTEXT        NULL,
  after_data     LONGTEXT        NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT UTC_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_audit_log_entity (entity_type, entity_id, created_at),
  KEY ix_audit_log_admin (admin_user_id, created_at),
  CONSTRAINT fk_audit_log_admin FOREIGN KEY (admin_user_id, admin_role) REFERENCES users (id, role),
  CONSTRAINT chk_al_role CHECK (admin_role = 'admin'),
  CONSTRAINT chk_al_before CHECK (before_data IS NULL OR JSON_VALID(before_data)),
  CONSTRAINT chk_al_after CHECK (after_data IS NULL OR JSON_VALID(after_data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 13. Triggers: rules a FK/CHECK cannot express (state transitions, append-only
--     history, ledger/cache consistency). Error SQLSTATE 45000 for all.
-- =============================================================================
DELIMITER $$

-- ---- student_profiles: permanent placement; balance only moves with the ledger;
--      current rank only moves with rank_history.
CREATE TRIGGER trg_student_profiles_bi BEFORE INSERT ON student_profiles FOR EACH ROW
BEGIN
  IF NEW.coin_balance <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: a new wallet starts at 0; coins change only through coin_ledger';
  END IF;
  IF NEW.placement_attempt_id IS NOT NULL OR NEW.current_rank_id IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'student_profiles: placement and rank are written by assessment submission, not at creation';
  END IF;
END$$

CREATE TRIGGER trg_student_profiles_bu BEFORE UPDATE ON student_profiles FOR EACH ROW
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

-- ---- attempts: immutable identity; finalize once; finalize only when complete
CREATE TRIGGER trg_attempts_bu BEFORE UPDATE ON attempts FOR EACH ROW
BEGIN
  DECLARE v_assigned INT DEFAULT 0;
  DECLARE v_answered INT DEFAULT 0;
  DECLARE v_graded INT DEFAULT 0;
  DECLARE v_correct INT DEFAULT 0;
  DECLARE v_result_correct INT DEFAULT NULL;
  IF OLD.status = 'submitted' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: this attempt is already finalized and cannot change (I-02)';
  END IF;
  IF NEW.user_id <> OLD.user_id OR NEW.kind <> OLD.kind OR NOT (NEW.topic_id <=> OLD.topic_id)
     OR NEW.attempt_number <> OLD.attempt_number OR NEW.question_count <> OLD.question_count
     OR NOT (NEW.started_via <=> OLD.started_via) OR NEW.started_at <> OLD.started_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: identity columns are immutable';
  END IF;
  IF NEW.status = 'submitted' THEN
    SELECT COUNT(*), COUNT(selected_choice_id), COUNT(is_correct), COALESCE(SUM(is_correct), 0)
      INTO v_assigned, v_answered, v_graded, v_correct
      FROM attempt_questions WHERE attempt_id = NEW.id;
    IF v_assigned <> NEW.question_count OR v_answered <> NEW.question_count OR v_graded <> NEW.question_count THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: cannot finalize until every assigned question is present, answered and graded (I-05)';
    END IF;
    IF NEW.kind = 'topic_quiz' THEN
      SELECT (SELECT correct_count FROM quiz_results WHERE attempt_id = NEW.id) INTO v_result_correct;
    ELSE
      SELECT (SELECT correct_count FROM assessment_results WHERE attempt_id = NEW.id) INTO v_result_correct;
    END IF;
    IF v_result_correct IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: cannot finalize without its result row';
    END IF;
    IF v_result_correct <> v_correct THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: result correct_count does not match the graded answers';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_attempts_bd BEFORE DELETE ON attempts FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempts: attempts are never deleted'$$

-- ---- attempt_questions: assigned only while open, from an eligible active question
CREATE TRIGGER trg_attempt_questions_bi BEFORE INSERT ON attempt_questions FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20) DEFAULT NULL;
  DECLARE v_kind VARCHAR(20) DEFAULT NULL;
  DECLARE v_topic TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_count TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_q_status VARCHAR(20) DEFAULT NULL;
  DECLARE v_q_topic TINYINT UNSIGNED DEFAULT NULL;
  DECLARE v_q_quiz TINYINT DEFAULT NULL;
  DECLARE v_q_assess TINYINT DEFAULT NULL;
  SELECT status, kind, topic_id, question_count INTO v_status, v_kind, v_topic, v_count
    FROM attempts WHERE id = NEW.attempt_id;
  IF v_status IS NOT NULL THEN
    IF v_status <> 'in_progress' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: questions can only be assigned to an in-progress attempt';
    END IF;
    IF NEW.position > v_count THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: position exceeds the attempt question count';
    END IF;
    IF NEW.selected_choice_id IS NOT NULL OR NEW.is_correct IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: assigned questions start unanswered and ungraded';
    END IF;
    SELECT status, topic_id, for_quiz, for_assessment INTO v_q_status, v_q_topic, v_q_quiz, v_q_assess
      FROM questions WHERE id = NEW.question_id;
    IF v_q_status IS NOT NULL THEN
      IF v_q_status <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: only active questions can be assigned';
      END IF;
      IF (v_kind = 'topic_quiz' AND (v_q_quiz <> 1 OR v_q_topic <> v_topic)) OR (v_kind = 'assessment' AND v_q_assess <> 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: question is not eligible for this attempt';
      END IF;
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_attempt_questions_bu BEFORE UPDATE ON attempt_questions FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20) DEFAULT NULL;
  IF NEW.attempt_id <> OLD.attempt_id OR NEW.position <> OLD.position OR NEW.question_id <> OLD.question_id
     OR NEW.question_revision <> OLD.question_revision OR NEW.topic_id <> OLD.topic_id
     OR NEW.skill_group_id <> OLD.skill_group_id OR NOT (NEW.stem <=> OLD.stem)
     OR NOT (NEW.code_snippet <=> OLD.code_snippet) OR NOT (NEW.explanation <=> OLD.explanation) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: the question snapshot is immutable';
  END IF;
  SELECT status INTO v_status FROM attempts WHERE id = OLD.attempt_id;
  IF v_status <> 'in_progress' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: answers are locked after submission';
  END IF;
END$$

CREATE TRIGGER trg_attempt_questions_bd BEFORE DELETE ON attempt_questions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_questions: assigned questions are never deleted'$$

-- ---- attempt_question_choices: written once while the attempt is open
CREATE TRIGGER trg_aqc_bi BEFORE INSERT ON attempt_question_choices FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20) DEFAULT NULL;
  DECLARE v_selected INT UNSIGNED DEFAULT NULL;
  SELECT a.status, aq.selected_choice_id INTO v_status, v_selected
    FROM attempt_questions aq JOIN attempts a ON a.id = aq.attempt_id
   WHERE aq.id = NEW.attempt_question_id;
  IF v_status IS NOT NULL AND (v_status <> 'in_progress' OR v_selected IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_question_choices: choices are snapshotted only before answering';
  END IF;
END$$

CREATE TRIGGER trg_aqc_bu BEFORE UPDATE ON attempt_question_choices FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_question_choices: snapshots are immutable'$$
CREATE TRIGGER trg_aqc_bd BEFORE DELETE ON attempt_question_choices FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attempt_question_choices: snapshots are never deleted'$$

-- ---- results: written only while the attempt is still open, then immutable
CREATE TRIGGER trg_quiz_results_bi BEFORE INSERT ON quiz_results FOR EACH ROW
BEGIN
  IF (SELECT status FROM attempts WHERE id = NEW.attempt_id) <> 'in_progress' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quiz_results: attempt already finalized (I-02)';
  END IF;
END$$
CREATE TRIGGER trg_quiz_results_bu BEFORE UPDATE ON quiz_results FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quiz_results: results are immutable'$$
CREATE TRIGGER trg_quiz_results_bd BEFORE DELETE ON quiz_results FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quiz_results: results are never deleted'$$

CREATE TRIGGER trg_assessment_results_bi BEFORE INSERT ON assessment_results FOR EACH ROW
BEGIN
  IF (SELECT status FROM attempts WHERE id = NEW.attempt_id) <> 'in_progress' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_results: attempt already finalized (I-10)';
  END IF;
END$$
CREATE TRIGGER trg_assessment_results_bu BEFORE UPDATE ON assessment_results FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_results: results are immutable'$$
CREATE TRIGGER trg_assessment_results_bd BEFORE DELETE ON assessment_results FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment_results: results are never deleted'$$

-- ---- rank_history: chain from the current rank; append-only
CREATE TRIGGER trg_rank_history_bi BEFORE INSERT ON rank_history FOR EACH ROW
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
CREATE TRIGGER trg_rank_history_bu BEFORE UPDATE ON rank_history FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history is append-only'$$
CREATE TRIGGER trg_rank_history_bd BEFORE DELETE ON rank_history FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rank_history is append-only'$$

-- ---- reward_claims / badge_ownership: immutable once written
CREATE TRIGGER trg_reward_claims_bu BEFORE UPDATE ON reward_claims FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reward_claims are immutable'$$
CREATE TRIGGER trg_reward_claims_bd BEFORE DELETE ON reward_claims FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reward_claims are never deleted'$$
CREATE TRIGGER trg_badge_ownership_bu BEFORE UPDATE ON badge_ownership FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'badge_ownership is immutable (price paid is preserved)'$$
CREATE TRIGGER trg_badge_ownership_bd BEFORE DELETE ON badge_ownership FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'badge_ownership is never deleted'$$

-- ---- coin_ledger: running balance must chain from the locked wallet row;
--      never negative; the wallet cache is updated from the ledger; append-only.
CREATE TRIGGER trg_coin_ledger_bi BEFORE INSERT ON coin_ledger FOR EACH ROW
BEGIN
  DECLARE v_balance INT UNSIGNED DEFAULT NULL;
  DECLARE v_new BIGINT DEFAULT NULL;
  SELECT coin_balance INTO v_balance FROM student_profiles WHERE user_id = NEW.user_id FOR UPDATE;
  IF v_balance IS NOT NULL THEN
    SET v_new = CAST(v_balance AS SIGNED)
              + IF(NEW.direction = 'credit', CAST(NEW.amount AS SIGNED), -CAST(NEW.amount AS SIGNED));
    IF v_new < 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coin_ledger: insufficient coins; the balance cannot go negative (I-13)';
    END IF;
    IF NEW.balance_after <> v_new THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coin_ledger: balance_after must equal the current balance plus this entry';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_coin_ledger_ai AFTER INSERT ON coin_ledger FOR EACH ROW
  UPDATE student_profiles SET coin_balance = NEW.balance_after, updated_at = UTC_TIMESTAMP(3)
   WHERE user_id = NEW.user_id$$

CREATE TRIGGER trg_coin_ledger_bu BEFORE UPDATE ON coin_ledger FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coin_ledger is append-only; add a correcting entry instead'$$
CREATE TRIGGER trg_coin_ledger_bd BEFORE DELETE ON coin_ledger FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coin_ledger is append-only; add a correcting entry instead'$$

-- ---- sessions: only activity moves forward, and the end is written once
CREATE TRIGGER trg_user_sessions_bu BEFORE UPDATE ON user_sessions FOR EACH ROW
BEGIN
  IF NEW.user_id <> OLD.user_id OR NEW.session_ref_hash <> OLD.session_ref_hash
     OR NEW.started_at <> OLD.started_at OR NEW.app_policy_id <> OLD.app_policy_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_sessions: identity columns are immutable';
  END IF;
  IF OLD.ended_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_sessions: an ended session is final';
  END IF;
  IF NEW.last_activity_at < OLD.last_activity_at THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_sessions: last activity cannot move backwards';
  END IF;
END$$
CREATE TRIGGER trg_user_sessions_bd BEFORE DELETE ON user_sessions FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'user_sessions are kept for login history'$$

-- ---- badges: changing the price withdraws approval (approved 2026-10-14). The
--      CHECK above then keeps the badge off sale until the price is re-approved.
CREATE TRIGGER trg_badges_bu BEFORE UPDATE ON badges FOR EACH ROW
BEGIN
  IF NEW.price_coins <> OLD.price_coins AND NEW.price_status = 'approved' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'badges: a price change requires renewed approval (set price_status = provisional)';
  END IF;
END$$

-- ---- login_events / audit_log: append-only
CREATE TRIGGER trg_login_events_bu BEFORE UPDATE ON login_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'login_events is append-only'$$
CREATE TRIGGER trg_login_events_bd BEFORE DELETE ON login_events FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'login_events is append-only'$$
CREATE TRIGGER trg_audit_log_bu BEFORE UPDATE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'$$
CREATE TRIGGER trg_audit_log_bd BEFORE DELETE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'$$

DELIMITER ;

-- =============================================================================
-- 14. Views (read helpers; they hold no data)
-- =============================================================================

-- Student-facing choices for an attempt: no correct-answer column (C-28).
CREATE VIEW v_attempt_choices_public AS
SELECT c.id, c.attempt_question_id, c.label, c.choice_text
  FROM attempt_question_choices c;

-- Content readiness (approved 2026-10-14): a topic is ready when it is marked
-- available, its lesson is published and it has at least min_pool_size (10)
-- active, VALID quiz questions (>= 2 choices and exactly one correct choice).
CREATE VIEW v_topic_readiness AS
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

-- Assessment results per skill group, derived from the immutable snapshot (S1).
CREATE VIEW v_assessment_group_results AS
SELECT r.attempt_id,
       g.id AS skill_group_id,
       g.code AS skill_group_code,
       (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.skill_group_id = g.id) AS asked_count,
       (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.skill_group_id = g.id) AS correct_count
  FROM assessment_results r CROSS JOIN skill_groups g;

-- Per-topic evidence for every topic, derived from the snapshot (S1).
-- evidence_label never claims mastery: 'not_assessed' or 'sampled'.
CREATE VIEW v_assessment_topic_evidence AS
SELECT x.attempt_id, x.topic_id, x.position, x.asked_count, x.correct_count,
       IF(x.asked_count = 0, 'not_assessed', 'sampled') AS evidence_label
  FROM (SELECT r.attempt_id, t.id AS topic_id, t.position,
               (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.topic_id = t.id) AS asked_count,
               (SELECT COALESCE(SUM(aq.is_correct), 0) FROM attempt_questions aq WHERE aq.attempt_id = r.attempt_id AND aq.topic_id = t.id) AS correct_count
          FROM assessment_results r CROSS JOIN topics t) x;

-- Per student and topic: stored unlock/lesson facts + quiz statistics derived
-- from attempts and quiz_results (S2), and the display status.
CREATE VIEW v_student_topic_status AS
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

-- Distinct topics passed per student (rank progression and admin progress, P-36).
CREATE VIEW v_student_topics_passed AS
SELECT sp.user_id,
       (SELECT COUNT(DISTINCT a.topic_id) FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
         WHERE a.user_id = sp.user_id AND r.passed = 1) AS topics_passed
  FROM student_profiles sp;

-- Wallet reconciliation: cached balance vs ledger sum vs last running balance.
CREATE VIEW v_wallet_reconciliation AS
SELECT sp.user_id,
       sp.coin_balance AS cached_balance,
       COALESCE((SELECT SUM(l.signed_amount) FROM coin_ledger l WHERE l.user_id = sp.user_id), 0) AS ledger_sum,
       COALESCE((SELECT l.balance_after FROM coin_ledger l WHERE l.user_id = sp.user_id ORDER BY l.id DESC LIMIT 1), 0) AS last_balance_after,
       (SELECT COUNT(*) FROM coin_ledger l WHERE l.user_id = sp.user_id) AS entry_count
  FROM student_profiles sp;

-- Administrator overview (C-21). "Recently active" uses the current app policy window.
CREATE VIEW v_admin_student_overview AS
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

-- =============================================================================
-- 15. Mark the schema as installed
-- =============================================================================
INSERT INTO schema_info (id, schema_version) VALUES (1, '2026.10.14-3');
