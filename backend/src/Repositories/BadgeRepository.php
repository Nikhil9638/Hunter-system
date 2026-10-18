<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Badge store, ownership and showcase. Explicit columns only.
 *
 * Grants: SELECT badges; SELECT/INSERT badge_ownership; SELECT/INSERT/DELETE
 * badge_showcase (the showcase is replaced as a whole); SELECT app_policies;
 * SELECT/INSERT coin_ledger (milestone 3).
 */
final class BadgeRepository
{
    public function __construct(private Database $db)
    {
    }

    /** On-sale badges with an approved price, with this student's ownership. */
    public function catalogue(int $userId): array
    {
        return $this->db->all(
            "SELECT b.id, b.name, b.description, b.image_ref, b.price_coins, (o.id IS NOT NULL) AS owned
               FROM badges b
               LEFT JOIN badge_ownership o ON o.badge_id = b.id AND o.user_id = ?
              WHERE b.status = 'on_sale' AND b.price_status = 'approved'
              ORDER BY b.price_coins, b.id", [$userId]);
    }

    /**
     * Lock step 2 of a purchase: the badge row in SHARE mode, so a concurrent
     * catalogue change either commits first (and is seen here) or waits for us.
     */
    public function lockBadge(int $badgeId): ?array
    {
        return $this->db->one(
            'SELECT id, name, price_coins, price_status, status FROM badges WHERE id = ? LOCK IN SHARE MODE', [$badgeId]);
    }

    public function ownership(int $userId, int $badgeId): ?array
    {
        return $this->db->one('SELECT id, price_paid, purchased_at FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$userId, $badgeId]);
    }

    public function insertOwnership(int $userId, int $badgeId, int $price): int
    {
        $this->db->run('INSERT INTO badge_ownership (user_id, badge_id, price_paid) VALUES (?, ?, ?)', [$userId, $badgeId, $price]);
        return $this->db->lastId();
    }

    /** Ledger debit; the database checks amount = price_paid, the running balance and >= 0. */
    public function insertPurchaseDebit(int $userId, int $amount, int $balanceAfter, int $ownershipId): void
    {
        $this->db->run("INSERT INTO coin_ledger (user_id, direction, amount, balance_after, entry_type, badge_ownership_id)
                        VALUES (?, 'debit', ?, ?, 'badge_purchase', ?)", [$userId, $amount, $balanceAfter, $ownershipId]);
    }

    /** @return list<array<string,mixed>> in purchase order, with current badge status and showcase position */
    public function ownedBadges(int $userId): array
    {
        return $this->db->all(
            'SELECT o.badge_id, b.name, b.description, b.image_ref, b.status, o.price_paid, o.purchased_at, s.position
               FROM badge_ownership o
               JOIN badges b ON b.id = o.badge_id
               LEFT JOIN badge_showcase s ON s.user_id = o.user_id AND s.badge_id = o.badge_id
              WHERE o.user_id = ?
              ORDER BY o.purchased_at, o.id', [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function showcase(int $userId): array
    {
        return $this->db->all(
            'SELECT s.position, s.badge_id, b.name, b.image_ref, b.status
               FROM badge_showcase s JOIN badges b ON b.id = s.badge_id
              WHERE s.user_id = ? ORDER BY s.position', [$userId]);
    }

    /** @param list<int> $badgeIds @return list<int> the subset this student owns */
    public function ownedAmong(int $userId, array $badgeIds): array
    {
        if ($badgeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($badgeIds), '?'));
        return array_map('intval', array_column(
            $this->db->all("SELECT badge_id FROM badge_ownership WHERE user_id = ? AND badge_id IN ($in)", [$userId, ...$badgeIds]), 'badge_id'));
    }

    /** Replace the whole showcase (call inside the transaction, after the profile lock). */
    public function replaceShowcase(int $userId, array $badgeIds): void
    {
        $this->db->run('DELETE FROM badge_showcase WHERE user_id = ?', [$userId]);
        foreach (array_values($badgeIds) as $i => $badgeId) {
            $this->db->run('INSERT INTO badge_showcase (user_id, position, badge_id) VALUES (?, ?, ?)', [$userId, $i + 1, $badgeId]);
        }
    }

    public function showcaseLimit(): int
    {
        $r = $this->db->one('SELECT showcase_limit FROM app_policies WHERE is_current = 1') ?? throw new \RuntimeException('No current app policy.');
        return (int)$r['showcase_limit'];
    }
}
