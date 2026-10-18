<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/**
 * Administrator badge catalogue. A catalogue change locks ONLY the badge row
 * (FOR UPDATE); purchases lock the student's wallet, then the badge row in share
 * mode, so the two serialize without a deadlock cycle.
 *
 * Grants: INSERT + UPDATE(name, description, image_ref, price_coins, price_status,
 * status, updated_at) badges. Ownership is never written here.
 */
final class CatalogueRepository
{
    private const COLS = 'b.id, b.slug, b.name, b.description, b.image_ref, b.price_coins, b.price_status, b.status, b.created_at, b.updated_at';

    public function __construct(private Database $db)
    {
    }

    public function all(): array
    {
        return $this->db->all('SELECT ' . self::COLS . ', (SELECT COUNT(*) FROM badge_ownership o WHERE o.badge_id = b.id) AS owners
                                 FROM badges b ORDER BY b.id');
    }

    public function one(int $id): ?array
    {
        return $this->db->one('SELECT ' . self::COLS . ', (SELECT COUNT(*) FROM badge_ownership o WHERE o.badge_id = b.id) AS owners
                                 FROM badges b WHERE b.id = ?', [$id]);
    }

    public function lock(int $id): ?array
    {
        return $this->db->one('SELECT ' . self::COLS . ' FROM badges b WHERE b.id = ? FOR UPDATE', [$id]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->one('SELECT 1 AS x FROM badges WHERE slug = ?', [$slug]) !== null;
    }

    public function insert(string $slug, string $name, string $description, string $imageRef, int $price): int
    {
        $this->db->run("INSERT INTO badges (slug, name, description, image_ref, price_coins, price_status, status) VALUES (?, ?, ?, ?, ?, 'provisional', 'draft')",
            [$slug, $name, $description, $imageRef, $price]);
        return $this->db->lastId();
    }

    /**
     * Write the full mutable state. updated_at always moves forward (it is the
     * optimistic-concurrency version), even for two updates within one millisecond.
     */
    public function write(int $id, string $name, string $description, string $imageRef, int $price, string $priceStatus, string $status): void
    {
        $this->db->run('UPDATE badges SET name = ?, description = ?, image_ref = ?, price_coins = ?, price_status = ?, status = ?,
                               updated_at = GREATEST(UTC_TIMESTAMP(3), updated_at + INTERVAL 1000 MICROSECOND)
                         WHERE id = ?', [$name, $description, $imageRef, $price, $priceStatus, $status, $id]);
    }
}
