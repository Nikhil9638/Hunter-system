<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Http\ApiError;
use Hunter\Repositories\AssessmentRepository;
use Hunter\Repositories\StudentRepository;
use Hunter\Support\Time;

/**
 * Student dashboard and topic list (api-contract.md §5). Only supported, stored or
 * derived facts are returned. "Unlocked" (availability for this student) and
 * "content_ready" (lesson + valid quiz pool) are separate fields.
 */
final class DashboardService
{
    public function __construct(private StudentRepository $students, private AssessmentRepository $assessments)
    {
    }

    public function dashboard(int $userId): array
    {
        $p = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        $passed = $this->students->topicsPassed($userId);

        $assessment = ['state' => 'not_started', 'attempt_id' => null, 'can_start' => $p['onboarding_state'] === 'experience_chosen'];
        $recommended = $offered = null;
        if ($p['placement_attempt_id'] !== null) {
            $assessment = ['state' => 'submitted', 'attempt_id' => (int)$p['placement_attempt_id'], 'can_start' => false];
            $r = $this->assessments->result((int)$p['placement_attempt_id']);
            $recommended = $this->ref((int)$r['recommended_topic_id']);
            $offered = $r['offered_topic_id'] === null ? null : $this->ref((int)$r['offered_topic_id']);
        } elseif (($open = $this->assessments->openAttemptId($userId)) !== null) {
            $assessment = ['state' => 'in_progress', 'attempt_id' => $open, 'can_start' => true];
        }

        $current = null;
        foreach ($this->students->topicList($userId) as $t) {
            if ($t['unlocked_at'] !== null && $t['first_passed_at'] === null) {
                $current = ['id' => (int)$t['id'], 'title' => $t['title'], 'content_ready' => (int)$t['is_ready'] === 1];
                break;
            }
        }

        $next = null;
        if ($p['current_sort'] !== null) {
            $band = $this->students->nextRankBand((int)$p['current_sort']);
            if ($band !== null) {
                $next = ['code' => $band['code'], 'name' => $band['display_name'],
                         'topics_required' => (int)$band['min_topics_passed'],
                         'topics_remaining' => max(0, (int)$band['min_topics_passed'] - $passed)];
            }
        }

        return [
            'display_name' => $p['display_name'],
            'onboarding_state' => $p['onboarding_state'],
            'experience_level' => $p['exp_code'] === null ? null : ['code' => $p['exp_code'], 'label' => $p['exp_label']],
            'placement_rank' => $p['placement_code'] === null ? null : ['code' => $p['placement_code'], 'name' => $p['placement_name'], 'title' => $p['placement_title']],
            'hunter_rank' => $p['current_code'] === null ? null : ['code' => $p['current_code'], 'name' => $p['current_name'], 'title' => $p['current_title']],
            'assessment' => $assessment,
            'recommended_topic' => $recommended,
            'offered_topic' => $offered,
            'current_topic' => $current,
            'coins' => (int)$p['coin_balance'],
            'topics_passed' => $passed,
            'topics_total' => $this->students->topicCount(),
            'next_rank' => $next,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function topics(int $userId): array
    {
        return array_map(static fn(array $t): array => [
            'id' => (int)$t['id'],
            'position' => (int)$t['position'],
            'title' => $t['title'],
            'skill_group' => $t['skill_group'],
            'unlocked' => $t['unlocked_at'] !== null,                     // availability for this student
            'unlock_source' => $t['unlock_source'],
            'content_ready' => (int)$t['is_ready'] === 1,                 // lesson published + >= 10 valid quiz questions
            'status' => $t['display_status'] ?? 'locked',                 // locked | available | in_progress | completed | passed
            'lesson_visited' => $t['first_lesson_visit_at'] !== null,
            'completed_attempts' => (int)($t['completed_attempts'] ?? 0),
            'best_correct' => $t['best_correct_count'] === null ? null : (int)$t['best_correct_count'],
            'first_passed_at' => Time::iso($t['first_passed_at']),
            'open_attempt_id' => $t['open_attempt_id'] === null ? null : (int)$t['open_attempt_id'],
        ], $this->students->topicList($userId));
    }

    private function ref(int $topicId): ?array
    {
        $t = $this->students->topicRef($topicId);
        return $t === null ? null : ['id' => (int)$t['id'], 'position' => (int)$t['position'], 'title' => $t['title'], 'ready' => (int)$t['is_ready'] === 1];
    }
}
