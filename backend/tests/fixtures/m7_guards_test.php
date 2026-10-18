<?php
/** Read-only negative controls: rejected before account/content writes. */
declare(strict_types=1);
require dirname(__DIR__) . '/TestSupport.php';
$cases = [
    ['http://localhost/hunter-system/api/v1', 'hunter_system_dev', 'hunter_app'],
    ['http://127.0.0.1:8098/hunter-system/api/v1', 'hunter_system_dev', 'hunter_app'],
    ['http://localhost:8098/hunter-system/api/v1', 'hunter_system_m3_fixture', 'hunter_m7_fixture'],
    ['http://127.0.0.1:8098/hunter-system/api/v1', 'hunter_system_m3_fixture', 'root'],
    ['http://127.0.0.1:8097/hunter-system/api/v1', 'hunter_system_m3_fixture', 'hunter_m7_fixture'],
    // Allowed old account name, but the running server is the new account: attestation must reject it.
    ['http://127.0.0.1:8098/hunter-system/api/v1', 'hunter_system_m3_fixture', 'hunter_fixture'],
];
$passed = 0;
foreach ($cases as $case) {
    try { \Hunter\Tests\requireFixtureBackend(...$case); throw new LogicException('Unsafe target was accepted.'); }
    catch (RuntimeException $expected) { if (!str_starts_with($expected->getMessage(), 'Refusing')) throw $expected; ++$passed; }
}
\Hunter\Tests\requireFixtureBackend('http://127.0.0.1:8097/hunter-system/api/v1','hunter_system_m7_seed_fixture','hunter_m7_seed');
\Hunter\Tests\requireFixtureBackend('http://127.0.0.1:8098/hunter-system/api/v1','hunter_system_m3_fixture','hunter_m7_fixture');
\Hunter\Tests\requireFixtureBackend('http://127.0.0.1:8099/hunter-system/api/v1','hunter_system_m3_fixture','hunter_m7_fixture');
echo "$passed unsafe/mismatched targets refused; 3 exact live fixture identities accepted. No mutations sent.\n";
