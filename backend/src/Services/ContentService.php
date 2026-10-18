<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\AuditRepository;
use Hunter\Repositories\ContentRepository;
use Hunter\Support\Text;
use Hunter\Support\Time;

/**
 * Administrator content management: topics, lessons, questions (api-contract.md §10).
 * Every mutation is one transaction containing the change AND its audit row(s);
 * existing records are protected by optimistic concurrency tokens (revision for
 * lessons/questions, version = updated_at for topics). Nothing is ever deleted
 * except unused choices of a question being edited.
 */
final class ContentService
{
    private const LABELS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function __construct(private Database $db, private ContentRepository $content, private AuditRepository $audit)
    {
    }

    // ---- shared validation ---------------------------------------------------

    private static function text(array $in, string $key, int $max, array &$f, bool $required = true, bool $nullable = false): ?string
    {
        if (!array_key_exists($key, $in) || ($nullable && $in[$key] === null)) {
            if ($required && !$nullable) {
                $f[$key] = 'required';
            }
            return null;
        }
        $v = $in[$key];
        if (!is_string($v) || !Text::isUtf8($v)) {
            $f[$key] = 'invalid';
            return null;
        }
        $v = trim(Text::nfc(str_replace("\r\n", "\n", $v)));
        if ($v === '') {
            if ($nullable) {
                return null;
            }
            $f[$key] = 'required';
            return null;
        }
        if (mb_strlen($v) > $max) {
            $f[$key] = 'too_long';
            return null;
        }
        return $v;
    }

    private static function revision(array $in, array &$f, int $min = 1): ?int
    {
        $v = $in['expected_revision'] ?? null;
        if (!array_key_exists('expected_revision', $in)) {
            $f['expected_revision'] = 'required';
            return null;
        }
        if (!is_int($v) || $v < $min) {
            $f['expected_revision'] = 'invalid';
            return null;
        }
        return $v;
    }

    private static function conflictRevision(int $current): ApiError
    {
        return new ApiError(409, 'edit_conflict', 'This item was changed by someone else. Reload it and apply your change again.', ['current_revision' => $current]);
    }

    // ---- topics ----------------------------------------------------------------

    public function topics(): array
    {
        $min = $this->content->minPoolSize();
        return array_map(fn(array $t): array => $this->topicView($t, $min), $this->content->topics());
    }

    private function topicView(array $t, int $min): array
    {
        $published = (int)$t['lesson_published'] === 1;
        return [
            'id' => (int)$t['id'], 'position' => (int)$t['position'], 'slug' => $t['slug'], 'title' => $t['title'], 'summary' => $t['summary'],
            'skill_group' => $t['skill_group'], 'status' => $t['status'], 'version' => Time::iso($t['updated_at']),
            'lesson' => $t['lesson_status'] === null ? null : ['status' => $t['lesson_status'], 'revision' => (int)$t['lesson_revision']],
            'lesson_published' => $published, 'quiz_pool_size' => (int)$t['quiz_pool_size'], 'required_pool_size' => $min,
            'content_complete' => $published && (int)$t['quiz_pool_size'] >= $min,
            'is_ready' => (int)$t['is_ready'] === 1, 'students_unlocked' => (int)$t['students_unlocked'],
        ];
    }

    public function patchTopic(int $adminId, int $topicId, array $in): array
    {
        $f = [];
        $version = $in['expected_version'] ?? null;
        if (!is_string($version) || $version === '') {
            $f['expected_version'] = array_key_exists('expected_version', $in) ? 'invalid' : 'required';
        }
        $summary = array_key_exists('summary', $in) ? self::text($in, 'summary', 255, $f) : null;
        $status = $in['status'] ?? null;
        if (array_key_exists('status', $in) && !in_array($status, ['draft', 'available'], true)) {
            $f['status'] = 'invalid';
        }
        if ($f === [] && $summary === null && $status === null) {
            $f['body'] = 'no_changes';
        }
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $topicId, $version, $summary, $status): void {
            $t = $this->content->lockTopic($topicId) ?? throw ApiError::notFound();
            if (Time::iso($t['updated_at']) !== $version) {
                throw new ApiError(409, 'edit_conflict', 'This topic was changed by someone else. Reload it and apply your change again.',
                    ['current_version' => Time::iso($t['updated_at'])]);
            }
            $newSummary = $summary ?? $t['summary'];
            $newStatus = $status ?? $t['status'];
            if ($newStatus === 'available' && $t['status'] !== 'available') {
                $lesson = $this->content->lockLesson($topicId);
                $pool = $this->content->poolSizeLocked($topicId);
                $min = $this->content->minPoolSize();
                $published = $lesson !== null && $lesson['status'] === 'published';
                if (!$published || $pool < $min) {
                    throw new ApiError(409, 'topic_not_ready', 'The topic needs a published lesson and enough valid quiz questions first.',
                        ['lesson_published' => $published, 'quiz_pool_size' => $pool, 'required_pool_size' => $min]);
                }
            }
            if ($newSummary === $t['summary'] && $newStatus === $t['status']) {
                return;                                                          // nothing changed: no write, no audit
            }
            $this->content->updateTopic($topicId, $newSummary, $newStatus);
            $action = $newStatus !== $t['status'] ? ($newStatus === 'available' ? 'publish' : 'unpublish') : 'update';
            $this->audit->record($adminId, $action, 'topic', $topicId,
                ['summary' => $t['summary'], 'status' => $t['status']], ['summary' => $newSummary, 'status' => $newStatus]);
        });
        foreach ($this->topics() as $view) {
            if ($view['id'] === $topicId) {
                return $view;
            }
        }
        throw ApiError::notFound();
    }

    // ---- lessons -------------------------------------------------------------

    public function lesson(int $topicId): array
    {
        if (!$this->content->topicExists($topicId)) {
            throw ApiError::notFound();
        }
        $l = $this->content->lesson($topicId) ?? throw ApiError::notFound();
        return $this->lessonView($l);
    }

    private function lessonView(array $l): array
    {
        return ['topic_id' => (int)$l['topic_id'], 'lesson_id' => (int)$l['id'], 'title' => $l['title'], 'body_markdown' => $l['body'],
                'status' => $l['status'], 'revision' => (int)$l['revision'], 'published_at' => Time::iso($l['published_at']),
                'updated_at' => Time::iso($l['updated_at'])];
    }

    /** @return array{lesson:array, created:bool} */
    public function saveLesson(int $adminId, int $topicId, array $in): array
    {
        $f = [];
        $expected = self::revision($in, $f, 0);
        $title = self::text($in, 'title', 120, $f);
        $body = self::text($in, 'body_markdown', 100000, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $created = $this->db->txRetry(function () use ($adminId, $topicId, $expected, $title, $body): bool {
            $this->content->lockTopic($topicId) ?? throw ApiError::notFound();
            $l = $this->content->lockLesson($topicId);
            if ($l === null) {
                if ($expected !== 0) {
                    throw self::conflictRevision(0);
                }
                $id = $this->content->insertLesson($topicId, $title, $body);
                $this->audit->record($adminId, 'create', 'lesson', $id, null, ['topic_id' => $topicId, 'title' => $title, 'body' => $body, 'status' => 'draft', 'revision' => 1]);
                return true;
            }
            if ((int)$l['revision'] !== $expected) {
                throw self::conflictRevision((int)$l['revision']);
            }
            $this->content->updateLessonContent((int)$l['id'], $title, $body);
            $this->audit->record($adminId, 'update', 'lesson', (int)$l['id'],
                ['title' => $l['title'], 'body' => $l['body'], 'revision' => (int)$l['revision']],
                ['title' => $title, 'body' => $body, 'revision' => (int)$l['revision'] + 1]);
            return false;
        });
        return ['lesson' => $this->lessonView($this->content->lesson($topicId)), 'created' => $created];
    }

    public function lessonStatus(int $adminId, int $topicId, string $target, array $in): array
    {
        $f = [];
        $expected = self::revision($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $topicId, $target, $expected): void {
            $t = $this->content->lockTopic($topicId) ?? throw ApiError::notFound();
            $l = $this->content->lockLesson($topicId) ?? throw ApiError::notFound();
            if ((int)$l['revision'] !== $expected) {
                throw self::conflictRevision((int)$l['revision']);
            }
            $allowed = ['published' => ['draft', 'retired'], 'draft' => ['published'], 'retired' => ['draft', 'published']][$target];
            if (!in_array($l['status'], $allowed, true)) {
                throw new ApiError(409, 'invalid_transition', "The lesson is {$l['status']}; it cannot become $target.", ['status' => $l['status']]);
            }
            if ($target !== 'published' && $t['status'] === 'available') {
                throw new ApiError(409, 'topic_in_use_set_draft_first', 'Students can open this topic. Set the topic to draft first.');
            }
            $this->content->setLessonStatus((int)$l['id'], $target);
            $action = ['published' => 'publish', 'draft' => 'unpublish', 'retired' => 'retire'][$target];
            $this->audit->record($adminId, $action, 'lesson', (int)$l['id'], ['status' => $l['status'], 'revision' => (int)$l['revision']],
                ['status' => $target, 'revision' => (int)$l['revision']]);
        });
        return $this->lessonView($this->content->lesson($topicId));
    }

    // ---- questions -------------------------------------------------------------

    public function questions(array $query): array
    {
        $f = [];
        $filters = ['topic_id' => null, 'usage' => null, 'status' => null];
        if (isset($query['topic_id'])) {
            (is_string($query['topic_id']) && ctype_digit($query['topic_id']) && (int)$query['topic_id'] >= 1 && (int)$query['topic_id'] <= 255)
                ? $filters['topic_id'] = (int)$query['topic_id'] : $f['topic_id'] = 'invalid';
        }
        if (isset($query['usage'])) {
            in_array($query['usage'], ['quiz', 'assessment'], true) ? $filters['usage'] = $query['usage'] : $f['usage'] = 'invalid';
        }
        if (isset($query['status'])) {
            in_array($query['status'], ['draft', 'active', 'retired'], true) ? $filters['status'] = $query['status'] : $f['status'] = 'invalid';
        }
        [$page, $perPage] = Paging::parse($query, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $r = $this->content->questionPage($filters, $page, $perPage);
        $choices = $this->content->choicesFor(array_map('intval', array_column($r['rows'], 'id')));
        return ['questions' => array_map(fn(array $q): array => $this->questionView($q, $choices[(int)$q['id']] ?? []), $r['rows']),
                'page' => $page, 'per_page' => $perPage, 'total' => $r['total']];
    }

    public function question(int $id): array
    {
        $q = $this->content->question($id) ?? throw ApiError::notFound();
        return $this->questionView($q, $this->content->choicesFor([$id])[$id] ?? []);
    }

    private function questionView(array $q, array $choices): array
    {
        $correct = count(array_filter($choices, static fn($c) => (int)$c['is_correct'] === 1));
        return [
            'id' => (int)$q['id'], 'topic_id' => (int)$q['topic_id'], 'skill_group' => $q['skill_group'],
            'for_quiz' => (int)$q['for_quiz'] === 1, 'for_assessment' => (int)$q['for_assessment'] === 1, 'difficulty' => $q['difficulty'],
            'stem' => $q['stem'], 'code_snippet' => $q['code_snippet'], 'explanation' => $q['explanation'],
            'status' => $q['status'], 'revision' => (int)$q['revision'], 'valid' => count($choices) >= 2 && $correct === 1,
            'used_in_attempts' => (int)$q['used_in_attempts'], 'updated_at' => Time::iso($q['updated_at']), 'retired_at' => Time::iso($q['retired_at']),
            'choices' => array_map(static fn(array $c): array => ['id' => (int)$c['id'], 'label' => $c['label'], 'text' => $c['choice_text'],
                                                                   'is_correct' => (int)$c['is_correct'] === 1], $choices),
        ];
    }

    /** Validate a full question body. $complete = the rules for anything students can receive. */
    private function parseQuestion(array $in, array &$f): array
    {
        $q = [];
        $tid = $in['topic_id'] ?? null;
        if (!is_int($tid) || $tid < 1 || !$this->content->topicExists($tid)) {
            $f['topic_id'] = array_key_exists('topic_id', $in) ? 'invalid' : 'required';
        }
        $q['topic_id'] = $tid;
        foreach (['for_quiz', 'for_assessment'] as $k) {
            if (!array_key_exists($k, $in)) {
                $f[$k] = 'required';
            } elseif (!is_bool($in[$k])) {
                $f[$k] = 'invalid';
            }
            $q[$k] = ($in[$k] ?? false) === true;
        }
        if (!isset($f['for_quiz']) && !isset($f['for_assessment']) && !$q['for_quiz'] && !$q['for_assessment']) {
            $f['for_quiz'] = 'needs_one_usage';
        }
        $q['difficulty'] = $in['difficulty'] ?? null;
        if (!in_array($q['difficulty'], ['easy', 'medium', 'hard'], true)) {
            $f['difficulty'] = array_key_exists('difficulty', $in) ? 'invalid' : 'required';
        }
        $q['stem'] = self::text($in, 'stem', 5000, $f);
        $q['code_snippet'] = self::text($in, 'code_snippet', 5000, $f, false, true);
        $q['explanation'] = self::text($in, 'explanation', 5000, $f, false, true);
        $q['choices'] = [];
        $ch = $in['choices'] ?? null;
        if (!array_key_exists('choices', $in)) {
            $f['choices'] = 'required';
        } elseif (!is_array($ch) || !array_is_list($ch) || count($ch) > 6) {
            $f['choices'] = 'invalid';
        } else {
            $correct = 0;
            foreach ($ch as $i => $c) {
                $okShape = is_array($c) && count($c) === 3 && array_diff(array_keys($c), ['label', 'text', 'is_correct']) === [];
                if (!$okShape || ($c['label'] ?? null) !== self::LABELS[$i] || !is_bool($c['is_correct'] ?? null) || !is_string($c['text'] ?? null)) {
                    $f['choices'] = 'invalid';                                   // labels must be A, B, C… in order
                    break;
                }
                $text = trim(Text::nfc($c['text']));
                if ($text === '' || mb_strlen($text) > 1000 || !Text::isUtf8($c['text'])) {
                    $f['choices'] = 'invalid_text';
                    break;
                }
                $correct += $c['is_correct'] ? 1 : 0;
                $q['choices'][] = ['label' => $c['label'], 'text' => $text, 'is_correct' => $c['is_correct']];
            }
            if (!isset($f['choices']) && $correct > 1) {
                $f['choices'] = 'more_than_one_correct';
            }
        }
        return $q;
    }

    /** Extra rules for a question students can receive (active). */
    private static function assertComplete(array $q, array &$f): void
    {
        if (isset($f['choices'])) {
            return;
        }
        if (count($q['choices']) < 2) {
            $f['choices'] = 'needs_two_choices';
        } elseif (count(array_filter($q['choices'], static fn($c) => $c['is_correct'])) !== 1) {
            $f['choices'] = 'needs_one_correct';
        }
        if (($q['explanation'] ?? null) === null && !isset($f['explanation'])) {
            $f['explanation'] = 'required';
        }
    }

    private static function snapshot(array $q, array $choices): array
    {
        return ['topic_id' => (int)$q['topic_id'], 'for_quiz' => (int)$q['for_quiz'] === 1 || $q['for_quiz'] === true,
                'for_assessment' => (int)$q['for_assessment'] === 1 || $q['for_assessment'] === true, 'difficulty' => $q['difficulty'],
                'stem' => $q['stem'], 'code_snippet' => $q['code_snippet'], 'explanation' => $q['explanation'],
                'choices' => array_map(static fn($c) => ['label' => $c['label'], 'text' => $c['choice_text'] ?? $c['text'],
                                                          'is_correct' => (int)$c['is_correct'] === 1], $choices)];
    }

    public function createQuestion(int $adminId, array $in): array
    {
        $f = [];
        $q = $this->parseQuestion($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $id = $this->db->txRetry(function () use ($adminId, $q): int {
            $this->content->lockTopic($q['topic_id']) ?? throw ApiError::notFound();
            $id = $this->content->insertQuestion($q);
            $this->content->replaceChoices($id, [], $q['choices']);
            $this->audit->record($adminId, 'create', 'question', $id, null, self::snapshot($q, $q['choices']) + ['status' => 'draft', 'revision' => 1]);
            return $id;
        });
        return $this->question($id);
    }

    public function updateQuestion(int $adminId, int $id, array $in): array
    {
        $f = [];
        $expected = self::revision($in, $f);
        unset($in['expected_revision']);
        $q = $this->parseQuestion($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $expected, $q): void {
            $topicHint = $this->content->question($id) ?? throw ApiError::notFound();
            foreach (array_unique([(int)$topicHint['topic_id'], $q['topic_id']]) as $t) {     // topic rows first (lock order)
                $this->content->lockTopic($t);
            }
            $cur = $this->content->lockQuestion($id) ?? throw ApiError::notFound();
            if ((int)$cur['revision'] !== $expected || (int)$cur['topic_id'] !== (int)$topicHint['topic_id']) {
                throw self::conflictRevision((int)$cur['revision']);
            }
            if ($cur['status'] === 'retired') {
                throw new ApiError(409, 'question_retired', 'Retired questions cannot be edited.');
            }
            if ($cur['status'] === 'active') {
                $f = [];
                self::assertComplete($q, $f);
                if ($f !== []) {
                    throw ApiError::validation($f);
                }
                if ((int)$cur['topic_id'] !== $q['topic_id'] || (int)$cur['for_quiz'] !== ($q['for_quiz'] ? 1 : 0)
                    || (int)$cur['for_assessment'] !== ($q['for_assessment'] ? 1 : 0)) {
                    throw new ApiError(409, 'tagging_locked', 'Topic and usage of an active question cannot change. Create a new question instead.');
                }
            }
            $existing = $this->content->lockChoices($id);
            $wanted = array_column($q['choices'], 'label');
            foreach ($existing as $c) {
                if (!in_array($c['label'], $wanted, true) && $this->content->choiceUsed((int)$c['id'])) {
                    throw new ApiError(409, 'choice_in_use', "Choice {$c['label']} was shown in an attempt and cannot be removed.", ['label' => $c['label']]);
                }
            }
            $this->content->updateQuestion($id, $q);
            $this->content->replaceChoices($id, $existing, $q['choices']);
            $this->audit->record($adminId, 'update', 'question', $id,
                self::snapshot($cur, $existing) + ['revision' => (int)$cur['revision']],
                self::snapshot($q, $q['choices']) + ['revision' => (int)$cur['revision'] + 1]);
        });
        return $this->question($id);
    }

    public function activateQuestion(int $adminId, int $id, array $in): array
    {
        $f = [];
        $expected = self::revision($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $expected): void {
            $hint = $this->content->question($id) ?? throw ApiError::notFound();
            $this->content->lockTopic((int)$hint['topic_id']);
            $cur = $this->content->lockQuestion($id) ?? throw ApiError::notFound();
            if ((int)$cur['revision'] !== $expected || (int)$cur['topic_id'] !== (int)$hint['topic_id']) {
                throw self::conflictRevision((int)$cur['revision']);
            }
            if ($cur['status'] !== 'draft') {
                throw new ApiError(409, $cur['status'] === 'retired' ? 'question_retired' : 'invalid_transition', "The question is {$cur['status']}.");
            }
            $choices = $this->content->lockChoices($id);
            $q = self::snapshot($cur, $choices);
            $f = [];
            self::assertComplete(['choices' => array_map(static fn($c) => ['is_correct' => $c['is_correct']], $q['choices']), 'explanation' => $cur['explanation']], $f);
            if (trim((string)$cur['stem']) === '') {
                $f['stem'] = 'required';
            }
            if ($f !== []) {
                throw ApiError::validation($f);
            }
            $this->content->setQuestionStatus($id, 'active');
            $this->audit->record($adminId, 'publish', 'question', $id, ['status' => 'draft', 'revision' => $expected], ['status' => 'active', 'revision' => $expected]);
        });
        return $this->question($id);
    }

    public function retireQuestion(int $adminId, int $id, array $in): array
    {
        $f = [];
        $expected = self::revision($in, $f);
        $confirm = $in['confirm'] ?? false;
        if (!is_bool($confirm)) {
            $f['confirm'] = 'invalid';
        }
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $expected, $confirm): void {
            $hint = $this->content->question($id) ?? throw ApiError::notFound();
            $topicId = (int)$hint['topic_id'];
            $topic = $this->content->lockTopic($topicId);
            $cur = $this->content->lockQuestion($id) ?? throw ApiError::notFound();
            if ((int)$cur['revision'] !== $expected || (int)$cur['topic_id'] !== $topicId) {
                throw self::conflictRevision((int)$cur['revision']);
            }
            if ($cur['status'] === 'retired') {
                throw new ApiError(409, 'question_retired', 'The question is already retired.');
            }
            $wasActive = $cur['status'] === 'active';
            $this->content->setQuestionStatus($id, 'retired');
            if ($wasActive && (int)$cur['for_assessment'] === 1 && ($short = $this->content->assessmentShortfall()) !== null) {
                throw new ApiError(409, 'would_break_assessment_pool', 'The placement assessment would not have enough questions in this skill group.', $short);
            }
            $this->audit->record($adminId, 'retire', 'question', $id, ['status' => $cur['status'], 'revision' => $expected], ['status' => 'retired', 'revision' => $expected]);
            if ($wasActive && (int)$cur['for_quiz'] === 1 && $topic['status'] === 'available') {
                $pool = $this->content->poolSizeLocked($topicId);
                if ($pool < $this->content->minPoolSize()) {
                    if (!$confirm) {
                        throw new ApiError(409, 'would_make_topic_not_ready', 'Retiring this question takes an available topic below the required quiz pool.',
                            ['topic_id' => $topicId, 'quiz_pool_size_after' => $pool, 'required_pool_size' => $this->content->minPoolSize()]);
                    }
                    $this->content->updateTopic($topicId, $topic['summary'], 'draft');
                    $this->audit->record($adminId, 'unpublish', 'topic', $topicId, ['status' => 'available'],
                        ['status' => 'draft', 'reason' => "question $id retired with confirm"]);
                }
            }
        });
        return $this->question($id);
    }
}
