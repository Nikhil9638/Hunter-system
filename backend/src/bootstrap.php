<?php
/**
 * Hunter System backend bootstrap: PSR-4 style autoloader for the Hunter\ namespace.
 * No framework, no Composer dependencies.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hunter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
