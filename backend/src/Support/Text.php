<?php

declare(strict_types=1);

namespace Hunter\Support;

use Normalizer;
use RuntimeException;

/**
 * Unicode handling (approved 2026-10-14, decision D-17):
 *   passwords      -> NFKC (as recommended by NIST SP 800-63B), never trimmed
 *   email, names   -> NFC, trimmed
 * Requires the PHP intl extension; the API fails closed (503) without it.
 */
final class Text
{
    public static function intlAvailable(): bool
    {
        return class_exists(Normalizer::class);
    }

    public static function isUtf8(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8');
    }

    public static function nfc(string $s): string
    {
        return self::normalize($s, Normalizer::FORM_C);
    }

    public static function nfkc(string $s): string
    {
        return self::normalize($s, Normalizer::FORM_KC);
    }

    private static function normalize(string $s, int $form): string
    {
        if (!self::intlAvailable()) {
            throw new RuntimeException('PHP intl extension is required for Unicode normalization.');
        }
        $out = Normalizer::normalize($s, $form);
        if ($out === false) {
            throw new RuntimeException('Unicode normalization failed.');
        }
        return $out;
    }

    /** Lower-cased NFC email, or null when it is not a plausible address. */
    public static function normalizeEmail(mixed $raw): ?string
    {
        if (!is_string($raw) || !self::isUtf8($raw) || strlen($raw) > 1024) {
            return null;
        }
        $email = mb_strtolower(self::nfc(trim($raw)), 'UTF-8');
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254) {
            return null;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            return null;
        }
        return $email;
    }

    /** NFC, trimmed display name of 1-80 characters without control characters, or null. */
    public static function normalizeDisplayName(mixed $raw): ?string
    {
        if (!is_string($raw) || !self::isUtf8($raw) || strlen($raw) > 1024) {
            return null;
        }
        $name = trim(self::nfc($raw));
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 1 || $len > 80 || preg_match('/\p{Cc}|\p{Cf}/u', $name)) {
            return null;
        }
        return $name;
    }
}
