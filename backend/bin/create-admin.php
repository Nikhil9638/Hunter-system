<?php
/**
 * Create the first (or another) administrator. CLI only (A-07).
 *
 *   C:\xampp\php\php.exe backend\bin\create-admin.php --email=admin@example.org --display-name="Course Admin"
 *
 * The password is entered interactively twice without echo (never as an argument
 * or environment variable). The same password policy as registration applies:
 * NFKC normalization, 12-256 characters, at most 1024 bytes, Argon2id, no
 * truncation. Uses the restricted application account from the private config.
 * No default password exists anywhere.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Hunter\Db\Database;
use Hunter\Http\ApiError;
use Hunter\Repositories\UserRepository;
use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Logger;
use Hunter\Support\PasswordPolicy;
use Hunter\Support\Text;

Console::requireCli();

$opt = Console::options($argv);
$email = Text::normalizeEmail($opt['email'] ?? null);
$name = Text::normalizeDisplayName($opt['display-name'] ?? null);
if ($email === null || $name === null) {
    fwrite(STDERR, "Usage: php backend/bin/create-admin.php --email=<email> --display-name=\"<name>\"\n");
    exit(2);
}
if (!Text::intlAvailable() || !PasswordPolicy::argon2idAvailable()) {
    fwrite(STDERR, "PHP intl and Argon2id are required.\n");
    exit(1);
}

$config = Config::load();
$config->assertComplete();
$db = new Database($config);
$users = new UserRepository($db);
if ($users->emailExists($email)) {
    fwrite(STDERR, "An account with this email already exists. Nothing changed.\n");
    exit(1);
}

$first = Console::readSecret('New administrator password (12-256 characters)');
$second = Console::readSecret('Repeat the password');
if (!hash_equals($first, $second)) {
    fwrite(STDERR, "The passwords do not match. Nothing changed.\n");
    exit(1);
}
try {
    $normalized = PasswordPolicy::normalizeNew($first);
} catch (ApiError $e) {
    fwrite(STDERR, 'Password rejected: ' . implode(', ', $e->details['fields'] ?? []) . ". Nothing changed.\n");
    exit(1);
}
unset($first, $second);

try {
    $id = $users->insert('admin', $email, $name, PasswordPolicy::hash($normalized));
} catch (PDOException $e) {
    fwrite(STDERR, Database::isDuplicateKey($e) ? "An account with this email already exists.\n" : "Database error.\n");
    exit(1);
}
unset($normalized);
Logger::info('administrator created via CLI', ['user_id' => $id]);           // audit_log needs an acting admin
echo "Administrator created: id $id, $email\n";
