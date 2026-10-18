<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Administrator student tracking (C-21): list, one student's detail, logins and
 * sessions, all login events. Reads only; no coin balances, hashes or session refs.
 */
final class AdminOverviewRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{query:?string, status:?string, recently_active:?bool} $filters
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function page(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ($filters['query'] !== null) {
            $where[] = '(display_name LIKE ? OR email LIKE ?)';
            $like = '%' . addcslashes($filters['query'], '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($filters['status'] !== null) {
            $where[] = 'account_status = ?';
            $params[] = $filters['status'];
        }
        if ($filters['recently_active'] !== null) {
            $where[] = 'recently_active = ?';
            $params[] = $filters['recently_active'] ? 1 : 0;
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int)$this->db->one("SELECT COUNT(*) AS n FROM v_admin_student_overview $sqlWhere", $params)['n'];
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT user_id, display_name, email, account_status, last_successful_login_at, last_activity_at,
                    recently_active, hunter_rank, current_topic, topics_passed, topics_total
               FROM v_admin_student_overview $sqlWhere
              ORDER BY display_name, user_id
              LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    public function student(int $userId): ?array
    {
        return $this->db->one(
            'SELECT o.user_id, o.display_name, o.email, o.account_status, o.last_successful_login_at, o.last_activity_at,
                    o.recently_active, o.topics_passed, o.topics_total, u.created_at, p.onboarding_state, p.placement_attempt_id,
                    el.code AS exp_code, el.label AS exp_label, cr.code AS current_code
               FROM v_admin_student_overview o
               JOIN users u ON u.id = o.user_id
               JOIN student_profiles p ON p.user_id = o.user_id
               LEFT JOIN experience_levels el ON el.id = p.experience_level_id
               LEFT JOIN ranks cr ON cr.id = p.current_rank_id
              WHERE o.user_id = ?', [$userId]);
    }

    public function placement(int $attemptId): ?array
    {
        return $this->db->one(
            'SELECT r.correct_count, a.submitted_at, rk.code AS rank_code, r.recommended_topic_id, rt.title AS recommended_title,
                    r.offered_topic_id, ot.title AS offered_title
               FROM assessment_results r JOIN attempts a ON a.id = r.attempt_id JOIN ranks rk ON rk.id = r.placement_rank_id
               JOIN topics rt ON rt.id = r.recommended_topic_id LEFT JOIN topics ot ON ot.id = r.offered_topic_id
              WHERE r.attempt_id = ?', [$attemptId]);
    }

    public function topicStatus(int $userId): array
    {
        return $this->db->all(
            'SELECT topic_id, position, title, display_status, unlocked_at, unlock_source, lesson_visit_count, completed_attempts,
                    best_correct_count, first_passed_at
               FROM v_student_topic_status WHERE user_id = ? ORDER BY position', [$userId]);
    }

    public function rankHistory(int $userId): array
    {
        return $this->db->all(
            'SELECT h.created_at, h.reason, fr.code AS from_code, tr.code AS to_code, h.topics_passed
               FROM rank_history h LEFT JOIN ranks fr ON fr.id = h.from_rank_id JOIN ranks tr ON tr.id = h.to_rank_id
              WHERE h.user_id = ? ORDER BY h.id', [$userId]);
    }

    /** @return array{events:list<array>, events_total:int, sessions:list<array>, sessions_total:int} */
    public function logins(int $userId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        return [
            'events' => $this->db->all("SELECT occurred_at, outcome FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT $perPage OFFSET $offset", [$userId]),
            'events_total' => (int)$this->db->one('SELECT COUNT(*) AS n FROM login_events WHERE user_id = ?', [$userId])['n'],
            'sessions' => $this->db->all("SELECT started_at, last_activity_at, ended_at, end_reason FROM user_sessions
                                           WHERE user_id = ? ORDER BY id DESC LIMIT $perPage OFFSET $offset", [$userId]),
            'sessions_total' => (int)$this->db->one('SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = ?', [$userId])['n'],
        ];
    }

    /** @return array{rows:list<array>, total:int} */
    public function loginEvents(?string $outcome, ?string $since, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if ($outcome !== null) {
            $where[] = 'e.outcome = ?';
            $params[] = $outcome;
        }
        if ($since !== null) {
            $where[] = 'e.occurred_at >= ?';
            $params[] = $since;
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;
        return [
            'rows' => $this->db->all("SELECT e.id, e.occurred_at, e.outcome, e.user_id, u.display_name, u.role
                                        FROM login_events e LEFT JOIN users u ON u.id = e.user_id
                                        $w ORDER BY e.id DESC LIMIT $perPage OFFSET $offset", $params),
            'total' => (int)$this->db->one("SELECT COUNT(*) AS n FROM login_events e $w", $params)['n'],
        ];
    }
}
