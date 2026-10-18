<?php

declare(strict_types=1);

namespace Hunter\Support;

use RuntimeException;

/**
 * Configuration from the private env file (KEY=VALUE lines, # comments).
 * The file is outside the web root and is never committed; see
 * backend/config/hunter.env.example for the keys.
 */
final class Config
{
    private const REQUIRED = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'THROTTLE_KEY'];

    /** @param array<string,string> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(?string $file = null): self
    {
        $file ??= Paths::envFile();
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Configuration file not found or not readable (see backend/config/hunter.env.example).');
        }
        return new self(self::parse((string)file_get_contents($file)));
    }

    /** @return array<string,string> */
    public static function parse(string $text): array
    {
        $values = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $values[trim(substr($line, 0, $eq))] = trim(substr($line, $eq + 1));
        }
        return $values;
    }

    public function get(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Missing configuration value $key.");
    }

    public function assertComplete(): void
    {
        foreach (self::REQUIRED as $key) {
            if (($this->values[$key] ?? '') === '') {
                throw new RuntimeException("Missing configuration value $key.");
            }
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $this->values['THROTTLE_KEY'])) {
            throw new RuntimeException('THROTTLE_KEY must be 64 hex characters (32 random bytes).');
        }
    }

    /** URL path prefix of the project, e.g. /hunter-system */
    public function basePath(): string
    {
        return rtrim($this->get('APP_BASE_PATH', '/hunter-system'), '/');
    }

    /** @return list<string> allowed Origin values for state-changing requests */
    public function allowedOrigins(): array
    {
        $list = array_map('trim', explode(',', $this->get('APP_ORIGINS', 'http://localhost,http://127.0.0.1,https://localhost,https://127.0.0.1')));
        return array_values(array_filter($list, static fn(string $o): bool => $o !== ''));
    }

    /** 'auto' (Secure when the request is HTTPS), 'true' or 'false'. */
    public function cookieSecureMode(): string
    {
        $mode = strtolower($this->get('COOKIE_SECURE', 'auto'));
        return in_array($mode, ['auto', 'true', 'false'], true) ? $mode : 'auto';
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key, (string)$default);
        return ctype_digit($v) ? (int)$v : $default;
    }
}
