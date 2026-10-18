<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\AssessmentRepository;
use Hunter\Repositories\StudentRepository;
use Hunter\Support\Time;
use PDOException;

/**
 * Placement assessment (A-03, O-08). Transactions follow the lock order of
 * database-design.md §11: (1) student_profiles row, (2) attempts row, (3) others.
 * Grading is done in PHP from the attempt's snapshot; the database triggers are
 * the backstop (finalize once, complete before finalize, frozen answers).
 * The assessment never awards coins (A-02).
 */
final class AssessmentService
{
    public const LABEL = 'Starting point from a short placement check, not proof of mastery';

    public function __construct(
        private Database $db,
        private AssessmentRepository $repo,
        private StudentRepository $students,
    ) {
    }

    /** Start or resume. @return array{attempt:array, created:bool} */
    public function start(int $userId): array
    {
        try {
            [$attemptId, $created] = $this->db->tx(function () use ($userId): array {
                $profile = $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
                if ($profile['placement_attempt_id'] !== null) {
                    throw new ApiError(409, 'placement_already_recorded', 'Your placement assessment is already complete.');
                }
                if ($profile['onboarding_state'] !== 'experience_chosen') {
                    throw new ApiError(409, 'choose_experience_first', 'Choose your experience level first.');
                }
                $open = $this->repo->openAttemptId($userId);
                if ($open !== null) {
                    return [$open, false];                                      // resume, same attempt
                }
                $policy = $this->repo->currentPolicy() ?? throw new ApiError(503, 'assessment_unavailable', 'The assessment is not available.');
                $picked = $this->pickQuestions((int)$policy['id'], (int)$policy['question_count']);
                $attemptId = $this->repo->insertAttempt($userId, $this->repo->nextAttemptNumber($userId), (int)$policy['question_count']);
                foreach ($picked as $i => $q) {
                    $this->repo->snapshotQuestion($attemptId, $i + 1, $q);
                }
                return [$attemptId, true];
            });
        } catch (PDOException $e) {
            // A concurrent start that slipped past the lock is stopped by the unique
            // open-attempt key; resume the winner's attempt.
            $open = Database::isDuplicateKey($e) ? $this->repo->openAttemptId($userId) : null;
            if ($open === null) {
                throw $e;
            }
            [$attemptId, $created] = [$open, false];
        }
        return ['attempt' => $this->attemptView($userId, $attemptId), 'created' => $created];
    }

    /**
     * Exactly the policy quota per skill group (2/2/2/2/1/1), random within a group,
     * distinct, valid questions only; positions ordered by group.
     *
     * @return list<array<string,mixed>>
     */
    private function pickQuestions(int $policyId, int $questionCount): array
    {
        $byGroup = [];
        foreach ($this->repo->validPool() as $q) {
            $byGroup[(int)$q['skill_group_id']][] = $q;
        }
        $picked = [];
        foreach ($this->repo->quotas($policyId) as $quota) {
            $pool = $byGroup[$quota['skill_group_id']] ?? [];
            if (count($pool) < $quota['question_count']) {
                throw new ApiError(503, 'assessment_unavailable', 'The assessment question pool is incomplete.');
            }
            for ($i = count($pool) - 1; $i > 0; $i--) {                       // Fisher-Yates with a CSPRNG
                $j = random_int(0, $i);
                [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            }
            array_push($picked, ...array_slice($pool, 0, $quota['question_count']));
        }
        if (count($picked) !== $questionCount || count(array_unique(array_column($picked, 'id'))) !== $questionCount) {
            throw new ApiError(503, 'assessment_unavailable', 'The assessment layout does not match the policy.');
        }
        return $picked;
    }

    /** Save or change one answer. Lock order: profile, attempt. */
    public function answer(int $userId, int $attemptId, int $position, array $input): array
    {
        $choiceId = $input['choice_id'] ?? null;
        if (!is_int($choiceId) || $choiceId < 1) {
            throw ApiError::validation(['choice_id' => $choiceId === null ? 'required' : 'invalid']);
        }
        return $this->db->tx(function () use ($userId, $attemptId, $position, $choiceId): array {
            $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            $attempt = $this->repo->lockAttempt($attemptId, $userId) ?? throw ApiError::notFound();
            if ($attempt['status'] !== 'in_progress') {
                throw new ApiError(409, 'attempt_finalized', 'This assessment has already been submitted; answers can no longer change.');
            }
            $aqId = $this->repo->assignedQuestionId($attemptId, $position) ?? throw ApiError::notFound();
            if (!$this->repo->choiceBelongsTo($choiceId, $aqId)) {
                throw new ApiError(400, 'invalid_choice', 'That choice does not belong to this question.');
            }
            $this->repo->saveAnswer($aqId, $choiceId);
            return ['position' => $position, 'selected_choice_id' => $choiceId];
        });
    }

    /** Final submission: grade, place, recommend, unlock, all in one transaction. Idempotent. */
    public function submit(int $userId, int $attemptId): array
    {
        $already = $this->db->tx(function () use ($userId, $attemptId): bool {
            $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            $attempt = $this->repo->lockAttempt($attemptId, $userId) ?? throw ApiError::notFound();
            if ($attempt['status'] === 'submitted') {
                return true;                                                   // duplicate: no side effects
            }
            $rows = $this->repo->gradingRows($attemptId);
            if (count($rows) !== (int)$attempt['question_count']) {
                throw new \RuntimeException("Attempt $attemptId has " . count($rows) . ' assigned questions.');
            }
            $unanswered = array_values(array_map(static fn(array $r): int => (int)$r['position'],
                array_filter($rows, static fn(array $r): bool => $r['selected_choice_id'] === null)));
            if ($unanswered !== []) {
                throw new ApiError(409, 'attempt_incomplete', 'Answer every question before submitting.', ['unanswered_positions' => $unanswered]);
            }

            // Grade in PHP from the snapshot.
            $correct = 0;
            $missedGroup = null;
            $missedSort = PHP_INT_MAX;
            foreach ($rows as $r) {
                $ok = (int)$r['is_correct'] === 1;
                $this->repo->setCorrect((int)$r['id'], $ok);
                if ($ok) {
                    $correct++;
                } elseif ((int)$r['group_sort'] < $missedSort) {
                    $missedSort = (int)$r['group_sort'];
                    $missedGroup = (int)$r['skill_group_id'];
                }
            }

            $policy = $this->repo->currentPolicy() ?? throw new \RuntimeException('No current assessment policy.');
            $rankId = $this->repo->placementRankFor((int)$policy['id'], $correct);
            $recommended = $missedGroup === null ? (int)$policy['all_correct_topic_id'] : $this->repo->firstTopicOfGroup($missedGroup);
            $offered = $this->repo->isReady($recommended) ? null : $this->repo->nearestEarlierReady($recommended);

            $this->repo->insertResult($attemptId, (int)$policy['id'], $correct, $rankId, $recommended, $offered);
            $this->repo->markSubmitted($attemptId);                            // trigger re-checks count, answers, sum
            $this->repo->recordPlacementRank($userId, $rankId, $attemptId);   // rank history before the profile change
            $this->repo->setPlacementOnProfile($userId, $attemptId, $rankId); // placement + current Hunter rank
            $this->repo->createProgressRows($userId, $recommended);          // earlier topics open, none passed
            return false;
        });
        return $this->resultView($userId, $attemptId, $already);
    }

    public function result(int $userId): array
    {
        $profile = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        if ($profile['placement_attempt_id'] === null) {
            throw ApiError::notFound();
        }
        $view = $this->resultView($userId, (int)$profile['placement_attempt_id'], null);
        unset($view['already_submitted']);
        return $view;
    }

    /** Pre-submission view: explicit fields, no correct flags, no explanations. */
    public function attemptView(int $userId, int $attemptId): array
    {
        $h = $this->repo->attemptHeader($attemptId, $userId) ?? throw ApiError::notFound();
        $choices = [];
        foreach ($this->repo->publicChoices($attemptId) as $c) {
            $choices[(int)$c['attempt_question_id']][] = ['id' => (int)$c['id'], 'label' => $c['label'], 'text' => $c['choice_text']];
        }
        $questions = array_map(static fn(array $q): array => [
            'position' => (int)$q['position'],
            'skill_group' => $q['skill_group'],
            'stem' => $q['stem'],
            'code_snippet' => $q['code_snippet'],
            'choices' => $choices[(int)$q['id']] ?? [],
            'selected_choice_id' => $q['selected_choice_id'] === null ? null : (int)$q['selected_choice_id'],
        ], $this->repo->publicQuestions($attemptId));
        return [
            'attempt_id' => (int)$h['id'],
            'status' => $h['status'],
            'question_count' => (int)$h['question_count'],
            'answered_count' => count(array_filter($questions, static fn(array $q): bool => $q['selected_choice_id'] !== null)),
            'started_at' => Time::iso($h['started_at']),
            'questions' => $questions,
        ];
    }

    /** Post-submission result with summary and per-question review (O-08). */
    private function resultView(int $userId, int $attemptId, ?bool $alreadySubmitted): array
    {
        $this->repo->attemptHeader($attemptId, $userId) ?? throw ApiError::notFound();
        $r = $this->repo->result($attemptId) ?? throw ApiError::notFound();
        $profile = $this->students->profileSummary($userId);
        $review = $this->repo->reviewRows($attemptId);
        $choices = [];
        foreach ($review['choices'] as $c) {
            $choices[(int)$c['attempt_question_id']][] = [
                'id' => (int)$c['id'], 'label' => $c['label'], 'text' => $c['choice_text'], 'correct' => (int)$c['is_correct'] === 1,
            ];
        }
        return [
            'attempt_id' => (int)$r['attempt_id'],
            'already_submitted' => $alreadySubmitted,
            'submitted_at' => Time::iso($r['submitted_at']),
            'correct_count' => (int)$r['correct_count'],
            'question_count' => (int)$r['question_count'],
            'placement_rank' => ['code' => $r['rank_code'], 'name' => $r['rank_name'], 'title' => $r['rank_title']],
            'label' => self::LABEL,
            'recommended_topic' => $this->topicRef((int)$r['recommended_topic_id']),
            'offered_topic' => $r['offered_topic_id'] === null ? null : $this->topicRef((int)$r['offered_topic_id']),
            'groups' => array_map(static fn(array $g): array => [
                'code' => $g['code'], 'name' => $g['name'], 'asked' => (int)$g['asked_count'], 'correct' => (int)$g['correct_count'],
            ], $this->repo->groupResults($attemptId)),
            'topics' => array_map(static fn(array $t): array => [
                'id' => (int)$t['topic_id'], 'title' => $t['title'], 'evidence' => $t['evidence_label'],
                'asked' => (int)$t['asked_count'], 'correct' => (int)$t['correct_count'],
            ], $this->repo->topicEvidence($attemptId)),
            'experience_level' => $profile['exp_code'] === null ? null : ['code' => $profile['exp_code'], 'label' => $profile['exp_label']],
            'coins_awarded' => 0,                                              // the assessment never awards coins (A-02)
            'review' => array_map(static fn(array $q): array => [
                'position' => (int)$q['position'],
                'skill_group' => $q['skill_group'],
                'topic' => $q['topic_title'],
                'stem' => $q['stem'],
                'code_snippet' => $q['code_snippet'],
                'choices' => $choices[(int)$q['id']] ?? [],
                'selected_choice_id' => (int)$q['selected_choice_id'],
                'is_correct' => (int)$q['is_correct'] === 1,
                'explanation' => $q['explanation'],
            ], $review['questions']),
        ];
    }

    private function topicRef(int $topicId): ?array
    {
        $t = $this->students->topicRef($topicId);
        return $t === null ? null : ['id' => (int)$t['id'], 'position' => (int)$t['position'], 'title' => $t['title'], 'ready' => (int)$t['is_ready'] === 1];
    }
}
