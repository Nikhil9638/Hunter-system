<?php
/**
 * Hunter System API front controller - the only PHP file reachable over HTTP.
 * Apache rewrites /hunter-system/api/v1/* here (see /hunter-system/.htaccess).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

\Hunter\App::handleHttp();
