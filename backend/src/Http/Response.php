<?php

declare(strict_types=1);

namespace Hunter\Http;

/** JSON responses with the envelope from api-contract.md §1. */
final class Response
{
    /** @param array<string,string> $headers */
    public static function data(int $status, mixed $data, array $headers = []): void
    {
        self::send($status, ['data' => $data], $headers);
    }

    public static function error(ApiError $e): void
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->details !== []) {
            $error['details'] = $e->details;
        }
        self::send($e->status, ['error' => $error], $e->headers);
    }

    /** @param array<string,string> $headers */
    private static function send(int $status, array $payload, array $headers): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
