<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\BadgeRepository;
use Hunter\Repositories\StudentRepository;
use Hunter\Support\Config;
use Hunter\Support\Time;
use PDOException;

/**
 * Badge store, purchases, own profile and showcase (api-contract.md §8, A-05).
 * Lock order: (1) student profile/wallet row FOR UPDATE, (2) badge row in SHARE
 * mode, (3) other rows. A purchase's ownership row and ledger debit commit together.
 * Purchases and showcase edits never touch placement, rank or topic progress.
 */
final class BadgeService
{
    public function __construct(
        private Database $db,
        private BadgeRepository $badges,
        private StudentRepository $students,
        private Config $config,
    ) {
    }

    /** Artwork is not approved: placeholder references have no URL (contract §8). */
    private function imageUrl(string $ref): ?string
    {
        return str_starts_with($ref, 'placeholder/') ? null : $this->config->basePath() . '/assets/' . ltrim($ref, '/');
    }

    public function store(int $userId): array
    {
        $p = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        $balance = (int)$p['coin_balance'];
        return array_map(fn(array $b): array => [
            'id' => (int)$b['id'],
            'name' => $b['name'],
            'description' => $b['description'],
            'image_url' => $this->imageUrl($b['image_ref']),
            'price' => (int)$b['price_coins'],
            'status' => 'on_sale',
            'owned' => (int)$b['owned'] === 1,
            'affordable' => (int)$b['owned'] === 0 && (int)$b['price_coins'] <= $balance,
        ], $this->badges->catalogue($userId));
    }

    public function purchase(int $userId, int $badgeId): array
    {
        try {
            return $this->db->tx(function () use ($userId, $badgeId): array {
                $profile = $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
                $badge = $this->badges->lockBadge($badgeId) ?? throw ApiError::notFound();
                if (($owned = $this->badges->ownership($userId, $badgeId)) !== null) {
                    throw $this->alreadyOwned($badgeId, $owned);
                }
                if ($badge['status'] !== 'on_sale' || $badge['price_status'] !== 'approved') {
                    throw new ApiError(409, 'not_on_sale', 'This badge is not for sale.');
                }
                $price = (int)$badge['price_coins'];
                $balance = (int)$profile['coin_balance'];
                if ($balance < $price) {
                    throw new ApiError(409, 'insufficient_coins', 'You do not have enough coins for this badge.', ['balance' => $balance, 'price' => $price]);
                }
                $ownershipId = $this->badges->insertOwnership($userId, $badgeId, $price);
                $this->badges->insertPurchaseDebit($userId, $price, $balance - $price, $ownershipId);
                $own = $this->badges->ownership($userId, $badgeId);
                return ['badge_id' => $badgeId, 'name' => $badge['name'], 'price_paid' => $price,
                        'balance_after' => $balance - $price, 'purchased_at' => Time::iso($own['purchased_at'])];
            });
        } catch (PDOException $e) {
            // backstop: uq_badge_ownership_once (cannot normally race past the profile lock)
            $owned = Database::isDuplicateKey($e) ? $this->badges->ownership($userId, $badgeId) : null;
            if ($owned === null) {
                throw $e;
            }
            throw $this->alreadyOwned($badgeId, $owned);
        }
    }

    private function alreadyOwned(int $badgeId, array $owned): ApiError
    {
        return new ApiError(409, 'already_owned', 'You already own this badge.',
            ['badge_id' => $badgeId, 'price_paid' => (int)$owned['price_paid'], 'purchased_at' => Time::iso($owned['purchased_at'])]);
    }

    public function profile(int $userId): array
    {
        $p = $this->students->profileSummary($userId) ?? throw ApiError::forbidden();
        return [
            'display_name' => $p['display_name'],
            'experience_level' => $p['exp_code'] === null ? null : ['code' => $p['exp_code'], 'label' => $p['exp_label']],
            'placement_rank' => $p['placement_code'] === null ? null : ['code' => $p['placement_code'], 'name' => $p['placement_name'], 'title' => $p['placement_title']],
            'hunter_rank' => $p['current_code'] === null ? null : ['code' => $p['current_code'], 'name' => $p['current_name'], 'title' => $p['current_title']],
            'topics_passed' => $this->students->topicsPassed($userId),
            'topics_total' => $this->students->topicCount(),
            'coins' => (int)$p['coin_balance'],
            'showcase_limit' => $this->badges->showcaseLimit(),
            'showcase' => $this->showcaseView($userId),
            'owned_badges' => array_map(fn(array $o): array => [
                'badge_id' => (int)$o['badge_id'],
                'name' => $o['name'],
                'description' => $o['description'],
                'image_url' => $this->imageUrl($o['image_ref']),
                'price_paid' => (int)$o['price_paid'],
                'purchased_at' => Time::iso($o['purchased_at']),
                'status' => $o['status'],
                'showcased' => $o['position'] !== null,
            ], $this->badges->ownedBadges($userId)),
        ];
    }

    private function showcaseView(int $userId): array
    {
        return array_map(fn(array $s): array => [
            'position' => (int)$s['position'],
            'badge_id' => (int)$s['badge_id'],
            'name' => $s['name'],
            'image_url' => $this->imageUrl($s['image_ref']),
            'status' => $s['status'],
        ], $this->badges->showcase($userId));
    }

    public function setShowcase(int $userId, array $input): array
    {
        $ids = $input['badge_ids'] ?? null;
        if (!array_key_exists('badge_ids', $input)) {
            throw ApiError::validation(['badge_ids' => 'required']);
        }
        if (!is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn($v): bool => !is_int($v) || $v < 1) !== []) {
            throw ApiError::validation(['badge_ids' => 'invalid']);
        }
        if (count($ids) > $this->badges->showcaseLimit()) {
            throw ApiError::validation(['badge_ids' => 'too_many']);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw ApiError::validation(['badge_ids' => 'duplicate']);
        }
        $this->db->tx(function () use ($userId, $ids): void {
            $this->students->lockProfile($userId) ?? throw ApiError::forbidden();
            $owned = $this->badges->ownedAmong($userId, $ids);
            foreach ($ids as $id) {
                if (!in_array($id, $owned, true)) {
                    throw new ApiError(409, 'not_owned', 'You can only showcase badges you own.', ['badge_id' => $id]);
                }
            }
            $this->badges->replaceShowcase($userId, $ids);
        });
        return ['showcase' => $this->showcaseView($userId)];
    }
}
