<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\AssessmentRepository;
use Hunter\Repositories\QuizRepository;
use Hunter\Repositories\StudentRepository;
use Hunter\Support\Time;
use PDOException;

/**
 * Lessons, topic quizzes, first-completion rewards, next-topic unlock and rank
 * promotion (A-01, A-02, A-04, C-10). Lock order everywhere: (1) student profile
 * row, (2) attempt row, (3) other rows. A submission's result, unlock, promotion,
 * reward claim and ledger credit commit together or not at all.
 */
final class LearningService
{
    public const LABEL = 'Quiz performance';

    public function __construct(
        private Database $db,
        private QuizRepository $quiz,
        private AssessmentRepository $attempts,          // kind-agnostic snapshot/answer/grading helpers
        private StudentRepository $students,
    ) {
    }

    // ---- topic access ------------------------------------------------------------

    /** Placement done, topic exists, unlocked for this student, content ready (separate checks). */
    private function requireTopicAccess(int $userId, int $topicId, ?array $profile = null): array
    {
        $profile ??= $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        if ($profile['placement_attempt_id'] === null) {
            throw new ApiError(409, 'placement_required', 'Complete the placement assessment first.');
        }
        $t = $this->quiz->topicAccess($userId, $topicId) ?? throw ApiError::notFound();
        if ($t['unlocked_at'] === null) {
            throw new ApiError(409, 'topic_locked', 'This topic is not unlocked yet.');
        }
        if ((int)$t['is_ready'] !== 1) {
            throw new ApiError(409, 'topic_content_not_available', 'This topic is unlocked, but its lesson and quiz are not available yet.');
        }
        return $t;
    }

    public function lesson(int $userId, int $topicId): array
    {
        $this->requireTopicAccess($userId, $topicId);
        $lesson = $this->quiz->publishedLesson($topicId) ?? throw new ApiError(409, 'topic_content_not_available', 'This lesson is not available yet.');
        $this->quiz->recordLessonVisit($userId, $topicId);
        return ['topic_id' => $topicId, 'title' => $lesson['title'], 'body_markdown' => $lesson['body'], 'revision' => (int)$lesson['revision']];
    }

    // ---- quiz attempts -----------------------------------------------------------

    /** @return array{attempt:array, created:bool} */
    public function startQuiz(int $userId, int $topicId, array $input): array
    {
        $via = $input['started_via'] ?? null;
        if (!in_array($via, ['lesson_first', 'direct'], true)) {
            throw ApiError::validation(['started_via' => $via === null ? 'required' : 'invalid']);
        }
        try {
            [$attemptId, $created] = $this->db->tx(function () use ($userId, $topicId, $via): array {
                $profile = $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
                $this->requireTopicAccess($userId, $topicId, $profile);
                $open = $this->quiz->openAttemptId($userId, $topicId);
                if ($open !== null) {
                    return [$open, false];                                       // resume; original started_via kept
                }
                $policy = $this->quiz->currentGradingPolicy();
                $picked = $this->pickQuestions($userId, $topicId, $policy['question_count']);
                $attemptId = $this->quiz->insertAttempt($userId, $topicId, $this->quiz->nextAttemptNumber($userId, $topicId), $policy['question_count'], $via);
                foreach ($picked as $i => $q) {
                    $this->attempts->snapshotQuestion($attemptId, $i + 1, $q);
                }
                return [$attemptId, true];
            });
        } catch (PDOException $e) {
            $open = Database::isDuplicateKey($e) ? $this->quiz->openAttemptId($userId, $topicId) : null;
            if ($open === null) {
                throw $e;
            }
            [$attemptId, $created] = [$open, false];
        }
        return ['attempt' => $this->attemptView($userId, $attemptId), 'created' => $created];
    }

    /** Five distinct valid questions: unseen first, then previously seen ones; random within each set. */
    private function pickQuestions(int $userId, int $topicId, int $count): array
    {
        $seen = array_flip($this->quiz->seenQuestionIds($userId, $topicId));
        $unseen = $old = [];
        foreach ($this->quiz->validQuizPool($topicId) as $q) {
            isset($seen[(int)$q['id']]) ? $old[] = $q : $unseen[] = $q;
        }
        $picked = array_slice(array_merge(self::shuffle($unseen), self::shuffle($old)), 0, $count);
        if (count($picked) !== $count) {
            throw new ApiError(409, 'topic_content_not_available', 'This topic does not have enough valid questions yet.');
        }
        return $picked;
    }

    private static function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    public function answer(int $userId, int $attemptId, int $position, array $input): array
    {
        $choiceId = $input['choice_id'] ?? null;
        if (!is_int($choiceId) || $choiceId < 1) {
            throw ApiError::validation(['choice_id' => $choiceId === null ? 'required' : 'invalid']);
        }
        return $this->db->tx(function () use ($userId, $attemptId, $position, $choiceId): array {
            $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            $attempt = $this->quiz->lockAttempt($attemptId, $userId) ?? throw ApiError::notFound();
            if ($attempt['status'] !== 'in_progress') {
                throw new ApiError(409, 'attempt_finalized', 'This quiz has already been submitted; answers can no longer change.');
            }
            $aqId = $this->attempts->assignedQuestionId($attemptId, $position) ?? throw ApiError::notFound();
            if (!$this->attempts->choiceBelongsTo($choiceId, $aqId)) {
                throw new ApiError(400, 'invalid_choice', 'That choice does not belong to this question.');
            }
            $this->attempts->saveAnswer($aqId, $choiceId);
            return ['position' => $position, 'selected_choice_id' => $choiceId];
        });
    }

    /** Grade, unlock, promote, reward - one transaction. Idempotent. */
    public function submit(int $userId, int $attemptId): array
    {
        $already = $this->db->tx(function () use ($userId, $attemptId): bool {
            $profile = $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            $attempt = $this->quiz->lockAttempt($attemptId, $userId) ?? throw ApiError::notFound();
            if ($attempt['status'] === 'submitted') {
                return true;                                                      // duplicate: no side effects
            }
            $topicId = (int)$attempt['topic_id'];
            $rows = $this->attempts->gradingRows($attemptId);
            if (count($rows) !== (int)$attempt['question_count']) {
                throw new \RuntimeException("Quiz attempt $attemptId has " . count($rows) . ' assigned questions.');
            }
            $unanswered = array_values(array_map(static fn(array $r): int => (int)$r['position'],
                array_filter($rows, static fn(array $r): bool => $r['selected_choice_id'] === null)));
            if ($unanswered !== []) {
                throw new ApiError(409, 'attempt_incomplete', 'Answer every question before submitting.', ['unanswered_positions' => $unanswered]);
            }

            // Grade in PHP from the snapshot, under the current grading policy.
            $correct = 0;
            foreach ($rows as $r) {
                $ok = (int)$r['is_correct'] === 1;
                $this->attempts->setCorrect((int)$r['id'], $ok);
                $correct += $ok ? 1 : 0;
            }
            $policy = $this->quiz->currentGradingPolicy();
            $band = $this->quiz->gradeBand($policy['id'], $correct);
            $passed = $correct >= $policy['pass_mark'];
            $this->quiz->insertQuizResult($attemptId, $policy['id'], $correct, $band['grade'], $band['percent'], $passed);
            $this->attempts->markSubmitted($attemptId);                       // trigger re-checks count, answers, sum

            if ($passed) {
                $next = $this->quiz->nextTopic($topicId);                    // null for the final topic
                if ($next !== null) {
                    $this->quiz->unlockTopic($userId, (int)$next['id']);     // no-op if already unlocked; never re-locks
                }
                $this->applyRankProgression($userId, (int)$profile['current_rank_id'], $attemptId);
            }

            // First completed quiz of this topic pays once, even if failed (A-02).
            if (!$this->quiz->claimExists($userId, $topicId)) {
                $rp = $this->quiz->currentRewardPolicy();
                if ($rp !== null) {
                    $claimId = $this->quiz->insertClaim($userId, $topicId, $attemptId, $rp['id'], $rp['amount']);
                    $this->quiz->insertRewardCredit($userId, $rp['amount'], (int)$profile['coin_balance'] + $rp['amount'], $claimId);
                }
            }
            return false;
        });
        return $this->resultView($userId, $attemptId, $already);
    }

    /**
     * Current rank = highest of (placement or previously achieved = current) and the
     * rank earned by DISTINCT topics passed. Never lowers; records only real promotions.
     */
    private function applyRankProgression(int $userId, int $currentRankId, int $attemptId): void
    {
        $passed = $this->students->topicsPassed($userId);                    // includes this attempt
        $earned = $this->quiz->earnedRank($passed);
        if ($earned === null) {
            return;
        }
        $current = $this->quiz->rank($currentRankId);
        if ($earned['sort'] <= $current['sort']) {
            return;
        }
        $this->quiz->insertPromotion($userId, $current['id'], $earned['rank_id'], $attemptId, $earned['policy_id'], $passed);
        $this->quiz->setCurrentRank($userId, $earned['rank_id']);
    }

    // ---- reads ------------------------------------------------------------------

    public function attempt(int $userId, int $attemptId): array
    {
        $h = $this->quiz->attemptHeader($attemptId, $userId) ?? throw ApiError::notFound();
        return $h['status'] === 'submitted' ? $this->resultView($userId, $attemptId, null) : $this->attemptView($userId, $attemptId);
    }

    /** Pre-submission view: explicit fields, no correct flags, no explanations. */
    public function attemptView(int $userId, int $attemptId): array
    {
        $h = $this->quiz->attemptHeader($attemptId, $userId) ?? throw ApiError::notFound();
        $choices = [];
        foreach ($this->attempts->publicChoices($attemptId) as $c) {
            $choices[(int)$c['attempt_question_id']][] = ['id' => (int)$c['id'], 'label' => $c['label'], 'text' => $c['choice_text']];
        }
        $questions = array_map(static fn(array $q): array => [
            'position' => (int)$q['position'],
            'stem' => $q['stem'],
            'code_snippet' => $q['code_snippet'],
            'choices' => $choices[(int)$q['id']] ?? [],
            'selected_choice_id' => $q['selected_choice_id'] === null ? null : (int)$q['selected_choice_id'],
        ], $this->attempts->publicQuestions($attemptId));
        return [
            'attempt_id' => (int)$h['id'],
            'topic_id' => (int)$h['topic_id'],
            'attempt_number' => (int)$h['attempt_number'],
            'status' => $h['status'],
            'started_via' => $h['started_via'],
            'question_count' => (int)$h['question_count'],
            'answered_count' => count(array_filter($questions, static fn(array $q): bool => $q['selected_choice_id'] !== null)),
            'started_at' => Time::iso($h['started_at']),
            'questions' => $questions,
        ];
    }

    /** Submission-time facts only (reward, unlock and promotion caused by THIS attempt). */
    private function resultView(int $userId, int $attemptId, ?bool $alreadySubmitted): array
    {
        $h = $this->quiz->attemptHeader($attemptId, $userId) ?? throw ApiError::notFound();
        $r = $this->quiz->quizResult($attemptId) ?? throw ApiError::notFound();
        $topicId = (int)$h['topic_id'];
        $passed = (int)$r['passed'] === 1;

        $reward = $this->quiz->rewardFor($attemptId);
        $unlocked = null;
        if ($passed && $this->quiz->firstPassAttemptId($userId, $topicId) === $attemptId) {
            $next = $this->quiz->nextTopic($topicId);
            if ($next !== null && $this->quiz->unlockSource($userId, (int)$next['id']) === 'passed_previous') {
                $unlocked = ['id' => (int)$next['id'], 'title' => $next['title'], 'content_ready' => (int)$next['is_ready'] === 1];
            }
        }
        $promotion = $this->quiz->promotionFor($attemptId);

        $review = $this->attempts->reviewRows($attemptId);
        $choices = [];
        foreach ($review['choices'] as $c) {
            $choices[(int)$c['attempt_question_id']][] = ['id' => (int)$c['id'], 'label' => $c['label'], 'text' => $c['choice_text'], 'correct' => (int)$c['is_correct'] === 1];
        }
        $out = [
            'attempt_id' => (int)$h['id'],
            'topic_id' => $topicId,
            'attempt_number' => (int)$h['attempt_number'],
            'already_submitted' => $alreadySubmitted,
            'submitted_at' => Time::iso($h['submitted_at']),
            'label' => self::LABEL,
            'correct_count' => (int)$r['correct_count'],
            'question_count' => (int)$h['question_count'],
            'grade' => $r['grade_letter'],
            'percent' => (int)$r['percent'],
            'passed' => $passed,
            'review' => array_map(static fn(array $q): array => [
                'position' => (int)$q['position'],
                'stem' => $q['stem'],
                'code_snippet' => $q['code_snippet'],
                'choices' => $choices[(int)$q['id']] ?? [],
                'selected_choice_id' => (int)$q['selected_choice_id'],
                'is_correct' => (int)$q['is_correct'] === 1,
                'explanation' => $q['explanation'],
            ], $review['questions']),
            'coins_awarded' => $reward === null ? 0 : (int)$reward['coin_amount'],
            'reward' => $reward === null ? null : ['coins' => (int)$reward['coin_amount'], 'reason' => $reward['reward_type'], 'balance_after' => (int)$reward['balance_after']],
            'unlocked_topic' => $unlocked,
            'promotion' => $promotion === null ? null : ['from' => $promotion['from_code'], 'to' => $promotion['to_code'],
                'topics_passed' => (int)$promotion['topics_passed'], 'policy_version' => (int)$promotion['policy_version']],
        ];
        if ($alreadySubmitted === null) {
            unset($out['already_submitted']);
        }
        return $out;
    }

    public function topicAttempts(int $userId, int $topicId): array
    {
        $profile = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        if ($profile['placement_attempt_id'] === null) {
            throw new ApiError(409, 'placement_required', 'Complete the placement assessment first.');
        }
        $this->quiz->topicAccess($userId, $topicId) ?? throw ApiError::notFound();
        return array_map(static fn(array $a): array => [
            'attempt_id' => (int)$a['id'],
            'attempt_number' => (int)$a['attempt_number'],
            'status' => $a['status'],
            'started_via' => $a['started_via'],
            'started_at' => Time::iso($a['started_at']),
            'submitted_at' => Time::iso($a['submitted_at']),
            'correct_count' => $a['correct_count'] === null ? null : (int)$a['correct_count'],
            'grade' => $a['grade_letter'],
            'percent' => $a['percent'] === null ? null : (int)$a['percent'],
            'passed' => $a['passed'] === null ? null : (int)$a['passed'] === 1,
        ], $this->quiz->topicAttempts($userId, $topicId));
    }

    public function coins(int $userId, array $query): array
    {
        $fields = [];
        $limit = $query['limit'] ?? '50';
        if (!is_string($limit) || !ctype_digit($limit) || (int)$limit < 1 || (int)$limit > 100) {
            $fields['limit'] = 'invalid';
        }
        $before = $query['before_id'] ?? null;
        if ($before !== null && (!is_string($before) || !ctype_digit($before) || (int)$before < 1)) {
            $fields['before_id'] = 'invalid';
        }
        if ($fields !== []) {
            throw ApiError::validation($fields);
        }
        $p = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        return [
            'balance' => (int)$p['coin_balance'],
            'entries' => array_map(static fn(array $l): array => [
                'id' => (int)$l['id'],
                'at' => Time::iso($l['created_at']),
                'direction' => $l['direction'],
                'amount' => (int)$l['amount'],
                'balance_after' => (int)$l['balance_after'],
                'type' => $l['entry_type'],
                'topic_id' => $l['topic_id'] === null ? null : (int)$l['topic_id'],
                'badge_id' => $l['badge_id'] === null ? null : (int)$l['badge_id'],
            ], $this->quiz->ledger($userId, (int)$limit, $before === null ? null : (int)$before)),
        ];
    }

    public function rankHistory(int $userId): array
    {
        return array_map(static function (array $h): array {
            $row = ['at' => Time::iso($h['created_at']), 'reason' => $h['reason'], 'from' => $h['from_code'], 'to' => $h['to_code']];
            if ($h['reason'] === 'promotion') {
                $row += ['topics_passed' => (int)$h['topics_passed'], 'evidence_attempt_id' => (int)$h['evidence_attempt_id'],
                         'policy_version' => (int)$h['policy_version']];
            }
            return $row;
        }, $this->quiz->rankHistory($userId));
    }
}
