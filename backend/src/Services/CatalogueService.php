<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\AuditRepository;
use Hunter\Repositories\CatalogueRepository;
use Hunter\Support\Config;
use Hunter\Support\Text;
use Hunter\Support\Time;
use PDOException;

/**
 * Administrator badge catalogue with explicit price approval (api-contract.md §10.5).
 * Every mutation locks only the badge row, checks the version token, writes the
 * badge and its audit row in one transaction. Ownership and price_paid are never
 * touched; retirement keeps owners and showcases.
 */
final class CatalogueService
{
    /** Approved public artwork location: assets/badges/<name>.<png|webp> (architecture §3). */
    public const IMAGE_REF = '/^badges\/[a-z0-9][a-z0-9-]{0,62}\.(png|webp)$/';
    public const MAX_PRICE = 1000000;

    public function __construct(private Database $db, private CatalogueRepository $badges, private AuditRepository $audit, private Config $config)
    {
    }

    public function all(): array
    {
        return array_map(fn(array $b): array => $this->view($b), $this->badges->all());
    }

    private function view(array $b): array
    {
        $approval = null;
        if ($b['price_status'] === 'approved' && ($a = $this->audit->latestPriceApproval((int)$b['id'])) !== null) {
            $approval = ['approved_by' => ['id' => (int)$a['admin_user_id'], 'display_name' => $a['display_name']],
                         'approved_at' => Time::iso($a['created_at']), 'price' => (int)$a['price']];
        }
        $placeholder = str_starts_with($b['image_ref'], 'placeholder/');
        return [
            'id' => (int)$b['id'], 'slug' => $b['slug'], 'name' => $b['name'], 'description' => $b['description'],
            'image_ref' => $placeholder ? null : $b['image_ref'],
            'image_url' => $placeholder ? null : $this->config->basePath() . '/assets/' . $b['image_ref'],
            'price' => (int)$b['price_coins'], 'price_status' => $b['price_status'], 'status' => $b['status'],
            'version' => Time::iso($b['updated_at']), 'owners' => (int)($b['owners'] ?? 0), 'price_approval' => $approval,
        ];
    }

    private function one(int $id): array
    {
        return $this->view($this->badges->one($id) ?? throw ApiError::notFound());
    }

    // ---- validation ------------------------------------------------------------

    private static function str(array $in, string $key, int $max, array &$f): ?string
    {
        $v = $in[$key] ?? null;
        if (!is_string($v) || !Text::isUtf8($v)) {
            $f[$key] = array_key_exists($key, $in) ? 'invalid' : 'required';
            return null;
        }
        $v = trim(Text::nfc($v));
        if ($v === '' || mb_strlen($v) > $max) {
            $f[$key] = $v === '' ? 'required' : 'too_long';
            return null;
        }
        return $v;
    }

    private static function price(array $in, array &$f): ?int
    {
        $v = $in['price'] ?? null;
        if (!is_int($v) || $v < 1 || $v > self::MAX_PRICE) {
            $f['price'] = array_key_exists('price', $in) ? 'invalid' : 'required';
            return null;
        }
        return $v;
    }

    /** null = no artwork (stored as a placeholder reference); otherwise only the approved path. */
    private static function imageRef(array $in, string $slug, array &$f): ?string
    {
        if (!array_key_exists('image_ref', $in)) {
            $f['image_ref'] = 'required';
            return null;
        }
        $v = $in['image_ref'];
        if ($v === null) {
            return 'placeholder/badges/' . $slug;
        }
        if (!is_string($v) || !preg_match(self::IMAGE_REF, $v)) {
            $f['image_ref'] = 'invalid';                                         // traversal, other folders, executables, svg/html…
            return null;
        }
        return $v;
    }

    private static function version(array $in, array &$f): ?string
    {
        $v = $in['expected_version'] ?? null;
        if (!is_string($v) || $v === '') {
            $f['expected_version'] = array_key_exists('expected_version', $in) ? 'invalid' : 'required';
            return null;
        }
        return $v;
    }

    private static function auditFields(array $b): array
    {
        return ['name' => $b['name'], 'description' => $b['description'], 'image_ref' => $b['image_ref'], 'price_coins' => (int)$b['price_coins'],
                'price_status' => $b['price_status'], 'status' => $b['status']];
    }

    /** Lock + version check (step 1 and only lock of every catalogue change). */
    private function lockChecked(int $id, string $version): array
    {
        $b = $this->badges->lock($id) ?? throw ApiError::notFound();
        if (Time::iso($b['updated_at']) !== $version) {
            throw new ApiError(409, 'edit_conflict', 'This badge was changed by someone else. Reload it and apply your change again.',
                ['current_version' => Time::iso($b['updated_at'])]);
        }
        return $b;
    }

    // ---- mutations -------------------------------------------------------------

    public function create(int $adminId, array $in): array
    {
        $f = [];
        $slug = $in['slug'] ?? null;
        if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{1,59}$/', $slug)) {
            $f['slug'] = array_key_exists('slug', $in) ? 'invalid' : 'required';
            $slug = 'x';
        }
        $name = self::str($in, 'name', 80, $f);
        $description = self::str($in, 'description', 255, $f);
        $image = self::imageRef($in, $slug, $f);
        $price = self::price($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        try {
            $id = $this->db->txRetry(function () use ($adminId, $slug, $name, $description, $image, $price): int {
                if ($this->badges->slugExists($slug)) {
                    throw new ApiError(409, 'slug_taken', 'Another badge already uses this slug.');
                }
                $id = $this->badges->insert($slug, $name, $description, $image, $price);
                $this->audit->record($adminId, 'create', 'badge', $id, null,
                    ['slug' => $slug, 'name' => $name, 'description' => $description, 'image_ref' => $image, 'price_coins' => $price,
                     'price_status' => 'provisional', 'status' => 'draft']);
                return $id;
            });
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new ApiError(409, 'slug_taken', 'Another badge already uses this slug.');
            }
            throw $e;
        }
        return $this->one($id);
    }

    public function update(int $adminId, int $id, array $in): array
    {
        $f = [];
        $version = self::version($in, $f);
        $fields = array_intersect(array_keys($in), ['name', 'description', 'image_ref', 'price']);
        if ($fields === []) {
            $f['body'] = 'no_changes';
        }
        $name = array_key_exists('name', $in) ? self::str($in, 'name', 80, $f) : null;
        $description = array_key_exists('description', $in) ? self::str($in, 'description', 255, $f) : null;
        $price = array_key_exists('price', $in) ? self::price($in, $f) : null;
        $imageGiven = array_key_exists('image_ref', $in);
        $image = $imageGiven ? self::imageRef($in, 'pending', $f) : null;
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $version, $name, $description, $price, $imageGiven, $image, $in): void {
            $b = $this->lockChecked($id, $version);
            if ($b['status'] === 'retired') {
                throw new ApiError(409, 'badge_retired', 'Retired badges cannot be changed.');
            }
            $newImage = $imageGiven ? ($in['image_ref'] === null ? 'placeholder/badges/' . $b['slug'] : $image) : $b['image_ref'];
            $newPrice = $price ?? (int)$b['price_coins'];
            $priceChanged = $newPrice !== (int)$b['price_coins'];
            $after = ['name' => $name ?? $b['name'], 'description' => $description ?? $b['description'], 'image_ref' => $newImage,
                      'price_coins' => $newPrice,
                      // a new price withdraws approval and takes the badge off sale (C-16, O-09)
                      'price_status' => $priceChanged ? 'provisional' : $b['price_status'],
                      'status' => $priceChanged && $b['status'] === 'on_sale' ? 'off_sale' : $b['status']];
            if ($after === self::auditFields($b)) {
                return;                                                          // nothing changed
            }
            $this->badges->write($id, $after['name'], $after['description'], $after['image_ref'], $after['price_coins'], $after['price_status'], $after['status']);
            $this->audit->record($adminId, 'update', 'badge', $id, self::auditFields($b), $after + ($priceChanged ? ['operation' => 'price_change'] : []));
        });
        return $this->one($id);
    }

    public function approvePrice(int $adminId, int $id, array $in): array
    {
        $f = [];
        $version = self::version($in, $f);
        $price = self::price($in, $f);
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $version, $price): void {
            $b = $this->lockChecked($id, $version);
            if ($b['status'] === 'retired') {
                throw new ApiError(409, 'badge_retired', 'Retired badges cannot be changed.');
            }
            if ((int)$b['price_coins'] !== $price) {
                throw new ApiError(409, 'price_mismatch', 'The price you approved is not the current price.', ['current_price' => (int)$b['price_coins']]);
            }
            if ($b['price_status'] === 'approved') {
                throw new ApiError(409, 'already_approved', 'This price is already approved.');
            }
            $this->badges->write($id, $b['name'], $b['description'], $b['image_ref'], $price, 'approved', $b['status']);
            $this->audit->record($adminId, 'update', 'badge', $id, ['price_coins' => $price, 'price_status' => $b['price_status']],
                ['operation' => 'approve_price', 'price_coins' => $price, 'price_status' => 'approved']);
        });
        return $this->one($id);
    }

    public function setStatus(int $adminId, int $id, array $in): array
    {
        $f = [];
        $version = self::version($in, $f);
        $status = $in['status'] ?? null;
        if (!in_array($status, ['on_sale', 'off_sale', 'retired'], true)) {
            $f['status'] = array_key_exists('status', $in) ? 'invalid' : 'required';
        }
        if ($f !== []) {
            throw ApiError::validation($f);
        }
        $this->db->txRetry(function () use ($adminId, $id, $version, $status): void {
            $b = $this->lockChecked($id, $version);
            if ($b['status'] === 'retired') {
                throw new ApiError(409, 'badge_retired', 'Retired badges cannot be changed.');
            }
            if ($b['status'] === $status) {
                throw new ApiError(409, 'invalid_transition', "The badge is already $status.");
            }
            if ($status === 'on_sale' && $b['price_status'] !== 'approved') {
                throw new ApiError(409, 'price_not_approved', 'Approve the current price before putting the badge on sale.');
            }
            $this->badges->write($id, $b['name'], $b['description'], $b['image_ref'], (int)$b['price_coins'], $b['price_status'], $status);
            $action = ['on_sale' => 'publish', 'off_sale' => 'unpublish', 'retired' => 'retire'][$status];
            $this->audit->record($adminId, $action, 'badge', $id, ['status' => $b['status'], 'price_coins' => (int)$b['price_coins']],
                ['status' => $status, 'price_coins' => (int)$b['price_coins'], 'price_status' => $b['price_status']]);
        });
        return $this->one($id);
    }
}
