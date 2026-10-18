<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Placement assessment data. Every query names its columns. Before submission,
 * choices are read only through v_attempt_choices_public (no correct flag), and
 * explanations are not selected.
 *
 * Grants: SELECT/INSERT attempts, UPDATE(status, submitted_at);
 *         SELECT/INSERT attempt_questions, UPDATE(selected_choice_id, answered_at, is_correct);
 *         SELECT/INSERT attempt_question_choices; SELECT v_attempt_choices_public;
 *         SELECT questions, question_choices, assessment_policies, assessment_group_quotas,
 *         placement_rank_bands; SELECT/INSERT assessment_results, rank_history,
 *         student_topic_progress; SELECT v_assessment_group_results, v_assessment_topic_evidence.
 */
final class AssessmentRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function currentPolicy(): ?array
    {
        return $this->db->one('SELECT id, question_count, all_correct_topic_id FROM assessment_policies WHERE is_current = 1');
    }

    /** @return list<array{skill_group_id:int, sort_order:int, question_count:int}> */
    public function quotas(int $policyId): array
    {
        return array_map(static fn(array $r): array => [
            'skill_group_id' => (int)$r['skill_group_id'], 'sort_order' => (int)$r['sort_order'], 'question_count' => (int)$r['question_count'],
        ], $this->db->all(
            'SELECT q.skill_group_id, g.sort_order, q.question_count
               FROM assessment_group_quotas q JOIN skill_groups g ON g.id = q.skill_group_id
              WHERE q.policy_id = ? ORDER BY g.sort_order', [$policyId]));
    }

    /**
     * Active assessment questions that are VALID (>= 2 choices, exactly one correct).
     *
     * @return list<array<string,mixed>>
     */
    public function validPool(): array
    {
        // LOCKING read (share mode) of question rows only: the snapshot copies a coherent
        // question + choice set even while an administrator edits (see QuizRepository::validQuizPool).
        $groups = array_column($this->db->all('SELECT id, skill_group_id FROM topics'), 'skill_group_id', 'id');
        $rows = $this->db->all(
            "SELECT q.id, q.revision, q.topic_id, q.stem, q.code_snippet, q.explanation
               FROM questions q
              WHERE q.for_assessment = 1 AND q.status = 'active'
                AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id) >= 2
                AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id AND c.is_correct = 1) = 1
              LOCK IN SHARE MODE");
        return array_map(static fn(array $r): array => $r + ['skill_group_id' => (int)$groups[$r['topic_id']]], $rows);
    }

    public function openAttemptId(int $userId): ?int
    {
        $row = $this->db->one("SELECT id FROM attempts WHERE user_id = ? AND kind = 'assessment' AND status = 'in_progress'", [$userId]);
        return $row === null ? null : (int)$row['id'];
    }

    public function nextAttemptNumber(int $userId): int
    {
        return (int)$this->db->one("SELECT COALESCE(MAX(attempt_number), 0) + 1 AS n FROM attempts WHERE user_id = ? AND kind = 'assessment'", [$userId])['n'];
    }

    public function insertAttempt(int $userId, int $number, int $questionCount): int
    {
        $this->db->run("INSERT INTO attempts (user_id, kind, attempt_number, question_count) VALUES (?, 'assessment', ?, ?)",
            [$userId, $number, $questionCount]);
        return $this->db->lastId();
    }

    /** Snapshot one question and all its choices (including the correct flag, for grading later). */
    public function snapshotQuestion(int $attemptId, int $position, array $q): void
    {
        $this->db->run(
            'INSERT INTO attempt_questions (attempt_id, position, question_id, question_revision, topic_id, skill_group_id, stem, code_snippet, explanation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$attemptId, $position, $q['id'], $q['revision'], $q['topic_id'], $q['skill_group_id'], $q['stem'], $q['code_snippet'], $q['explanation']]);
        $aqId = $this->db->lastId();
        $this->db->run(
            'INSERT INTO attempt_question_choices (attempt_question_id, source_choice_id, label, choice_text, is_correct)
             SELECT ?, id, label, choice_text, is_correct FROM question_choices WHERE question_id = ? ORDER BY label',
            [$aqId, $q['id']]);
    }

    /** Lock step 2: the attempt row, only if it is this student's assessment. */
    public function lockAttempt(int $attemptId, int $userId): ?array
    {
        return $this->db->one(
            "SELECT id, status, question_count FROM attempts WHERE id = ? AND user_id = ? AND kind = 'assessment' FOR UPDATE",
            [$attemptId, $userId]);
    }

    /** @return array<string,mixed>|null id, status, question_count, started_at, submitted_at */
    public function attemptHeader(int $attemptId, int $userId): ?array
    {
        return $this->db->one(
            "SELECT id, status, question_count, started_at, submitted_at FROM attempts WHERE id = ? AND user_id = ? AND kind = 'assessment'",
            [$attemptId, $userId]);
    }

    public function assignedQuestionId(int $attemptId, int $position): ?int
    {
        $row = $this->db->one('SELECT id FROM attempt_questions WHERE attempt_id = ? AND position = ?', [$attemptId, $position]);
        return $row === null ? null : (int)$row['id'];
    }

    public function choiceBelongsTo(int $choiceId, int $attemptQuestionId): bool
    {
        return $this->db->one('SELECT 1 AS x FROM attempt_question_choices WHERE id = ? AND attempt_question_id = ?', [$choiceId, $attemptQuestionId]) !== null;
    }

    public function saveAnswer(int $attemptQuestionId, int $choiceId): void
    {
        $this->db->run('UPDATE attempt_questions SET selected_choice_id = ?, answered_at = UTC_TIMESTAMP(3) WHERE id = ?', [$choiceId, $attemptQuestionId]);
    }

    /**
     * Questions for the student BEFORE submission: no correct flag, no explanation.
     *
     * @return list<array<string,mixed>>
     */
    public function publicQuestions(int $attemptId): array
    {
        return $this->db->all(
            'SELECT aq.id, aq.position, g.code AS skill_group, aq.stem, aq.code_snippet, aq.selected_choice_id
               FROM attempt_questions aq JOIN skill_groups g ON g.id = aq.skill_group_id
              WHERE aq.attempt_id = ? ORDER BY aq.position', [$attemptId]);
    }

    /** @return list<array<string,mixed>> id, attempt_question_id, label, choice_text (public view) */
    public function publicChoices(int $attemptId): array
    {
        return $this->db->all(
            'SELECT c.id, c.attempt_question_id, c.label, c.choice_text
               FROM v_attempt_choices_public c JOIN attempt_questions aq ON aq.id = c.attempt_question_id
              WHERE aq.attempt_id = ? ORDER BY aq.position, c.label', [$attemptId]);
    }

    /** Grading input from the snapshot: selected choice's correct flag per question. */
    public function gradingRows(int $attemptId): array
    {
        return $this->db->all(
            'SELECT aq.id, aq.position, aq.skill_group_id, g.sort_order AS group_sort, aq.selected_choice_id, c.is_correct
               FROM attempt_questions aq
               JOIN skill_groups g ON g.id = aq.skill_group_id
               LEFT JOIN attempt_question_choices c ON c.id = aq.selected_choice_id AND c.attempt_question_id = aq.id
              WHERE aq.attempt_id = ? ORDER BY aq.position', [$attemptId]);
    }

    public function setCorrect(int $attemptQuestionId, bool $correct): void
    {
        $this->db->run('UPDATE attempt_questions SET is_correct = ? WHERE id = ?', [$correct ? 1 : 0, $attemptQuestionId]);
    }

    public function placementRankFor(int $policyId, int $correct): int
    {
        return (int)$this->db->one('SELECT rank_id FROM placement_rank_bands WHERE policy_id = ? AND ? BETWEEN min_correct AND max_correct',
            [$policyId, $correct])['rank_id'];
    }

    public function firstTopicOfGroup(int $skillGroupId): int
    {
        return (int)$this->db->one('SELECT id FROM topics WHERE skill_group_id = ? ORDER BY position LIMIT 1', [$skillGroupId])['id'];
    }

    public function isReady(int $topicId): bool
    {
        return (int)($this->db->one('SELECT is_ready FROM v_topic_readiness WHERE topic_id = ?', [$topicId])['is_ready'] ?? 0) === 1;
    }

    /** Approved fallback: the nearest EARLIER ready topic, or null. */
    public function nearestEarlierReady(int $topicId): ?int
    {
        $row = $this->db->one(
            'SELECT r.topic_id FROM v_topic_readiness r
              WHERE r.is_ready = 1 AND r.position < (SELECT position FROM topics WHERE id = ?)
              ORDER BY r.position DESC LIMIT 1', [$topicId]);
        return $row === null ? null : (int)$row['topic_id'];
    }

    public function insertResult(int $attemptId, int $policyId, int $correct, int $rankId, int $recommended, ?int $offered): void
    {
        $this->db->run(
            'INSERT INTO assessment_results (attempt_id, assessment_policy_id, correct_count, placement_rank_id, recommended_topic_id, offered_topic_id)
             VALUES (?, ?, ?, ?, ?, ?)', [$attemptId, $policyId, $correct, $rankId, $recommended, $offered]);
    }

    public function markSubmitted(int $attemptId): void
    {
        $this->db->run("UPDATE attempts SET status = 'submitted', submitted_at = UTC_TIMESTAMP(3) WHERE id = ?", [$attemptId]);
    }

    public function recordPlacementRank(int $userId, int $rankId, int $attemptId): void
    {
        $this->db->run("INSERT INTO rank_history (user_id, from_rank_id, to_rank_id, reason, evidence_attempt_id) VALUES (?, NULL, ?, 'placement', ?)",
            [$userId, $rankId, $attemptId]);
    }

    public function setPlacementOnProfile(int $userId, int $attemptId, int $rankId): void
    {
        $this->db->run(
            "UPDATE student_profiles SET placement_attempt_id = ?, placement_rank_id = ?, current_rank_id = ?,
                    onboarding_state = 'assessment_submitted', updated_at = UTC_TIMESTAMP(3)
              WHERE user_id = ?", [$attemptId, $rankId, $rankId, $userId]);
    }

    /** One progress row per topic; topics up to the recommended one are unlocked by placement. */
    public function createProgressRows(int $userId, int $recommendedTopicId): void
    {
        $this->db->run(
            "INSERT INTO student_topic_progress (user_id, topic_id, unlocked_at, unlock_source)
             SELECT ?, t.id, IF(t.position <= r.position, UTC_TIMESTAMP(3), NULL), IF(t.position <= r.position, 'placement', NULL)
               FROM topics t CROSS JOIN (SELECT position FROM topics WHERE id = ?) r", [$userId, $recommendedTopicId]);
    }

    /** @return array<string,mixed>|null stored result, explicit columns */
    public function result(int $attemptId): ?array
    {
        return $this->db->one(
            'SELECT ar.attempt_id, ar.correct_count, a.question_count, a.submitted_at,
                    ar.recommended_topic_id, ar.offered_topic_id,
                    r.code AS rank_code, r.display_name AS rank_name, r.title AS rank_title
               FROM assessment_results ar
               JOIN attempts a ON a.id = ar.attempt_id
               JOIN ranks r ON r.id = ar.placement_rank_id
              WHERE ar.attempt_id = ?', [$attemptId]);
    }

    /** @return list<array<string,mixed>> */
    public function groupResults(int $attemptId): array
    {
        return $this->db->all(
            'SELECT v.skill_group_code AS code, g.name, v.asked_count, v.correct_count
               FROM v_assessment_group_results v JOIN skill_groups g ON g.id = v.skill_group_id
              WHERE v.attempt_id = ? ORDER BY g.sort_order', [$attemptId]);
    }

    /** @return list<array<string,mixed>> */
    public function topicEvidence(int $attemptId): array
    {
        return $this->db->all(
            'SELECT v.topic_id, t.title, v.evidence_label, v.asked_count, v.correct_count
               FROM v_assessment_topic_evidence v JOIN topics t ON t.id = v.topic_id
              WHERE v.attempt_id = ? ORDER BY v.position', [$attemptId]);
    }

    /**
     * Post-submission review (correct flags and explanations). Callers must only use
     * this for a SUBMITTED attempt; the method itself enforces it.
     *
     * @return array{questions:list<array<string,mixed>>, choices:list<array<string,mixed>>}
     */
    public function reviewRows(int $attemptId): array
    {
        $questions = $this->db->all(
            "SELECT aq.id, aq.position, g.code AS skill_group, t.title AS topic_title, aq.stem, aq.code_snippet,
                    aq.explanation, aq.selected_choice_id, aq.is_correct
               FROM attempt_questions aq
               JOIN attempts a ON a.id = aq.attempt_id AND a.status = 'submitted'
               JOIN skill_groups g ON g.id = aq.skill_group_id
               JOIN topics t ON t.id = aq.topic_id
              WHERE aq.attempt_id = ? ORDER BY aq.position", [$attemptId]);
        $choices = $this->db->all(
            "SELECT c.id, c.attempt_question_id, c.label, c.choice_text, c.is_correct
               FROM attempt_question_choices c
               JOIN attempt_questions aq ON aq.id = c.attempt_question_id
               JOIN attempts a ON a.id = aq.attempt_id AND a.status = 'submitted'
              WHERE aq.attempt_id = ? ORDER BY aq.position, c.label", [$attemptId]);
        return ['questions' => $questions, 'choices' => $choices];
    }
}
