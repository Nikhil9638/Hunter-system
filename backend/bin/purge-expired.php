<?php
/**
 * Bounded-retention cleanup outside web traffic (CLI only). The API already purges
 * on every request; schedule this (e.g. Windows Task Scheduler, hourly) so the
 * 24-hour limit also holds while nobody uses the API.
 *
 *   C:\xampp\php\php.exe backend\bin\purge-expired.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Hunter\Db\Database;
use Hunter\Repositories\SessionRepository;
use Hunter\Repositories\ThrottleRepository;
use Hunter\Services\LoginThrottle;
use Hunter\Support\Config;
use Hunter\Support\Console;

Console::requireCli();

$config = Config::load();
$config->assertComplete();
$db = new Database($config);
$throttleRows = (new LoginThrottle($config, new ThrottleRepository($db)))->purgeExpired();
$expired = (new SessionRepository($db))->sweepExpired();
echo "login_throttle rows deleted: $throttleRows; idle sessions marked expired: $expired\n";
