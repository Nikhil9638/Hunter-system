<?php

declare(strict_types=1);

namespace Hunter\Support;

use Hunter\Http\ApiError;
use RuntimeException;

/**
 * Password rules (A-07, D-13, D-17):
 *   - Unicode NFKC normalization before counting, hashing and verifying.
 *   - 12 to 256 characters and at most 1024 bytes (after normalization).
 *   - Spaces and any Unicode allowed; no composition rules; never trimmed.
 *   - Argon2id only. bcrypt would silently ignore everything after 72 bytes.
 *   - Too long is REJECTED, never truncated.
 */
final class PasswordPolicy
{
    public const MIN_CHARS = 12;
    public const MAX_CHARS = 256;
    public const MAX_BYTES = 1024;

    /** Argon2id hash of random data (not a password): equalizes timing for unknown emails. */
    private const TIMING_DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MEwuN2JBY0tuN1pBTkpEQg$dAtWRdlZYtwCtB9oee0R205BTOK59RgUR6MDwi0KYw0';

    public static function argon2idAvailable(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
    }

    /** For registration / admin creation: normalized password or a 400 with a field code. */
    public static function normalizeNew(mixed $raw, string $field = 'password'): string
    {
        $code = self::problem($raw);
        if ($code !== null) {
            throw ApiError::validation([$field => $code]);
        }
        return Text::nfkc($raw);
    }

    /** For login: normalized password, or null when it can't be valid (caller answers generically). */
    public static function normalizeForLogin(mixed $raw): ?string
    {
        return self::problem($raw) === null ? Text::nfkc($raw) : null;
    }

    /** Field error code, or null when acceptable. */
    public static function problem(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return 'required';
        }
        if (strlen($raw) > self::MAX_BYTES * 4) {                          // bound the work before normalizing
            return 'too_long';
        }
        if (!Text::isUtf8($raw)) {
            return 'invalid_encoding';
        }
        $norm = Text::nfkc($raw);
        $chars = mb_strlen($norm, 'UTF-8');
        if ($chars < self::MIN_CHARS) {
            return 'too_short';
        }
        if ($chars > self::MAX_CHARS || strlen($norm) > self::MAX_BYTES) {
            return 'too_long';
        }
        return null;
    }

    public static function hash(string $normalized): string
    {
        if (!self::argon2idAvailable()) {
            throw new RuntimeException('Argon2id is not available in this PHP runtime.');
        }
        return password_hash($normalized, PASSWORD_ARGON2ID);
    }

    public static function verify(string $normalized, string $hash): bool
    {
        return password_verify($normalized, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    /** Burn comparable time when the account does not exist. */
    public static function verifyDummy(?string $normalized): void
    {
        password_verify($normalized ?? 'x', self::TIMING_DUMMY_HASH);
    }
}
