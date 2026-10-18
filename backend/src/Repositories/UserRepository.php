<?php

declare(strict_types=1);

namespace Hunter\Repositories;

use Hunter\Db\Database;

/** users + student_profiles. Grants: SELECT/INSERT users, UPDATE(password_hash, updated_at); SELECT/INSERT student_profiles. */
final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT u.id, u.role, u.email, u.display_name, u.password_hash, u.status, p.onboarding_state
               FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id
              WHERE u.email = ?', [$email]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one(
            'SELECT u.id, u.role, u.email, u.display_name, u.status, p.onboarding_state
               FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id
              WHERE u.id = ?', [$id]);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->one('SELECT 1 AS x FROM users WHERE email = ?', [$email]) !== null;
    }

    /** Role is a parameter of the caller, never of the browser: registration passes 'student'. */
    public function insert(string $role, string $email, string $displayName, string $passwordHash): int
    {
        $this->db->run('INSERT INTO users (role, display_name, email, password_hash) VALUES (?, ?, ?, ?)',
            [$role, $displayName, $email, $passwordHash]);
        return $this->db->lastId();
    }

    public function insertStudentProfile(int $userId): void
    {
        $this->db->run('INSERT INTO student_profiles (user_id) VALUES (?)', [$userId]);
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->run('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?', [$hash, $userId]);
    }
}
