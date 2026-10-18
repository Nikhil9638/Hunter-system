<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Administrator content: topics, lessons, questions and choices. Explicit columns.
 * Lock order inside a transaction: topic row -> lesson/question row -> choices.
 * Readiness checks inside a mutation use LOCKING reads, so they see the latest
 * committed state (not an older read view) and cannot race another admin's change.
 *
 * Grants: UPDATE(summary, status, updated_at) topics; INSERT + UPDATE(content
 * columns) lessons, questions; INSERT, UPDATE(choice_text, is_correct), DELETE
 * question_choices (the snapshot FK refuses deleting a choice used in an attempt).
 */
final class ContentRepository
{
    public function __construct(private Database $db)
    {
    }

    // ---- topics --------------------------------------------------------------

    public function topics(): array
    {
        return $this->db->all(
            "SELECT t.id, t.position, t.slug, t.title, t.summary, g.code AS skill_group, t.status, t.updated_at,
                    l.status AS lesson_status, l.revision AS lesson_revision,
                    r.lesson_published, r.quiz_pool_size, r.is_ready,
                    (SELECT COUNT(*) FROM student_topic_progress p WHERE p.topic_id = t.id AND p.unlocked_at IS NOT NULL) AS students_unlocked
               FROM topics t
               JOIN skill_groups g ON g.id = t.skill_group_id
               JOIN v_topic_readiness r ON r.topic_id = t.id
               LEFT JOIN lessons l ON l.topic_id = t.id
              ORDER BY t.position");
    }

    public function topicExists(int $topicId): bool
    {
        return $this->db->one('SELECT 1 AS x FROM topics WHERE id = ?', [$topicId]) !== null;
    }

    /** Lock step 1 for topic-scoped changes. */
    public function lockTopic(int $topicId): ?array
    {
        return $this->db->one('SELECT id, summary, status, updated_at FROM topics WHERE id = ? FOR UPDATE', [$topicId]);
    }

    public function updateTopic(int $topicId, string $summary, string $status): void
    {
        $this->db->run("UPDATE topics SET summary = ?, status = ?, updated_at = GREATEST(UTC_TIMESTAMP(3), updated_at + INTERVAL 1000 MICROSECOND)
                         WHERE id = ?", [$summary, $status, $topicId]);
    }

    public function topicVersion(int $topicId): string
    {
        return (string)$this->db->one('SELECT updated_at FROM topics WHERE id = ?', [$topicId])['updated_at'];
    }

    public function minPoolSize(): int
    {
        return (int)$this->db->one('SELECT min_pool_size FROM quiz_grading_policies WHERE is_current = 1')['min_pool_size'];
    }

    /** Current (locking) count of active VALID quiz questions of a topic. */
    public function poolSizeLocked(int $topicId): int
    {
        $ids = $this->db->all(
            "SELECT q.id FROM questions q
              WHERE q.topic_id = ? AND q.for_quiz = 1 AND q.status = 'active' LOCK IN SHARE MODE", [$topicId]);
        $n = 0;
        foreach ($ids as $r) {
            $c = $this->db->one('SELECT COUNT(*) AS n, COALESCE(SUM(is_correct), 0) AS k FROM question_choices WHERE question_id = ? LOCK IN SHARE MODE', [$r['id']]);
            $n += ((int)$c['n'] >= 2 && (int)$c['k'] === 1) ? 1 : 0;
        }
        return $n;
    }

    /** Current (locking) active valid assessment questions per skill group vs the current quotas. */
    public function assessmentShortfall(): ?array
    {
        $quotas = $this->db->all(
            'SELECT g.id, g.code, q.question_count FROM assessment_group_quotas q
               JOIN assessment_policies p ON p.id = q.policy_id AND p.is_current = 1
               JOIN skill_groups g ON g.id = q.skill_group_id ORDER BY g.sort_order');
        foreach ($quotas as $quota) {
            // topic ids first with a plain read: the locking read below must not lock topic rows
            // (another admin may hold one while waiting for a question row -> deadlock)
            $topicIds = array_map('intval', array_column($this->db->all('SELECT id FROM topics WHERE skill_group_id = ?', [$quota['id']]), 'id'));
            $in = implode(',', $topicIds ?: [0]);
            $ids = $this->db->all(
                "SELECT q.id FROM questions q
                  WHERE q.topic_id IN ($in) AND q.for_assessment = 1 AND q.status = 'active' LOCK IN SHARE MODE");
            $n = 0;
            foreach ($ids as $r) {
                $c = $this->db->one('SELECT COUNT(*) AS n, COALESCE(SUM(is_correct), 0) AS k FROM question_choices WHERE question_id = ? LOCK IN SHARE MODE', [$r['id']]);
                $n += ((int)$c['n'] >= 2 && (int)$c['k'] === 1) ? 1 : 0;
            }
            if ($n < (int)$quota['question_count']) {
                return ['skill_group' => $quota['code'], 'available' => $n, 'required' => (int)$quota['question_count']];
            }
        }
        return null;
    }

    // ---- lessons -------------------------------------------------------------

    public function lesson(int $topicId): ?array
    {
        return $this->db->one('SELECT id, topic_id, title, body, status, revision, published_at, updated_at FROM lessons WHERE topic_id = ?', [$topicId]);
    }

    public function lockLesson(int $topicId): ?array
    {
        return $this->db->one('SELECT id, topic_id, title, body, status, revision, published_at FROM lessons WHERE topic_id = ? FOR UPDATE', [$topicId]);
    }

    public function insertLesson(int $topicId, string $title, string $body): int
    {
        $this->db->run("INSERT INTO lessons (topic_id, title, body, status, revision) VALUES (?, ?, ?, 'draft', 1)", [$topicId, $title, $body]);
        return $this->db->lastId();
    }

    public function updateLessonContent(int $lessonId, string $title, string $body): void
    {
        $this->db->run('UPDATE lessons SET title = ?, body = ?, revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = ?', [$title, $body, $lessonId]);
    }

    public function setLessonStatus(int $lessonId, string $status): void
    {
        $this->db->run("UPDATE lessons SET status = ?, published_at = IF(? = 'published', UTC_TIMESTAMP(3), published_at), updated_at = UTC_TIMESTAMP(3)
                         WHERE id = ?", [$status, $status, $lessonId]);
    }

    // ---- questions -----------------------------------------------------------

    /** @param array{topic_id:?int, usage:?string, status:?string} $f */
    public function questionPage(array $f, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ($f['topic_id'] !== null) {
            $where[] = 'q.topic_id = ?';
            $params[] = $f['topic_id'];
        }
        if ($f['usage'] === 'quiz') {
            $where[] = 'q.for_quiz = 1';
        } elseif ($f['usage'] === 'assessment') {
            $where[] = 'q.for_assessment = 1';
        }
        if ($f['status'] !== null) {
            $where[] = 'q.status = ?';
            $params[] = $f['status'];
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int)$this->db->one("SELECT COUNT(*) AS n FROM questions q $w", $params)['n'];
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all(self::QUESTION_SELECT . " $w ORDER BY q.topic_id, q.id LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    private const QUESTION_SELECT =
        'SELECT q.id, q.topic_id, g.code AS skill_group, q.for_quiz, q.for_assessment, q.difficulty, q.stem, q.code_snippet,
                q.explanation, q.status, q.revision, q.created_at, q.updated_at, q.retired_at,
                (SELECT COUNT(*) FROM attempt_questions aq WHERE aq.question_id = q.id) AS used_in_attempts
           FROM questions q JOIN topics t ON t.id = q.topic_id JOIN skill_groups g ON g.id = t.skill_group_id';

    public function question(int $id): ?array
    {
        return $this->db->one(self::QUESTION_SELECT . ' WHERE q.id = ?', [$id]);
    }

    /** @param list<int> $ids @return array<int, list<array>> choices per question id */
    public function choicesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->db->all("SELECT id, question_id, label, choice_text, is_correct FROM question_choices WHERE question_id IN ($in) ORDER BY question_id, label") as $c) {
            $out[(int)$c['question_id']][] = $c;
        }
        return $out;
    }

    public function lockQuestion(int $id): ?array
    {
        return $this->db->one(
            'SELECT id, topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status, revision
               FROM questions WHERE id = ? FOR UPDATE', [$id]);
    }

    public function lockChoices(int $questionId): array
    {
        return $this->db->all('SELECT id, label, choice_text, is_correct FROM question_choices WHERE question_id = ? ORDER BY label FOR UPDATE', [$questionId]);
    }

    public function insertQuestion(array $q): int
    {
        $this->db->run("INSERT INTO questions (topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status, revision)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 1)",
            [$q['topic_id'], $q['for_quiz'] ? 1 : 0, $q['for_assessment'] ? 1 : 0, $q['difficulty'], $q['stem'], $q['code_snippet'], $q['explanation']]);
        return $this->db->lastId();
    }

    public function updateQuestion(int $id, array $q): void
    {
        $this->db->run('UPDATE questions SET topic_id = ?, for_quiz = ?, for_assessment = ?, difficulty = ?, stem = ?, code_snippet = ?, explanation = ?,
                               revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = ?',
            [$q['topic_id'], $q['for_quiz'] ? 1 : 0, $q['for_assessment'] ? 1 : 0, $q['difficulty'], $q['stem'], $q['code_snippet'], $q['explanation'], $id]);
    }

    public function setQuestionStatus(int $id, string $status): void
    {
        $this->db->run("UPDATE questions SET status = ?, retired_at = IF(? = 'retired', UTC_TIMESTAMP(3), NULL), updated_at = UTC_TIMESTAMP(3) WHERE id = ?",
            [$status, $status, $id]);
    }

    public function choiceUsed(int $choiceId): bool
    {
        // locking (current) read: sees a snapshot committed by a start that finished just before our lock
        return $this->db->one('SELECT 1 AS x FROM attempt_question_choices WHERE source_choice_id = ? LIMIT 1 LOCK IN SHARE MODE', [$choiceId]) !== null;
    }

    /**
     * Replace the choice set, keeping ids where labels match. Correct flags are
     * cleared first so the "one correct choice" unique key never trips mid-way.
     *
     * @param list<array{label:string, text:string, is_correct:bool}> $choices
     * @param list<array<string,mixed>> $existing locked rows
     */
    public function replaceChoices(int $questionId, array $existing, array $choices): void
    {
        $byLabel = array_column($existing, null, 'label');
        $wanted = array_column($choices, null, 'label');
        foreach ($existing as $c) {
            if (!isset($wanted[$c['label']])) {
                $this->db->run('DELETE FROM question_choices WHERE id = ?', [$c['id']]);
            }
        }
        $this->db->run('UPDATE question_choices SET is_correct = 0 WHERE question_id = ? AND is_correct = 1', [$questionId]);
        foreach ($choices as $c) {
            if (isset($byLabel[$c['label']])) {
                $this->db->run('UPDATE question_choices SET choice_text = ? WHERE id = ?', [$c['text'], $byLabel[$c['label']]['id']]);
            } else {
                $this->db->run('INSERT INTO question_choices (question_id, label, choice_text, is_correct) VALUES (?, ?, ?, 0)', [$questionId, $c['label'], $c['text']]);
            }
        }
        foreach ($choices as $c) {
            if ($c['is_correct']) {
                $this->db->run('UPDATE question_choices SET is_correct = 1 WHERE question_id = ? AND label = ?', [$questionId, $c['label']]);
            }
        }
    }
}
