<?php

declare(strict_types=1);

namespace Hunter\Services;

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Http\Request;
use Hunter\Repositories\SessionRepository;
use Hunter\Repositories\UserRepository;
use Hunter\Support\PasswordPolicy;
use Hunter\Support\Text;
use PDOException;

/** Registration, login and logout (A-07, C-21..C-23, P-32). */
final class AuthService
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private SessionRepository $sessions,
        private SessionManager $sessionManager,
        private LoginThrottle $throttle,
    ) {
    }

    /** @return array{user:array<string,mixed>, csrf_token:string} */
    public function register(Request $request, array $input): array
    {
        $fields = [];
        $email = Text::normalizeEmail($input['email'] ?? null);
        if ($email === null) {
            $fields['email'] = isset($input['email']) ? 'invalid_format' : 'required';
        }
        $name = Text::normalizeDisplayName($input['display_name'] ?? null);
        if ($name === null) {
            $fields['display_name'] = isset($input['display_name']) ? 'invalid' : 'required';
        }
        $pwProblem = PasswordPolicy::problem($input['password'] ?? null);
        if ($pwProblem !== null) {
            $fields['password'] = $pwProblem;
        }
        if ($fields !== []) {
            throw ApiError::validation($fields);
        }
        if ($this->users->emailExists($email)) {
            throw new ApiError(409, 'email_taken', 'An account with this email already exists.');
        }

        $hash = PasswordPolicy::hash(PasswordPolicy::normalizeNew($input['password']));
        try {
            $userId = $this->db->tx(function () use ($email, $name, $hash): int {
                $id = $this->users->insert('student', $email, $name, $hash);   // role is fixed, never from input
                $this->users->insertStudentProfile($id);
                return $id;
            });
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) {                                  // lost a concurrent registration race
                throw new ApiError(409, 'email_taken', 'An account with this email already exists.');
            }
            throw $e;
        }
        $csrf = $this->startSession($request, $userId, 'student');
        return ['user' => $this->userSelf($userId), 'csrf_token' => $csrf];
    }

    /** @return array{user:array<string,mixed>, csrf_token:string} */
    public function login(Request $request, array $input): array
    {
        $ipKey = $this->throttle->ipKey($request->clientIp);
        $email = Text::normalizeEmail($input['email'] ?? null);
        $emailKey = $email !== null ? $this->throttle->emailKey($email) : null;
        $this->throttle->assertNotLocked(array_values(array_filter([$emailKey, $ipKey])));

        $password = PasswordPolicy::normalizeForLogin($input['password'] ?? null);
        $user = $email !== null ? $this->users->findByEmail($email) : null;

        if ($user === null) {
            PasswordPolicy::verifyDummy($password);                              // similar timing either way
            $this->sessions->recordLogin(null, 'unknown_account');               // no typed value is stored
            $this->throttle->recordFailure($emailKey, $ipKey);
            throw $this->invalidCredentials();
        }
        $userId = (int)$user['id'];
        if ($password === null || !PasswordPolicy::verify($password, (string)$user['password_hash'])) {
            $this->sessions->recordLogin($userId, 'wrong_password');
            $this->throttle->recordFailure($emailKey, $ipKey);
            throw $this->invalidCredentials();
        }
        if ($user['status'] !== 'active') {
            $this->sessions->recordLogin($userId, 'account_deactivated');
            throw new ApiError(403, 'account_deactivated', 'This account is deactivated.');
        }
        if (PasswordPolicy::needsRehash((string)$user['password_hash'])) {
            $this->users->updatePasswordHash($userId, PasswordPolicy::hash($password));
        }
        $this->throttle->clearEmail((string)$emailKey);
        $csrf = $this->startSession($request, $userId, (string)$user['role']);
        return ['user' => $this->userSelf($userId), 'csrf_token' => $csrf];
    }

    public function logout(Request $request, AuthContext $ctx): void
    {
        $this->sessionManager->logout($request, $ctx);
    }

    /** @return array<string,mixed> */
    public function userSelf(int $userId): array
    {
        $u = $this->users->findById($userId) ?? throw ApiError::notAuthenticated();
        $out = [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
        ];
        if ($u['role'] === 'student') {
            $out['onboarding_state'] = $u['onboarding_state'];
        }
        return $out;
    }

    /** Session row + success event in one transaction, then the PHP session (new ID). */
    private function startSession(Request $request, int $userId, string $role): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->tx(function () use ($userId, $token): void {
            $policy = $this->sessions->currentPolicy();
            $sessionId = $this->sessions->create($userId, hash('sha256', $token), (int)$policy['id']);
            $this->sessions->recordLogin($userId, 'success', $sessionId);
        });
        return $this->sessionManager->beginAuthenticatedSession($request, $userId, $role, $token);
    }

    private function invalidCredentials(): ApiError
    {
        return new ApiError(401, 'invalid_credentials', 'Incorrect email or password.');
    }
}
