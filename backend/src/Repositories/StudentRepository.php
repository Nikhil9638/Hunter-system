<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Student profile, experience levels and read models for the dashboard and topic list.
 * Grants: SELECT experience_levels, ranks, skill_groups, topics, rank_progression_*,
 * v_topic_readiness, v_student_topic_status, v_student_topics_passed;
 * UPDATE(experience_level_id, experience_chosen_at, onboarding_state, ...) student_profiles.
 */
final class StudentRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Lock the student's profile row: step 1 of the lock order for every student write. */
    public function lockProfile(int $userId): ?array
    {
        return $this->db->one(
            'SELECT user_id, onboarding_state, experience_level_id, placement_attempt_id, placement_rank_id,
                    current_rank_id, coin_balance
               FROM student_profiles WHERE user_id = ? FOR UPDATE', [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function experienceLevels(): array
    {
        return $this->db->all('SELECT id, code, label FROM experience_levels ORDER BY sort_order');
    }

    public function experienceLevelExists(int $id): bool
    {
        return $this->db->one('SELECT 1 AS x FROM experience_levels WHERE id = ?', [$id]) !== null;
    }

    public function setExperience(int $userId, int $levelId): void
    {
        $this->db->run(
            "UPDATE student_profiles
                SET experience_level_id = ?, experience_chosen_at = UTC_TIMESTAMP(3),
                    onboarding_state = 'experience_chosen', updated_at = UTC_TIMESTAMP(3)
              WHERE user_id = ? AND onboarding_state = 'not_started'", [$levelId, $userId]);
    }

    /** @return array<string,mixed>|null profile with ranks and experience, explicit columns */
    public function profileSummary(int $userId): ?array
    {
        return $this->db->one(
            'SELECT u.display_name, p.onboarding_state, p.coin_balance, p.placement_attempt_id,
                    el.code AS exp_code, el.label AS exp_label,
                    pr.code AS placement_code, pr.display_name AS placement_name, pr.title AS placement_title,
                    cr.code AS current_code, cr.display_name AS current_name, cr.title AS current_title, cr.sort_order AS current_sort
               FROM student_profiles p
               JOIN users u ON u.id = p.user_id
               LEFT JOIN experience_levels el ON el.id = p.experience_level_id
               LEFT JOIN ranks pr ON pr.id = p.placement_rank_id
               LEFT JOIN ranks cr ON cr.id = p.current_rank_id
              WHERE p.user_id = ?', [$userId]);
    }

    public function topicsPassed(int $userId): int
    {
        return (int)($this->db->one('SELECT topics_passed FROM v_student_topics_passed WHERE user_id = ?', [$userId])['topics_passed'] ?? 0);
    }

    public function topicCount(): int
    {
        return (int)$this->db->one('SELECT COUNT(*) AS n FROM topics')['n'];
    }

    /** Next higher rank band of the current rank progression policy, or null at the top. */
    public function nextRankBand(int $currentSort): ?array
    {
        return $this->db->one(
            'SELECT r.code, r.display_name, b.min_topics_passed
               FROM rank_progression_bands b
               JOIN rank_progression_policies pol ON pol.id = b.policy_id AND pol.is_current = 1
               JOIN ranks r ON r.id = b.rank_id
              WHERE r.sort_order > ?
              ORDER BY r.sort_order LIMIT 1', [$currentSort]);
    }

    /**
     * All topics in curriculum order with content readiness (shared) and this student's
     * availability and derived status (null columns before placement).
     *
     * @return list<array<string,mixed>>
     */
    public function topicList(int $userId): array
    {
        return $this->db->all(
            'SELECT t.id, t.position, t.title, g.code AS skill_group,
                    r.is_ready, r.lesson_published, r.quiz_pool_size,
                    s.unlocked_at, s.unlock_source, s.first_lesson_visit_at, s.completed_attempts,
                    s.best_correct_count, s.first_passed_at, s.open_attempt_id, s.display_status
               FROM topics t
               JOIN skill_groups g ON g.id = t.skill_group_id
               JOIN v_topic_readiness r ON r.topic_id = t.id
               LEFT JOIN v_student_topic_status s ON s.topic_id = t.id AND s.user_id = ?
              ORDER BY t.position', [$userId]);
    }

    /** @return array<string,mixed>|null id, position, title, ready */
    public function topicRef(?int $topicId): ?array
    {
        if ($topicId === null) {
            return null;
        }
        return $this->db->one(
            'SELECT t.id, t.position, t.title, r.is_ready FROM topics t JOIN v_topic_readiness r ON r.topic_id = t.id WHERE t.id = ?', [$topicId]);
    }
}
