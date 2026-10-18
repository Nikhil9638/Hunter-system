<?php

declare(strict_types=1);

namespace Hunter\Http;

/** The incoming HTTP request, reduced to what the API needs. */
final class Request
{
    public const MAX_BODY_BYTES = 65536;

    /**
     * @param array<string,string> $headers lower-case names
     * @param array<string,mixed>  $query
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,          // relative to /api/v1, e.g. "/auth/login"
        public readonly array $headers,
        public readonly array $query,
        public readonly string $body,
        public readonly string $clientIp,
        public readonly bool $https,
    ) {
    }

    public static function fromGlobals(string $apiPrefix): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if (strncmp($path, $apiPrefix, strlen($apiPrefix)) !== 0) {
            throw ApiError::notFound();
        }
        $rel = substr($path, strlen($apiPrefix));
        $rel = $rel === '' ? '/' : $rel;

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }

        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            throw new ApiError(413, 'payload_too_large', 'The request body is too large.');
        }
        $body = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new ApiError(413, 'payload_too_large', 'The request body is too large.');
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $rel,
            $headers,
            $_GET,
            $body,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),           // proxies are not trusted
            $https,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * The JSON body as an object. Only the listed keys are accepted (unknown keys
     * such as "role" are rejected, never silently used).
     *
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    public function jsonObject(array $allowed): array
    {
        if ($this->body === '') {
            throw new ApiError(400, 'validation_failed', 'A JSON body is required.', ['fields' => ['body' => 'required']]);
        }
        try {
            $data = json_decode($this->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'invalid_json', 'The request body is not valid JSON.');
        }
        // Associative decoding maps both {} and [] to an empty PHP array.
        // Require an actual JSON object, including in the empty-body case.
        if (!is_array($data) || !str_starts_with(ltrim($this->body), '{')) {
            throw new ApiError(400, 'invalid_json', 'The request body must be a JSON object.');
        }
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ApiError::validation(array_fill_keys(array_map('strval', $unknown), 'unknown_field'));
        }
        return $data;
    }
}
