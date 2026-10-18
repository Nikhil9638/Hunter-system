<?php

declare(strict_types=1);

namespace Hunter\Services;

/** The authenticated caller, resolved from the server-side session on every request. */
final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,          // from the database, not from the browser
        public readonly int $sessionRowId,
        public readonly string $csrfToken,
    ) {
    }
}
