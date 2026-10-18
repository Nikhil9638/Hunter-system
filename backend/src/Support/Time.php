<?php

declare(strict_types=1);

namespace Hunter\Support;

/** Database DATETIME(3) values are UTC; the API returns ISO-8601 with Z. */
final class Time
{
    public static function iso(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }
        $v = str_replace(' ', 'T', $dbValue);
        if (!str_contains($v, '.')) {
            $v .= '.000';
        }
        return $v . 'Z';
    }
}
