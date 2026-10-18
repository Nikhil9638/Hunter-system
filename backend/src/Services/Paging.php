<?php

declare(strict_types=1);

namespace Hunter\Services;

/** Bounded pagination and filter parsing shared by administrator list endpoints. */
final class Paging
{
    public const MAX_PAGE = 100000;
    public const MAX_PER_PAGE = 100;

    /** @return array{0:int, 1:int} page, per_page (field errors go to $f) */
    public static function parse(array $query, array &$f, int $defaultPerPage = 50): array
    {
        return [self::int($query, 'page', 1, self::MAX_PAGE, 1, $f), self::int($query, 'per_page', 1, self::MAX_PER_PAGE, $defaultPerPage, $f)];
    }

    public static function int(array $query, string $key, int $min, int $max, ?int $default, array &$f): ?int
    {
        if (!isset($query[$key])) {
            return $default;
        }
        $v = $query[$key];
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 10 || (int)$v < $min || (int)$v > $max) {
            $f[$key] = 'invalid';
            return $default;
        }
        return (int)$v;
    }

    /** ISO-8601 UTC ("2026-10-14T00:00:00Z", optional .mmm) -> DB DATETIME(3) string, or null. */
    public static function since(array $query, array &$f): ?string
    {
        if (!isset($query['since'])) {
            return null;
        }
        $v = $query['since'];
        if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(\.\d{1,3})?Z$/', $v, $m)
            || !checkdate((int)substr($m[1], 5, 2), (int)substr($m[1], 8, 2), (int)substr($m[1], 0, 4))
            || (int)substr($m[2], 0, 2) > 23 || (int)substr($m[2], 3, 2) > 59 || (int)substr($m[2], 6, 2) > 59) {
            $f['since'] = 'invalid';
            return null;
        }
        return $m[1] . ' ' . $m[2] . ($m[3] ?? '.000');
    }
}
