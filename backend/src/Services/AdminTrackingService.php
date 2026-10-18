<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Http\ApiError;
use Hunter\Repositories\AdminOverviewRepository;
use Hunter\Repositories\SessionRepository;
use Hunter\Support\Time;

/** Administrator student tracking (C-21, A-06). No coin balances, no hashes, no session references. */
final class AdminTrackingService
{
    public function __construct(
        private SessionRepository $sessions,
        private AdminOverviewRepository $overview,
    ) {
    }

    /** @param array<string,mixed> $query */
    public function students(array $query): array
    {
        $filters = ['query' => null, 'status' => null, 'recently_active' => null];
        $fields = [];
        if (isset($query['query'])) {
            $q = is_string($query['query']) ? trim($query['query']) : '';
            if ($q === '' || mb_strlen($q) > 100) {
                $fields['query'] = 'invalid';
            } else {
                $filters['query'] = $q;
            }
        }
        if (isset($query['status'])) {
            in_array($query['status'], ['active', 'deactivated'], true) ? $filters['status'] = $query['status'] : $fields['status'] = 'invalid';
        }
        if (isset($query['recently_active'])) {
            in_array($query['recently_active'], ['true', 'false'], true)
                ? $filters['recently_active'] = $query['recently_active'] === 'true'
                : $fields['recently_active'] = 'invalid';
        }
        $page = $this->positiveInt($query['page'] ?? '1', 1, 100000, 'page', $fields);
        $perPage = $this->positiveInt($query['per_page'] ?? '50', 1, 100, 'per_page', $fields);
        if ($fields !== []) {
            throw ApiError::validation($fields);
        }

        $this->sessions->sweepExpired();                                         // closed browsers become 'expired'
        $result = $this->overview->page($filters, $page, $perPage);
        $rows = array_map(static fn(array $r): array => [
            'user_id' => (int)$r['user_id'],
            'display_name' => $r['display_name'],
            'email' => $r['email'],
            'account_status' => $r['account_status'],
            'last_successful_login_at' => Time::iso($r['last_successful_login_at']),
            'last_activity_at' => Time::iso($r['last_activity_at']),
            'recently_active' => (int)$r['recently_active'] === 1,
            'hunter_rank' => $r['hunter_rank'],
            'current_topic' => $r['current_topic'],
            'topics_passed' => (int)$r['topics_passed'],
            'topics_total' => (int)$r['topics_total'],
        ], $result['rows']);
        return ['students' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $result['total']];
    }

    public function student(int $userId): array
    {
        $this->sessions->sweepExpired();
        $s = $this->overview->student($userId) ?? throw ApiError::notFound();
        $placement = null;
        if ($s['placement_attempt_id'] !== null && ($p = $this->overview->placement((int)$s['placement_attempt_id'])) !== null) {
            $placement = ['rank' => $p['rank_code'], 'correct_count' => (int)$p['correct_count'], 'submitted_at' => Time::iso($p['submitted_at']),
                          'recommended_topic' => ['id' => (int)$p['recommended_topic_id'], 'title' => $p['recommended_title']],
                          'offered_topic' => $p['offered_topic_id'] === null ? null : ['id' => (int)$p['offered_topic_id'], 'title' => $p['offered_title']]];
        }
        return [
            'user_id' => (int)$s['user_id'], 'display_name' => $s['display_name'], 'email' => $s['email'], 'account_status' => $s['account_status'],
            'registered_at' => Time::iso($s['created_at']), 'last_successful_login_at' => Time::iso($s['last_successful_login_at']),
            'last_activity_at' => Time::iso($s['last_activity_at']), 'recently_active' => (int)$s['recently_active'] === 1,
            'onboarding_state' => $s['onboarding_state'],
            'experience_level' => $s['exp_code'] === null ? null : ['code' => $s['exp_code'], 'label' => $s['exp_label']],
            'placement' => $placement, 'hunter_rank' => $s['current_code'],
            'topics_passed' => (int)$s['topics_passed'], 'topics_total' => (int)$s['topics_total'],
            'topics' => array_map(static fn(array $t): array => [
                'id' => (int)$t['topic_id'], 'position' => (int)$t['position'], 'title' => $t['title'], 'status' => $t['display_status'],
                'unlocked' => $t['unlocked_at'] !== null, 'unlock_source' => $t['unlock_source'], 'lesson_visits' => (int)$t['lesson_visit_count'],
                'completed_attempts' => (int)$t['completed_attempts'],
                'best_correct' => $t['best_correct_count'] === null ? null : (int)$t['best_correct_count'],
                'first_passed_at' => Time::iso($t['first_passed_at']),
            ], $this->overview->topicStatus($userId)),
            'rank_history' => array_map(static fn(array $h): array => [
                'at' => Time::iso($h['created_at']), 'reason' => $h['reason'], 'from' => $h['from_code'], 'to' => $h['to_code'],
                'topics_passed' => $h['topics_passed'] === null ? null : (int)$h['topics_passed'],
            ], $this->overview->rankHistory($userId)),
        ];
    }

    public function logins(int $userId, array $query): array
    {
        $f = [];
        [$page, $perPage] = Paging::parse($query, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->sessions->sweepExpired();
        $this->overview->student($userId) ?? throw ApiError::notFound();
        $r = $this->overview->logins($userId, $page, $perPage);
        return [
            'events' => array_map(static fn(array $e): array => ['at' => Time::iso($e['occurred_at']), 'outcome' => $e['outcome']], $r['events']),
            'events_total' => $r['events_total'],
            'sessions' => array_map(static fn(array $s): array => [
                'started_at' => Time::iso($s['started_at']), 'last_activity_at' => Time::iso($s['last_activity_at']),
                'ended_at' => Time::iso($s['ended_at']), 'end_reason' => $s['end_reason']], $r['sessions']),
            'sessions_total' => $r['sessions_total'], 'page' => $page, 'per_page' => $perPage,
        ];
    }

    public function loginEvents(array $query): array
    {
        $f = [];
        $outcome = null;
        if (isset($query['outcome'])) {
            in_array($query['outcome'], ['success', 'wrong_password', 'account_deactivated', 'unknown_account'], true)
                ? $outcome = $query['outcome'] : $f['outcome'] = 'invalid';
        }
        $since = Paging::since($query, $f);
        [$page, $perPage] = Paging::parse($query, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $r = $this->overview->loginEvents($outcome, $since, $page, $perPage);
        return [
            'events' => array_map(static fn(array $e): array => [
                'id' => (int)$e['id'], 'at' => Time::iso($e['occurred_at']), 'outcome' => $e['outcome'],
                'user' => $e['user_id'] === null ? null : ['id' => (int)$e['user_id'], 'display_name' => $e['display_name'], 'role' => $e['role']],
            ], $r['rows']),
            'page' => $page, 'per_page' => $perPage, 'total' => $r['total'],
        ];
    }

    private function positiveInt(mixed $raw, int $min, int $max, string $field, array &$fields): int
    {
        if (!is_string($raw) || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
            $fields[$field] = 'invalid';
            return $min;
        }
        return (int)$raw;
    }
}
