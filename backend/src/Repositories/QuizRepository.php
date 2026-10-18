<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Lessons, topic quizzes, rewards, rank promotion and the student's ledger.
 * Kind-agnostic snapshot/answer/grading helpers are reused from AssessmentRepository.
 *
 * Grants: SELECT lessons, quiz_grading_policies, quiz_grade_bands, reward_policies;
 *         SELECT/INSERT quiz_results, reward_claims, coin_ledger;
 *         UPDATE(unlocked_at, unlock_source, first_lesson_visit_at, last_lesson_visit_at,
 *                lesson_visit_count, updated_at) student_topic_progress.
 */
final class QuizRepository
{
    public function __construct(private Database $db)
    {
    }

    // ---- topic access --------------------------------------------------------

    /** @return array<string,mixed>|null topic + readiness + this student's availability */
    public function topicAccess(int $userId, int $topicId): ?array
    {
        return $this->db->one(
            'SELECT t.id, t.position, t.title, r.is_ready, p.unlocked_at
               FROM topics t
               JOIN v_topic_readiness r ON r.topic_id = t.id
               LEFT JOIN student_topic_progress p ON p.topic_id = t.id AND p.user_id = ?
              WHERE t.id = ?', [$userId, $topicId]);
    }

    /** @return array<string,mixed>|null published lesson, explicit columns */
    public function publishedLesson(int $topicId): ?array
    {
        return $this->db->one("SELECT title, body, revision FROM lessons WHERE topic_id = ? AND status = 'published'", [$topicId]);
    }

    /** First/last visit and count; a visit never completes or passes anything. */
    public function recordLessonVisit(int $userId, int $topicId): void
    {
        $this->db->run(
            'UPDATE student_topic_progress
                SET first_lesson_visit_at = COALESCE(first_lesson_visit_at, UTC_TIMESTAMP(3)),
                    last_lesson_visit_at = UTC_TIMESTAMP(3),
                    lesson_visit_count = lesson_visit_count + 1,
                    updated_at = UTC_TIMESTAMP(3)
              WHERE user_id = ? AND topic_id = ?', [$userId, $topicId]);
    }

    // ---- attempts --------------------------------------------------------------

    public function openAttemptId(int $userId, int $topicId): ?int
    {
        $row = $this->db->one("SELECT id FROM attempts WHERE user_id = ? AND kind = 'topic_quiz' AND topic_id = ? AND status = 'in_progress'", [$userId, $topicId]);
        return $row === null ? null : (int)$row['id'];
    }

    /** @return list<int> question ids this student has already been assigned in this topic */
    public function seenQuestionIds(int $userId, int $topicId): array
    {
        return array_map('intval', array_column($this->db->all(
            "SELECT DISTINCT aq.question_id FROM attempt_questions aq JOIN attempts a ON a.id = aq.attempt_id
              WHERE a.user_id = ? AND a.kind = 'topic_quiz' AND a.topic_id = ?", [$userId, $topicId]), 'question_id'));
    }

    /**
     * Active, valid quiz questions of the topic (>= 2 choices, exactly one correct).
     *
     * LOCKING read (share mode): returns the latest committed question rows and keeps
     * them from changing until the attempt commits. Administrator edits lock the
     * question row FOR UPDATE before touching its choices, so the snapshot copies a
     * coherent question + choice set (never an old stem with new choices). Only
     * question rows are locked (no topic join) to keep the lock order acyclic with
     * administrator edits (topic -> question -> choices).
     */
    public function validQuizPool(int $topicId): array
    {
        $group = (int)$this->db->one('SELECT skill_group_id FROM topics WHERE id = ?', [$topicId])['skill_group_id'];
        $rows = $this->db->all(
            "SELECT q.id, q.revision, q.topic_id, q.stem, q.code_snippet, q.explanation
               FROM questions q
              WHERE q.topic_id = ? AND q.for_quiz = 1 AND q.status = 'active'
                AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id) >= 2
                AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id AND c.is_correct = 1) = 1
              LOCK IN SHARE MODE", [$topicId]);
        return array_map(static fn(array $r): array => $r + ['skill_group_id' => $group], $rows);
    }

    public function nextAttemptNumber(int $userId, int $topicId): int
    {
        return (int)$this->db->one("SELECT COALESCE(MAX(attempt_number), 0) + 1 AS n FROM attempts WHERE user_id = ? AND kind = 'topic_quiz' AND topic_id = ?",
            [$userId, $topicId])['n'];
    }

    public function insertAttempt(int $userId, int $topicId, int $number, int $questionCount, string $startedVia): int
    {
        $this->db->run("INSERT INTO attempts (user_id, kind, topic_id, attempt_number, question_count, started_via) VALUES (?, 'topic_quiz', ?, ?, ?, ?)",
            [$userId, $topicId, $number, $questionCount, $startedVia]);
        return $this->db->lastId();
    }

    /** Lock step 2: the attempt row, only if it is this student's topic quiz. */
    public function lockAttempt(int $attemptId, int $userId): ?array
    {
        return $this->db->one("SELECT id, topic_id, status, question_count FROM attempts WHERE id = ? AND user_id = ? AND kind = 'topic_quiz' FOR UPDATE",
            [$attemptId, $userId]);
    }

    /** @return array<string,mixed>|null */
    public function attemptHeader(int $attemptId, int $userId): ?array
    {
        return $this->db->one(
            "SELECT id, topic_id, attempt_number, status, started_via, question_count, started_at, submitted_at
               FROM attempts WHERE id = ? AND user_id = ? AND kind = 'topic_quiz'", [$attemptId, $userId]);
    }

    // ---- grading, results ------------------------------------------------------

    /** @return array{id:int, pass_mark:int, question_count:int} */
    public function currentGradingPolicy(): array
    {
        $r = $this->db->one('SELECT id, pass_mark_correct, question_count FROM quiz_grading_policies WHERE is_current = 1')
            ?? throw new \RuntimeException('No current quiz grading policy.');
        return ['id' => (int)$r['id'], 'pass_mark' => (int)$r['pass_mark_correct'], 'question_count' => (int)$r['question_count']];
    }

    /** @return array{grade:string, percent:int} */
    public function gradeBand(int $policyId, int $correct): array
    {
        $r = $this->db->one('SELECT grade_letter, percent FROM quiz_grade_bands WHERE policy_id = ? AND correct_count = ?', [$policyId, $correct])
            ?? throw new \RuntimeException("No grade band for $correct correct.");
        return ['grade' => $r['grade_letter'], 'percent' => (int)$r['percent']];
    }

    public function insertQuizResult(int $attemptId, int $policyId, int $correct, string $grade, int $percent, bool $passed): void
    {
        $this->db->run('INSERT INTO quiz_results (attempt_id, grading_policy_id, correct_count, grade_letter, percent, passed) VALUES (?, ?, ?, ?, ?, ?)',
            [$attemptId, $policyId, $correct, $grade, $percent, $passed ? 1 : 0]);
    }

    /** @return array<string,mixed>|null */
    public function quizResult(int $attemptId): ?array
    {
        return $this->db->one('SELECT correct_count, grade_letter, percent, passed FROM quiz_results WHERE attempt_id = ?', [$attemptId]);
    }

    /** The topic after this one in curriculum order, or null for the final topic. */
    public function nextTopic(int $topicId): ?array
    {
        return $this->db->one(
            'SELECT n.id, n.title, r.is_ready FROM topics c JOIN topics n ON n.position = c.position + 1
               JOIN v_topic_readiness r ON r.topic_id = n.id WHERE c.id = ?', [$topicId]);
    }

    /** Unlock the next topic if still locked. Returns true when this call unlocked it. */
    public function unlockTopic(int $userId, int $topicId): bool
    {
        return $this->db->run(
            "UPDATE student_topic_progress SET unlocked_at = UTC_TIMESTAMP(3), unlock_source = 'passed_previous', updated_at = UTC_TIMESTAMP(3)
              WHERE user_id = ? AND topic_id = ? AND unlocked_at IS NULL", [$userId, $topicId]) === 1;
    }

    public function unlockSource(int $userId, int $topicId): ?string
    {
        $r = $this->db->one('SELECT unlock_source FROM student_topic_progress WHERE user_id = ? AND topic_id = ?', [$userId, $topicId]);
        return $r['unlock_source'] ?? null;
    }

    /** Earliest passing attempt of this student for the topic (by submission time, then id). */
    public function firstPassAttemptId(int $userId, int $topicId): ?int
    {
        $r = $this->db->one(
            "SELECT a.id FROM attempts a JOIN quiz_results r ON r.attempt_id = a.id
              WHERE a.user_id = ? AND a.kind = 'topic_quiz' AND a.topic_id = ? AND r.passed = 1
              ORDER BY a.submitted_at, a.id LIMIT 1", [$userId, $topicId]);
        return $r === null ? null : (int)$r['id'];
    }

    // ---- rank progression ------------------------------------------------------

    /** @return array{policy_id:int, rank_id:int, sort:int, code:string}|null earned band for a count */
    public function earnedRank(int $topicsPassed): ?array
    {
        $r = $this->db->one(
            'SELECT b.policy_id, b.rank_id, r.sort_order, r.code
               FROM rank_progression_bands b
               JOIN rank_progression_policies p ON p.id = b.policy_id AND p.is_current = 1
               JOIN ranks r ON r.id = b.rank_id
              WHERE ? BETWEEN b.min_topics_passed AND b.max_topics_passed', [$topicsPassed]);
        return $r === null ? null : ['policy_id' => (int)$r['policy_id'], 'rank_id' => (int)$r['rank_id'], 'sort' => (int)$r['sort_order'], 'code' => $r['code']];
    }

    /** @return array{id:int, sort:int, code:string} */
    public function rank(int $rankId): array
    {
        $r = $this->db->one('SELECT id, sort_order, code FROM ranks WHERE id = ?', [$rankId]);
        return ['id' => (int)$r['id'], 'sort' => (int)$r['sort_order'], 'code' => $r['code']];
    }

    public function insertPromotion(int $userId, int $fromRankId, int $toRankId, int $attemptId, int $policyId, int $topicsPassed): void
    {
        $this->db->run(
            "INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id, rank_policy_id, topics_passed)
             VALUES (?, ?, ?, 'promotion', ?, ?, ?)", [$userId, $fromRankId, $toRankId, $attemptId, $policyId, $topicsPassed]);
    }

    public function setCurrentRank(int $userId, int $rankId): void
    {
        $this->db->run('UPDATE student_profiles SET current_rank_id = ?, updated_at = UTC_TIMESTAMP(3) WHERE user_id = ?', [$rankId, $userId]);
    }

    /** @return array<string,mixed>|null promotion recorded with this attempt as evidence */
    public function promotionFor(int $attemptId): ?array
    {
        return $this->db->one(
            "SELECT fr.code AS from_code, tr.code AS to_code, h.topics_passed, p.version AS policy_version
               FROM rank_history h JOIN ranks fr ON fr.id = h.from_rank_id JOIN ranks tr ON tr.id = h.to_rank_id
               JOIN rank_progression_policies p ON p.id = h.rank_policy_id
              WHERE h.evidence_attempt_id = ? AND h.reason = 'promotion'", [$attemptId]);
    }

    /** @return list<array<string,mixed>> */
    public function rankHistory(int $userId): array
    {
        return $this->db->all(
            'SELECT h.created_at, h.reason, fr.code AS from_code, tr.code AS to_code, h.topics_passed, h.evidence_attempt_id, p.version AS policy_version
               FROM rank_history h
               LEFT JOIN ranks fr ON fr.id = h.from_rank_id
               JOIN ranks tr ON tr.id = h.to_rank_id
               LEFT JOIN rank_progression_policies p ON p.id = h.rank_policy_id
              WHERE h.user_id = ? ORDER BY h.id', [$userId]);
    }

    // ---- rewards and ledger ----------------------------------------------------

    public function claimExists(int $userId, int $topicId): bool
    {
        return $this->db->one("SELECT 1 AS x FROM reward_claims WHERE user_id = ? AND topic_id = ? AND reward_type = 'topic_first_completion'",
            [$userId, $topicId]) !== null;
    }

    /** @return array{id:int, amount:int}|null */
    public function currentRewardPolicy(): ?array
    {
        $r = $this->db->one("SELECT id, coin_amount FROM reward_policies WHERE reward_type = 'topic_first_completion' AND is_current = 1");
        return $r === null ? null : ['id' => (int)$r['id'], 'amount' => (int)$r['coin_amount']];
    }

    public function insertClaim(int $userId, int $topicId, int $attemptId, int $policyId, int $amount): int
    {
        $this->db->run("INSERT INTO reward_claims (user_id, topic_id, reward_type, attempt_id, reward_policy_id, coin_amount)
                        VALUES (?, ?, 'topic_first_completion', ?, ?, ?)", [$userId, $topicId, $attemptId, $policyId, $amount]);
        return $this->db->lastId();
    }

    /** Ledger credit; the database trigger verifies balance_after and updates the cached balance. */
    public function insertRewardCredit(int $userId, int $amount, int $balanceAfter, int $claimId): void
    {
        $this->db->run("INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, reward_claim_id)
                        VALUES (?, 'credit', ?, ?, 'topic_reward', ?)", [$userId, $amount, $balanceAfter, $claimId]);
    }

    /** @return array<string,mixed>|null reward paid for this attempt (submission-time facts) */
    public function rewardFor(int $attemptId): ?array
    {
        return $this->db->one(
            'SELECT c.coin_amount, c.reward_type, l.balance_after
               FROM reward_claims c JOIN coin_ledger l ON l.reward_claim_id = c.id
              WHERE c.attempt_id = ?', [$attemptId]);
    }

    /** @return list<array<string,mixed>> newest first */
    public function ledger(int $userId, int $limit, ?int $beforeId): array
    {
        $sql = 'SELECT l.id, l.created_at, l.direction, l.amount, l.balance_after, l.entry_type, c.topic_id, o.badge_id
                  FROM coin_ledger l
                  LEFT JOIN reward_claims c ON c.id = l.reward_claim_id
                  LEFT JOIN badge_ownership o ON o.id = l.badge_ownership_id
                 WHERE l.user_id = ?' . ($beforeId !== null ? ' AND l.id < ?' : '') . " ORDER BY l.id DESC LIMIT $limit";
        return $this->db->all($sql, $beforeId !== null ? [$userId, $beforeId] : [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function topicAttempts(int $userId, int $topicId): array
    {
        return $this->db->all(
            "SELECT a.id, a.attempt_number, a.status, a.started_via, a.started_at, a.submitted_at,
                    r.correct_count, r.grade_letter, r.percent, r.passed
               FROM attempts a LEFT JOIN quiz_results r ON r.attempt_id = a.id
              WHERE a.user_id = ? AND a.kind = 'topic_quiz' AND a.topic_id = ? ORDER BY a.attempt_number", [$userId, $topicId]);
    }
}
