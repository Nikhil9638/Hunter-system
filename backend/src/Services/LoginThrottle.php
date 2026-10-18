<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Http\ApiError;
use Hunter\Repositories\ThrottleRepository;
use Hunter\Support\Config;

/**
 * Login throttling (A-07; O-02 approved 2026-10-14).
 *
 * Keys are HMAC-SHA256(THROTTLE_KEY, 'email:<normalized email>') and
 * HMAC-SHA256(THROTTLE_KEY, 'ip:<client IP>'). THROTTLE_KEY lives in the private
 * env file outside the web root, so stored keys cannot be reversed by guessing.
 * No typed email, IP or password is stored.
 *
 * Defaults (overridable in the env file): email key 5 failures / 15 min,
 * IP key 30 failures / 15 min, lock 15 min, absolute retention 24 h.
 */
final class LoginThrottle
{
    private string $secret;
    private int $emailMax;
    private int $ipMax;
    private int $window;
    private int $lock;
    private int $retention;

    public function __construct(Config $config, private ThrottleRepository $repo)
    {
        $this->secret = (string)hex2bin($config->get('THROTTLE_KEY'));
        $this->emailMax = $config->int('THROTTLE_EMAIL_MAX_FAILURES', 5);
        $this->ipMax = $config->int('THROTTLE_IP_MAX_FAILURES', 30);
        $this->window = $config->int('THROTTLE_WINDOW_SECONDS', 900);
        $this->lock = $config->int('THROTTLE_LOCK_SECONDS', 900);
        $this->retention = min($config->int('THROTTLE_RETENTION_SECONDS', 86400), 86400);
    }

    public function emailKey(string $normalizedEmail): string
    {
        return hash_hmac('sha256', 'email:' . $normalizedEmail, $this->secret);
    }

    public function ipKey(string $ip): string
    {
        return hash_hmac('sha256', 'ip:' . $ip, $this->secret);
    }

    /** @param list<string> $keys */
    public function assertNotLocked(array $keys): void
    {
        $secs = $this->repo->lockedSeconds($keys);
        if ($secs > 0) {
            throw new ApiError(429, 'too_many_attempts', 'Too many login attempts. Please wait and try again.',
                ['retry_after_seconds' => $secs], ['Retry-After' => (string)$secs]);
        }
    }

    public function recordFailure(?string $emailKey, string $ipKey): void
    {
        if ($emailKey !== null) {
            $this->repo->recordFailure($emailKey, 'email', $this->emailMax, $this->window, $this->lock);
        }
        $this->repo->recordFailure($ipKey, 'ip', $this->ipMax, $this->window, $this->lock);
    }

    /** A successful login clears that email's counter (the IP counter keeps counting other failures). */
    public function clearEmail(string $emailKey): void
    {
        $this->repo->clear($emailKey);
    }

    public function purgeExpired(): int
    {
        return $this->repo->purgeExpired($this->retention, $this->window);
    }
}
