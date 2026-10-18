<?php
/**
 * Router for PHP's built-in server, used ONLY to serve the API against the isolated
 * fixture database (Apache keeps serving hunter_system_dev unchanged):
 *
 *   set HUNTER_PRIVATE_DIR=C:\xampp\hunter-system-fixture-private
 *   php -S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php
 *
 * Only /hunter-system/api/v1/* reaches the front controller; nothing else is served.
 */

declare(strict_types=1);

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
// Fail closed even before registration: this test server must NEVER serve dev.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
$fixtureConfig = \Hunter\Support\Config::load();
$fixturePairs = [
    'hunter_system_m3_fixture' => ['hunter_fixture', 'hunter_m7_fixture'],
    'hunter_system_m7_seed_fixture' => ['hunter_m7_seed'],
    'hunter_system_final_legacy_fixture' => ['hunter_final_legacy'],
];
if (!in_array($fixtureConfig->get('DB_USER'), $fixturePairs[$fixtureConfig->get('DB_NAME')] ?? [], true)
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(503);
    echo 'Refusing unexpected fixture identity or client.';
    return true;
}
if ($path === '/hunter-system/api/v1/__test/identity' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $fixtureDb = new \Hunter\Db\Database($fixtureConfig);
    $identity = $fixtureDb->one('SELECT DATABASE() AS db, SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1) AS account');
    \Hunter\Http\Response::data(200, ['database' => $identity['db'], 'account' => $identity['account']]);
    return true;
}
if (!str_starts_with($path, '/hunter-system/api/v1/')) {
    http_response_code(404);
    return true;
}
require dirname(__DIR__, 2) . '/public/index.php';
