<?php

declare(strict_types=1);

namespace Hunter\Http;

use RuntimeException;

/** An expected, client-visible error: HTTP status + stable code (api-contract.md §1.1). */
final class ApiError extends RuntimeException
{
    /**
     * @param array<string,mixed>  $details
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,string> $fields */
    public static function validation(array $fields): self
    {
        return new self(400, 'validation_failed', 'Some fields are invalid.', ['fields' => $fields]);
    }

    public static function notAuthenticated(string $code = 'not_authenticated', string $message = 'Please log in.'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'You do not have access to this.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Not found.');
    }
}
