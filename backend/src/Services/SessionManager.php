<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Http\ApiError;
use Hunter\Http\Request;
use Hunter\Repositories\SessionRepository;
use Hunter\Support\Config;
use Hunter\Support\Paths;

/**
 * PHP session handling + session tracking (architecture.md §5.3-5.5).
 *
 *  - Cookie HSSESSID: HttpOnly, SameSite=Strict, Path=/hunter-system/, Secure on HTTPS
 *    (COOKIE_SECURE=auto) or always/never by configuration.
 *  - Session files live in the private directory (outside the web root, not shared
 *    with phpMyAdmin), so other applications' garbage collection cannot remove them.
 *  - $_SESSION holds user_id, role, tracking_token, csrf_token. The database stores
 *    only SHA-256(tracking_token); PHP's session ID is never stored.
 *  - The ID is regenerated on login; logout destroys the session server-side.
 *  - Idle expiry and activity updates follow the session's app policy.
 */
final class SessionManager
{
    public const COOKIE = 'HSSESSID';

    private bool $configured = false;

    public function __construct(
        private Config $config,
        private SessionRepository $sessions,
    ) {
    }

    private function configure(Request $request): void
    {
        if ($this->configured) {
            return;
        }
        $dir = Paths::sessionDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Session directory is not available.');
        }
        session_save_path($dir);
        session_name(self::COOKIE);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', '86400');             // the app enforces the 30-minute idle limit
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->config->basePath() . '/',
            'secure' => $this->secureCookie($request),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $this->configured = true;
    }

    private function secureCookie(Request $request): bool
    {
        return match ($this->config->cookieSecureMode()) {
            'true' => true,
            'false' => false,
            default => $request->https,
        };
    }

    /** The cookie's session ID if it refers to an existing session file, else null. */
    private function existingSessionId(): ?string
    {
        $id = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{22,256}$/', $id)) {
            return null;
        }
        return is_file(Paths::sessionDir() . DIRECTORY_SEPARATOR . 'sess_' . $id) ? $id : null;
    }

    /** Resolve the caller or throw 401. Read-only access to the PHP session (no file lock held). */
    public function authenticate(Request $request): AuthContext
    {
        $this->configure($request);
        if ($this->existingSessionId() === null) {
            if (isset($_COOKIE[self::COOKIE])) {
                $this->clearCookie();
            }
            throw ApiError::notAuthenticated();
        }
        session_start(['read_and_close' => true]);
        $userId = $_SESSION['user_id'] ?? null;
        $token = $_SESSION['tracking_token'] ?? null;
        $csrf = $_SESSION['csrf_token'] ?? null;
        if (!is_int($userId) || !is_string($token) || !is_string($csrf)) {
            $this->destroyPhpSession();
            throw ApiError::notAuthenticated();
        }

        $row = $this->sessions->findForAuth(hash('sha256', $token));
        if ($row === null || $row['ended_at'] !== null || (int)$row['user_id'] !== $userId) {
            $this->destroyPhpSession();
            throw ApiError::notAuthenticated('session_ended', 'Your session has ended. Please log in again.');
        }
        if ((int)$row['idle_expired'] === 1) {
            $this->sessions->expire((int)$row['id'], (int)$row['session_idle_minutes']);
            $this->destroyPhpSession();
            throw ApiError::notAuthenticated('session_expired', 'Your session expired after inactivity. Please log in again.');
        }
        if ($row['user_status'] !== 'active') {
            $this->sessions->end((int)$row['id'], 'ended_by_admin');
            $this->destroyPhpSession();
            throw ApiError::notAuthenticated('session_ended', 'Your session has ended.');
        }
        if ((int)$row['needs_touch'] === 1) {
            $this->sessions->touch((int)$row['id'], (int)$row['activity_touch_seconds']);
        }
        return new AuthContext($userId, (string)$row['role'], (int)$row['id'], $csrf);
    }

    /**
     * Start a fresh authenticated PHP session after the database rows exist.
     * Ends a still-open tracked session of the same browser first, then
     * regenerates the ID (session fixation defence).
     */
    public function beginAuthenticatedSession(Request $request, int $userId, string $role, string $trackingToken): string
    {
        $this->configure($request);
        if ($this->existingSessionId() !== null) {
            session_start();
            $old = $_SESSION['tracking_token'] ?? null;
            if (is_string($old)) {
                $this->sessions->endByHash(hash('sha256', $old), 'logged_out');
            }
        } else {
            session_start();
        }
        session_regenerate_id(true);
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = [
            'user_id' => $userId,
            'role' => $role,
            'tracking_token' => $trackingToken,
            'csrf_token' => $csrf,
        ];
        session_write_close();
        return $csrf;
    }

    public function logout(Request $request, AuthContext $ctx): void
    {
        $this->sessions->end($ctx->sessionRowId, 'logged_out');
        $this->configure($request);
        $this->destroyPhpSession();
    }

    public function assertCsrf(Request $request, AuthContext $ctx): void
    {
        $sent = $request->header('X-CSRF-Token');
        if ($sent === null || !hash_equals($ctx->csrfToken, $sent)) {
            throw new ApiError(403, 'csrf_failed', 'The request could not be verified. Reload and try again.');
        }
    }

    private function destroyPhpSession(): void
    {
        if ($this->existingSessionId() !== null) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION = [];
            session_destroy();
        }
        $this->clearCookie();
    }

    private function clearCookie(): void
    {
        $p = session_get_cookie_params();
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
