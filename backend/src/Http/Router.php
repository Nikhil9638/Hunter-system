<?php

declare(strict_types=1);

namespace Hunter\Http;

/**
 * Small method + path router. Patterns use {name} for integer path parameters.
 * Route options: auth => null|'any'|'student'|'admin', csrf => bool.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:callable, options:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        $regex = '#^' . preg_replace('#\{[a-z_]+\}#', '([1-9][0-9]{0,9})', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler,
                           'options' => $options + ['auth' => null, 'csrf' => false]];
    }

    /** @return array{0:callable, 1:list<int>, 2:array} */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                if ($r['method'] === $method) {
                    return [$r['handler'], array_map('intval', array_slice($m, 1)), $r['options']];
                }
                $allowed[] = $r['method'];
            }
        }
        if ($allowed !== []) {
            throw new ApiError(405, 'method_not_allowed', 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw ApiError::notFound();
    }
}
