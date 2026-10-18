<?php

declare(strict_types=1);

namespace Hunter\Support;

/**
 * File-system locations. Secrets, PHP session files and logs live in a private
 * directory OUTSIDE the web root (default: C:\xampp\hunter-system-private, i.e.
 * two levels above the project folder in htdocs). Override with the
 * HUNTER_PRIVATE_DIR environment variable.
 */
final class Paths
{
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 3);                       // .../htdocs/hunter-system
    }

    public static function privateDir(): string
    {
        $env = getenv('HUNTER_PRIVATE_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '\\/');
        }
        return dirname(self::projectRoot(), 2) . DIRECTORY_SEPARATOR . 'hunter-system-private';
    }

    public static function envFile(): string
    {
        return self::privateDir() . DIRECTORY_SEPARATOR . 'hunter.env';
    }

    public static function sessionDir(): string
    {
        return self::privateDir() . DIRECTORY_SEPARATOR . 'sessions';
    }

    public static function logFile(): string
    {
        return self::privateDir() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    }
}
