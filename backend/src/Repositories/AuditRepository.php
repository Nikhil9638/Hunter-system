<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Append-only administrator audit log. Rows are written inside the same transaction
 * as the content change they describe. Grants: SELECT, INSERT audit_log (UPDATE and
 * DELETE are refused by triggers and not granted).
 */
final class AuditRepository
{
    public const ENTITY_TYPES = ['topic', 'lesson', 'question', 'badge'];
    public const ACTIONS = ['create', 'update', 'publish', 'unpublish', 'retire'];

    public function __construct(private Database $db)
    {
    }

    public function record(int $adminId, string $action, string $entityType, int $entityId, ?array $before, ?array $after): void
    {
        $enc = static fn(?array $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->run('INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id, before_data, after_data) VALUES (?, ?, ?, ?, ?, ?)',
            [$adminId, $action, $entityType, $entityId, $enc($before), $enc($after)]);
    }

    /** @param array{entity_type:?string, entity_id:?int, admin_id:?int, action:?string, since:?string} $f */
    public function page(array $f, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        foreach (['entity_type' => 'a.entity_type', 'entity_id' => 'a.entity_id', 'admin_id' => 'a.admin_user_id', 'action' => 'a.action'] as $k => $col) {
            if ($f[$k] !== null) {
                $where[] = "$col = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['since'] !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $f['since'];
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int)$this->db->one("SELECT COUNT(*) AS n FROM audit_log a $w", $params)['n'];
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT a.id, a.created_at, a.admin_user_id, u.display_name, a.action, a.entity_type, a.entity_id, a.before_data, a.after_data
               FROM audit_log a JOIN users u ON u.id = a.admin_user_id
               $w ORDER BY a.id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Latest price approval of a badge (who, when, which price). */
    public function latestPriceApproval(int $badgeId): ?array
    {
        return $this->db->one(
            "SELECT a.created_at, a.admin_user_id, u.display_name, JSON_VALUE(a.after_data, '$.price_coins') AS price
               FROM audit_log a JOIN users u ON u.id = a.admin_user_id
              WHERE a.entity_type = 'badge' AND a.entity_id = ? AND a.action = 'update'
                AND JSON_VALUE(a.after_data, '$.operation') = 'approve_price'
              ORDER BY a.id DESC LIMIT 1", [$badgeId]);
    }
}
