<?php
/** URL/database/account refusal only; no HTTP calls, no positive attestation claim. */
declare(strict_types=1);
require dirname(__DIR__).'/TestSupport.php';
$cases=[
 ['http://localhost/hunter-system/api/v1','hunter_system_dev','hunter_app'],
 ['http://127.0.0.1:8098/hunter-system/api/v1','hunter_system_dev','hunter_app'],
 ['http://localhost:8098/hunter-system/api/v1','hunter_system_m3_fixture','hunter_m7_fixture'],
 ['http://127.0.0.1:8098/hunter-system/api/v1','hunter_system_m3_fixture','root'],
 ['http://127.0.0.1:8097/hunter-system/api/v1','hunter_system_m3_fixture','hunter_m7_fixture'],
];
foreach($cases as $case){try{\Hunter\Tests\requireFixtureBackend(...$case);throw new LogicException('Unsafe target accepted');}catch(RuntimeException $e){if(!str_starts_with($e->getMessage(),'Refusing'))throw $e;}}
echo "5 unsafe fixture targets refused before HTTP/config/database access.\n";
