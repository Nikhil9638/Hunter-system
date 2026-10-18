<?php

declare(strict_types=1);

namespace Hunter\Support;

use Throwable;

/**
 * Minimal JSON-lines logger writing to the private log file (outside the web root).
 * Never pass passwords, tokens or request bodies to it.
 */
final class Logger
{
    public static function error(Throwable $e, string $errorId): void
    {
        self::write([
            'level' => 'error',
            'error_id' => $errorId,
            'type' => get_class($e),
            // Exception messages and trace arguments are untrusted: PDO or an
            // unexpected error can include submitted values or credentials.
            'message' => 'Unhandled application exception; sensitive details omitted.',
            'code' => (string)$e->getCode(),
            'at' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_map(static fn(array $frame): array => array_intersect_key(
                $frame, array_flip(['file','line','class','type','function'])
            ), array_slice($e->getTrace(), 0, 12)),
        ]);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write(['level' => 'info', 'message' => $message] + $context);
    }

    private static function write(array $record): void
    {
        $file = Paths::logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $record = ['time' => gmdate('Y-m-d\TH:i:s\Z')] + $record;
        @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
