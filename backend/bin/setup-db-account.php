<?php
/**
 * Create (or re-sync) the restricted application database account and write the
 * private configuration file. CLI only.
 *
 *   php backend/bin/setup-db-account.php [--db=hunter_system_dev] [--admin-user=root] [--rotate]
 *                                        [--account=hunter_app]
 *
 * - Prompts for the database ADMIN password without echo (press Enter if none).
 * - New account: generates a random password, CREATE USER 'hunter_app'@'127.0.0.1'.
 *   Existing account: keeps its password unless --rotate is given.
 * - Always: REVOKE ALL, then applies database/accounts/hunter_app_grants.sql.
 * - Writes DB_* and THROTTLE_KEY to the private env file (merging other keys) and
 *   restricts the private folder's ACL to the current user, SYSTEM and Administrators.
 * - Never prints the generated password.
 * - --account names another restricted account (e.g. hunter_fixture for an isolated
 *   test database, with HUNTER_PRIVATE_DIR pointing at its own private folder). An
 *   account that already holds grants on a DIFFERENT database is refused, so the
 *   live account can never be re-pointed (and lose its grants) by mistake.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;

Console::requireCli();

const ACCOUNT_HOST = '127.0.0.1';
const EXPECTED_SCHEMA = '2026.10.14-3';

$opt = Console::options($argv);
$dbName = $opt['db'] ?? 'hunter_system_dev';
$adminUser = $opt['admin-user'] ?? 'root';
$rotate = isset($opt['rotate']);
$accountUser = $opt['account'] ?? 'hunter_app';
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName) || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $adminUser)
    || !preg_match('/^hunter_[a-z0-9_]{1,24}$/', $accountUser)) {
    fwrite(STDERR, "Invalid --db, --admin-user or --account.\n");
    exit(2);
}

$adminPassword = Console::readSecret("Database admin password for $adminUser@127.0.0.1 (Enter if none)");
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $adminUser, $adminPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect as $adminUser: " . $e->getMessage() . "\n");
    exit(1);
}
unset($adminPassword);

$version = $pdo->query("SELECT schema_version FROM `$dbName`.schema_info WHERE id = 1")->fetchColumn();
if ($version !== EXPECTED_SCHEMA) {
    fwrite(STDERR, "Database $dbName is at schema " . var_export($version, true) . ', expected ' . EXPECTED_SCHEMA . ".\n");
    exit(1);
}

$account = "'" . $accountUser . "'@'" . ACCOUNT_HOST . "'";
$st = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?');
$st->execute([$accountUser, ACCOUNT_HOST]);
$exists = (int)$st->fetchColumn() > 0;
$st = $pdo->prepare('SELECT Db FROM mysql.db WHERE User = ? AND Host = ? AND Db <> ?
                     UNION SELECT Db FROM mysql.tables_priv WHERE User = ? AND Host = ? AND Db <> ?');
$st->execute([$accountUser, ACCOUNT_HOST, $dbName, $accountUser, ACCOUNT_HOST, $dbName]);
if ($st->fetchColumn() !== false) {
    fwrite(STDERR, "Account $accountUser already has grants on another database; refusing to re-point it to $dbName.\n");
    exit(1);
}

$newPassword = null;
if (!$exists || $rotate) {
    $newPassword = bin2hex(random_bytes(24));                       // 48 hex chars: safe to quote, never printed
    $quoted = $pdo->quote($newPassword);
    $pdo->exec($exists ? "ALTER USER $account IDENTIFIED BY $quoted" : "CREATE USER $account IDENTIFIED BY $quoted");
    unset($quoted);
}

// Exactly the grants in the file: revoke everything first.
$pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
$sql = (string)file_get_contents(Paths::projectRoot() . '/database/accounts/hunter_app_grants.sql');
$sql = str_replace(['{{DB}}', '{{ACCOUNT}}'], ["`$dbName`", $account], $sql);
$applied = 0;
foreach (preg_split('/;\s*(?:\R|$)/', $sql) as $stmt) {
    $lines = array_filter(preg_split('/\R/', $stmt), static fn(string $l): bool => !str_starts_with(trim($l), '--') && trim($l) !== '');
    $stmt = trim(implode("\n", $lines));
    if ($stmt === '') {
        continue;
    }
    if (!preg_match('/^GRANT\s/i', $stmt)) {
        fwrite(STDERR, "Refusing non-GRANT statement in grants file.\n");
        exit(1);
    }
    $pdo->exec($stmt);
    $applied++;
}

// Private configuration (outside the web root).
$dir = Paths::privateDir();
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}
$envFile = Paths::envFile();
$values = is_file($envFile) ? Config::parse((string)file_get_contents($envFile)) : [];
$values = array_merge([
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'APP_BASE_PATH' => '/hunter-system',
    'APP_ORIGINS' => 'http://localhost,http://127.0.0.1,https://localhost,https://127.0.0.1',
    'COOKIE_SECURE' => 'auto',
], $values);
$values['DB_NAME'] = $dbName;
$values['DB_USER'] = $accountUser;
if ($newPassword !== null) {
    $values['DB_PASSWORD'] = $newPassword;
} elseif (($values['DB_PASSWORD'] ?? '') === '') {
    fwrite(STDERR, "The account exists but the env file has no DB_PASSWORD; re-run with --rotate.\n");
    exit(1);
}
if (!preg_match('/^[0-9a-f]{64}$/', $values['THROTTLE_KEY'] ?? '')) {
    $values['THROTTLE_KEY'] = bin2hex(random_bytes(32));
}
$text = "# Hunter System private configuration - generated by backend/bin/setup-db-account.php\n"
      . "# Contains secrets. Never copy into the web root or commit.\n";
foreach ($values as $k => $v) {
    $text .= "$k=$v\n";
}
file_put_contents($envFile, $text, LOCK_EX);
unset($newPassword, $text, $values);

foreach ([Paths::sessionDir(), dirname(Paths::logFile())] as $sub) {
    if (!is_dir($sub)) {
        mkdir($sub, 0700, true);
    }
}
if (PHP_OS_FAMILY === 'Windows') {
    $user = getenv('USERNAME') ?: '';
    // Folder ACL only (inheritable entries), then make every child inherit it.
    // (Applying /inheritance:r with /T to files leaves them with an EMPTY ACL.)
    $cmd = 'icacls ' . escapeshellarg($dir) . ' /inheritance:r /grant:r '
         . escapeshellarg($user . ':(OI)(CI)F') . ' "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" /Q';
    exec($cmd . ' 2>&1', $out, $code);
    if ($code === 0) {
        exec('icacls ' . escapeshellarg($dir . DIRECTORY_SEPARATOR . '*') . ' /reset /T /Q 2>&1', $out, $code);
    }
    echo $code === 0 ? "Private folder ACL restricted to $user, SYSTEM, Administrators.\n" : "WARNING: icacls failed (exit $code).\n";
}

echo "Account " . $accountUser . '@' . ACCOUNT_HOST . ($exists ? ($rotate ? ' password rotated' : ' kept (password unchanged)') : ' created') . ".\n";
echo "Grants applied: $applied statements from database/accounts/hunter_app_grants.sql.\n";
echo "Configuration written to $envFile (credentials not displayed).\n";
