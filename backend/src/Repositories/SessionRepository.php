<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * user_sessions + login_events + app_policies (C-21..C-23, A-06).
 * Grants: SELECT/INSERT user_sessions, UPDATE(last_activity_at, ended_at, end_reason);
 *         SELECT/INSERT login_events; SELECT app_policies.
 * All time comparisons use the database clock (UTC).
 */
final class SessionRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed> current app policy (idle minutes, touch seconds, ...) */
    public function currentPolicy(): array
    {
        return $this->db->one('SELECT id, recently_active_minutes, session_idle_minutes, activity_touch_seconds FROM app_policies WHERE is_current = 1')
            ?? throw new \RuntimeException('No current app policy.');
    }

    public function create(int $userId, string $refHash, int $policyId): int
    {
        $this->db->run('INSERT INTO user_sessions (user_id, session_ref_hash, app_policy_id) VALUES (?, ?, ?)', [$userId, $refHash, $policyId]);
        return $this->db->lastId();
    }

    /**
     * Session row for a tracking hash, with the account and the idle/touch flags
     * computed by the database clock using the policy the session started under.
     *
     * @return array<string,mixed>|null
     */
    public function findForAuth(string $refHash): ?array
    {
        return $this->db->one(
            'SELECT s.id, s.user_id, s.ended_at, u.role, u.status AS user_status,
                    (s.last_activity_at < UTC_TIMESTAMP(3) - INTERVAL p.session_idle_minutes MINUTE) AS idle_expired,
                    (s.last_activity_at < UTC_TIMESTAMP(3) - INTERVAL p.activity_touch_seconds SECOND) AS needs_touch,
                    p.session_idle_minutes, p.activity_touch_seconds
               FROM user_sessions s
               JOIN users u ON u.id = s.user_id
               JOIN app_policies p ON p.id = s.app_policy_id
              WHERE s.session_ref_hash = ?', [$refHash]);
    }

    /** Throttled activity update (about once per policy interval). */
    public function touch(int $sessionId, int $touchSeconds): void
    {
        $this->db->run(
            'UPDATE user_sessions SET last_activity_at = UTC_TIMESTAMP(3)
              WHERE id = ? AND ended_at IS NULL AND last_activity_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND',
            [$sessionId, $touchSeconds]);
    }

    /** Idle expiry: the end time is last activity + idle limit, not "now". */
    public function expire(int $sessionId, int $idleMinutes): void
    {
        $this->db->run(
            "UPDATE user_sessions SET ended_at = last_activity_at + INTERVAL ? MINUTE, end_reason = 'expired'
              WHERE id = ? AND ended_at IS NULL", [$idleMinutes, $sessionId]);
    }

    /** @param 'logged_out'|'ended_by_admin' $reason */
    public function end(int $sessionId, string $reason): void
    {
        $this->db->run('UPDATE user_sessions SET ended_at = GREATEST(UTC_TIMESTAMP(3), last_activity_at), end_reason = ?
                         WHERE id = ? AND ended_at IS NULL', [$reason, $sessionId]);
    }

    public function endByHash(string $refHash, string $reason): void
    {
        $this->db->run('UPDATE user_sessions SET ended_at = GREATEST(UTC_TIMESTAMP(3), last_activity_at), end_reason = ?
                         WHERE session_ref_hash = ? AND ended_at IS NULL', [$reason, $refHash]);
    }

    /** Expire every idle session (run when the admin list loads). Returns the number ended. */
    public function sweepExpired(): int
    {
        return $this->db->run(
            "UPDATE user_sessions s JOIN app_policies p ON p.id = s.app_policy_id
                SET s.ended_at = s.last_activity_at + INTERVAL p.session_idle_minutes MINUTE, s.end_reason = 'expired'
              WHERE s.ended_at IS NULL
                AND s.last_activity_at < UTC_TIMESTAMP(3) - INTERVAL p.session_idle_minutes MINUTE");
    }

    /** @param 'success'|'wrong_password'|'account_deactivated'|'unknown_account' $outcome */
    public function recordLogin(?int $userId, string $outcome, ?int $sessionId = null): void
    {
        $this->db->run('INSERT INTO login_events (user_id, outcome, session_id) VALUES (?, ?, ?)', [$userId, $outcome, $sessionId]);
    }
}
