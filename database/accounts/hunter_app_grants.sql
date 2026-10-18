-- =============================================================================
-- Hunter System application account - least-privilege grants
-- Milestone 1 (authentication, sessions, login throttling, admin student list),
-- Milestone 2 (onboarding, placement assessment, dashboard, topic list),
-- Milestone 3 (lessons, topic quizzes, rewards, progression, rank promotion),
-- Milestone 4 (badge store, purchases, own profile, showcase) and
-- Milestone 5 (administrator content management, audit history).
--
-- Applied by backend/bin/setup-db-account.php AFTER "REVOKE ALL PRIVILEGES,
-- GRANT OPTION" so the account holds exactly these privileges. {{DB}} and
-- {{ACCOUNT}} are substituted by the script. No passwords in this file.
--
-- Not granted (by design): CREATE, ALTER, DROP, INDEX, TRIGGER, REFERENCES,
-- CREATE VIEW, EXECUTE, GRANT OPTION, FILE, any global privilege, any access to
-- other databases, and DELETE on anything except login_throttle, badge_showcase
-- and question_choices (unused choices only; enforced by the snapshot FK).
-- Later milestones add rows here (and re-run the script) as endpoints need them.
-- =============================================================================
GRANT SELECT ON {{DB}}.schema_info TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.app_policies TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.users TO {{ACCOUNT}};
GRANT UPDATE (password_hash, updated_at) ON {{DB}}.users TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.student_profiles TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.user_sessions TO {{ACCOUNT}};
GRANT UPDATE (last_activity_at, ended_at, end_reason) ON {{DB}}.user_sessions TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.login_events TO {{ACCOUNT}};
GRANT SELECT, INSERT, UPDATE, DELETE ON {{DB}}.login_throttle TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_admin_student_overview TO {{ACCOUNT}};

-- ---- Milestone 2: onboarding, placement assessment, dashboard, topic list ----
-- reference data and policies (read only)
GRANT SELECT ON {{DB}}.experience_levels TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.ranks TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.skill_groups TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.topics TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.questions TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.question_choices TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.assessment_policies TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.assessment_group_quotas TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.placement_rank_bands TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.rank_progression_policies TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.rank_progression_bands TO {{ACCOUNT}};
-- onboarding + placement on the profile (never coin_balance: coins change only via the ledger)
GRANT UPDATE (experience_level_id, experience_chosen_at, onboarding_state, placement_attempt_id,
              placement_rank_id, current_rank_id, updated_at) ON {{DB}}.student_profiles TO {{ACCOUNT}};
-- attempts and snapshots (answers and grading only; snapshot text is never updatable)
GRANT SELECT, INSERT ON {{DB}}.attempts TO {{ACCOUNT}};
GRANT UPDATE (status, submitted_at) ON {{DB}}.attempts TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.attempt_questions TO {{ACCOUNT}};
GRANT UPDATE (selected_choice_id, answered_at, is_correct) ON {{DB}}.attempt_questions TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.attempt_question_choices TO {{ACCOUNT}};
-- results, placement rank history, topic availability
GRANT SELECT, INSERT ON {{DB}}.assessment_results TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.rank_history TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.student_topic_progress TO {{ACCOUNT}};
-- derived read models
GRANT SELECT ON {{DB}}.v_attempt_choices_public TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_topic_readiness TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_assessment_group_results TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_assessment_topic_evidence TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_student_topic_status TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.v_student_topics_passed TO {{ACCOUNT}};

-- ---- Milestone 3: lessons, topic quizzes, rewards, progression, rank ----
-- lesson text and grading/reward policies (read only)
GRANT SELECT ON {{DB}}.lessons TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.quiz_grading_policies TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.quiz_grade_bands TO {{ACCOUNT}};
GRANT SELECT ON {{DB}}.reward_policies TO {{ACCOUNT}};
-- quiz results, first-completion claims and their ledger credits (append-only by trigger)
GRANT SELECT, INSERT ON {{DB}}.quiz_results TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.reward_claims TO {{ACCOUNT}};
GRANT SELECT, INSERT ON {{DB}}.coin_ledger TO {{ACCOUNT}};
-- next-topic unlock and lesson visits (never re-locks: the service only sets a NULL unlocked_at)
GRANT UPDATE (unlocked_at, unlock_source, first_lesson_visit_at, last_lesson_visit_at,
              lesson_visit_count, updated_at) ON {{DB}}.student_topic_progress TO {{ACCOUNT}};

-- ---- Milestone 4: badge store, purchases, own profile and showcase ----
-- catalogue read (prices and availability are read inside the purchase transaction)
GRANT SELECT ON {{DB}}.badges TO {{ACCOUNT}};
-- ownership is append-only (no UPDATE/DELETE; triggers also refuse them); the price paid is kept
GRANT SELECT, INSERT ON {{DB}}.badge_ownership TO {{ACCOUNT}};
-- the showcase is replaced as a whole inside one transaction
GRANT SELECT, INSERT, DELETE ON {{DB}}.badge_showcase TO {{ACCOUNT}};

-- ---- Milestone 5: administrator content management and audit history ----
-- topics: status and summary only (curriculum order, slug, title and skill group stay fixed)
GRANT UPDATE (summary, status, updated_at) ON {{DB}}.topics TO {{ACCOUNT}};
-- lessons and questions: content columns; history rows are never deleted
GRANT INSERT ON {{DB}}.lessons TO {{ACCOUNT}};
GRANT UPDATE (title, body, status, revision, published_at, updated_at) ON {{DB}}.lessons TO {{ACCOUNT}};
GRANT INSERT ON {{DB}}.questions TO {{ACCOUNT}};
GRANT UPDATE (topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status, revision,
              updated_at, retired_at) ON {{DB}}.questions TO {{ACCOUNT}};
-- choices: removing an UNUSED choice of an edited question (the snapshot FK refuses a used one)
GRANT INSERT, DELETE ON {{DB}}.question_choices TO {{ACCOUNT}};
GRANT UPDATE (choice_text, is_correct) ON {{DB}}.question_choices TO {{ACCOUNT}};
-- badge catalogue (slug immutable; ownership, price_paid and showcases are never written here)
GRANT INSERT ON {{DB}}.badges TO {{ACCOUNT}};
GRANT UPDATE (name, description, image_ref, price_coins, price_status, status, updated_at) ON {{DB}}.badges TO {{ACCOUNT}};
-- append-only audit history (UPDATE/DELETE refused by triggers and not granted)
GRANT SELECT, INSERT ON {{DB}}.audit_log TO {{ACCOUNT}};
