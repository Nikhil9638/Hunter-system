<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/** login_throttle (keyed hashes only). Grants: SELECT/INSERT/UPDATE/DELETE login_throttle. */
final class ThrottleRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Seconds until the longest active lock among the keys, or 0 if none is locked.
     *
     * @param list<string> $keyHashes
     */
    public function lockedSeconds(array $keyHashes): int
    {
        if ($keyHashes === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($keyHashes), '?'));
        $row = $this->db->one(
            "SELECT MAX(CEIL(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(3), locked_until) / 1000000)) AS secs
               FROM login_throttle WHERE key_hash IN ($in) AND locked_until > UTC_TIMESTAMP(3)", $keyHashes);
        return max(0, (int)($row['secs'] ?? 0));
    }

    /**
     * Count one failure in a fixed window; lock the key once the count reaches $max.
     * MariaDB evaluates the ON DUPLICATE KEY assignments left to right, so
     * failure_count is updated before locked_until uses it, and window_start is
     * reset last.
     */
    public function recordFailure(string $keyHash, string $keyType, int $max, int $windowSeconds, int $lockSeconds): void
    {
        $this->db->run(
            'INSERT INTO login_throttle (key_hash, key_type, window_start, failure_count, locked_until)
             VALUES (?, ?, UTC_TIMESTAMP(3), 1, IF(1 >= ?, UTC_TIMESTAMP(3) + INTERVAL ? SECOND, NULL))
             ON DUPLICATE KEY UPDATE
               failure_count = IF(window_start < UTC_TIMESTAMP(3) - INTERVAL ? SECOND, 1, failure_count + 1),
               locked_until  = IF(failure_count >= ?, UTC_TIMESTAMP(3) + INTERVAL ? SECOND, locked_until),
               window_start  = IF(window_start < UTC_TIMESTAMP(3) - INTERVAL ? SECOND, UTC_TIMESTAMP(3), window_start),
               updated_at    = UTC_TIMESTAMP(3)',
            [$keyHash, $keyType, $max, $lockSeconds, $windowSeconds, $max, $lockSeconds, $windowSeconds]);
    }

    public function clear(string $keyHash): void
    {
        $this->db->run('DELETE FROM login_throttle WHERE key_hash = ?', [$keyHash]);
    }

    /**
     * Bounded retention: delete rows older than $maxAgeSeconds (absolute, 24 h)
     * and rows whose window has passed and that are not locked.
     */
    public function purgeExpired(int $maxAgeSeconds, int $windowSeconds): int
    {
        return $this->db->run(
            'DELETE FROM login_throttle
              WHERE created_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND
                 OR (updated_at < UTC_TIMESTAMP(3) - INTERVAL ? SECOND
                     AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP(3)))',
            [$maxAgeSeconds, $windowSeconds]);
    }
}
