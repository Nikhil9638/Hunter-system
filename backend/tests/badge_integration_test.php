<?php
/**
 * Milestone 4 integration tests against the LIVE development store (Apache,
 * hunter_system_dev, API as hunter_app). No badge is approved or published here:
 * the live store is empty and the seeded sample badges stay draft. Purchases,
 * concurrency, catalogue changes and the rollback run against the isolated fixture
 * database (badge_fixture_test.php).
 *
 *   C:\xampp\php\php.exe backend\tests\badge_integration_test.php
 *
 * The admin connection (root; HUNTER_TEST_DB_ADMIN_PASSWORD) only READS rows here.
 * Test accounts: m4.<run>.*@example.invalid.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Tests\Client;

Console::requireCli();
$API = 'http://localhost/hunter-system/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m4_' . $RUN;
@mkdir($TMP);
$config = Config::load();
$API=\Hunter\Tests\seedFixtureApi($config,$API);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'badge test password';
$PREFIX = 'm4';
$FORBIDDEN_KEYS = ['is_correct', 'correct', 'explanation', 'source_choice_id', 'question_id'];

require __DIR__ . '/QuizSupport.php';

/** Everything a purchase or showcase edit must never change. */
function untouchable(PDO $db, int $uid): string
{
    return json_encode([
        q($db, 'SELECT placement_attempt_id, placement_rank_id, current_rank_id, coin_balance FROM student_profiles WHERE user_id = ?', [$uid]),
        qa($db, 'SELECT topic_id, unlocked_at, unlock_source, lesson_visit_count FROM student_topic_progress WHERE user_id = ? ORDER BY topic_id', [$uid]),
        q($db, "SELECT COUNT(*) n FROM rank_history WHERE user_id = ?", [$uid]),
        q($db, "SELECT COUNT(*) n FROM coin_ledger WHERE user_id = ?", [$uid]),
        q($db, "SELECT COUNT(*) n FROM badge_ownership WHERE user_id = ?", [$uid]),
    ]);
}

echo "Hunter System milestone 4 integration tests (API $API, DB " . $config->get('DB_NAME') . ")  run=$RUN\n\n";

$seed = qa($db, 'SELECT id, status, price_status FROM badges WHERE id BETWEEN 1 AND 4 ORDER BY id');
$onSale = (int)q($db, "SELECT COUNT(*) n FROM badges WHERE status = 'on_sale'")['n'];
check('D0 live store state: sample badges 1-4 still draft with provisional prices; nothing on sale',
    count($seed) === 4 && array_unique(array_column($seed, 'status')) === ['draft'] && array_unique(array_column($seed, 'price_status')) === ['provisional'] && $onSale === 0,
    "on_sale=$onSale");

$e = newStudent('e');
$eid = userId($e);
place($e, $db, []);
takeQuiz($e, $db, 1, 0);                                    // failed first completion: 10 coins
$before = untouchable($db, $eid);
$st = $e->req('GET', '/badges');
check('D1 GET /badges on the live store -> 200 with an empty list (a valid store)', $st['status'] === 200 && $st['json']['data'] === [], code($st));

$codes = [];
foreach ([1, 2, 3, 4] as $b) {
    $x = $e->req('POST', "/badges/$b/purchase");
    $codes[] = $x['status'] . ' ' . ($x['json']['error']['code'] ?? '');
}
$nf = $e->req('POST', '/badges/9999/purchase');
check('D2 buying a draft sample badge -> 409 not_on_sale (x4); unknown badge -> 404; no ownership, debit or balance change',
    array_unique($codes) === ['409 not_on_sale'] && $nf['status'] === 404 && untouchable($db, $eid) === $before, implode(', ', $codes) . ' | ' . code($nf));

$b1 = $e->req('POST', '/badges/1/purchase', ['price' => 1]);
$b2 = $e->req('POST', '/badges/1/purchase', null, [], false);
$anon = new Client($API, $TMP, 'anon');
$an = [$anon->req('GET', '/badges')['status'], $anon->req('POST', '/badges/1/purchase')['status'], $anon->req('GET', '/me/profile')['status'],
       $anon->req('PUT', '/me/showcase', ['badge_ids' => []])['status']];
check('D3 a client-sent price -> 400 unknown_field; no CSRF token -> 403; anonymous -> 401 on all four endpoints',
    $b1['status'] === 400 && ($b1['json']['error']['details']['fields']['price'] ?? '') === 'unknown_field' && $b2['status'] === 403 && $an === [401, 401, 401, 401],
    code($b1) . ' | ' . code($b2) . ' | ' . implode(',', $an));

$pr = $e->req('GET', '/me/profile');
$P = $pr['json']['data'] ?? [];
$d = $e->req('GET', '/me/dashboard')['json']['data'];
check('D4 GET /me/profile: exactly the documented fields; ranks and experience separate; values match the dashboard',
    $pr['status'] === 200 && array_keys($P) === ['display_name', 'experience_level', 'placement_rank', 'hunter_rank', 'topics_passed', 'topics_total', 'coins',
        'showcase_limit', 'showcase', 'owned_badges']
    && $P['coins'] === 10 && $P['coins'] === $d['coins'] && $P['hunter_rank'] === $d['hunter_rank'] && $P['placement_rank'] === $d['placement_rank']
    && $P['experience_level'] === $d['experience_level'] && $P['topics_passed'] === $d['topics_passed'] && $P['showcase_limit'] === 3
    && $P['showcase'] === [] && $P['owned_badges'] === [], code($pr));
$keys = array_map('strtolower', allKeysOf($pr['json']));
check('D5 profile carries no private account fields (email, role, ids of sessions, status, hashes)',
    array_intersect($keys, ['email', 'role', 'account_status', 'password_hash', 'user_id', 'session', 'last_login_at']) === []);

$val = [];
foreach ([[['badge_ids' => [1, 2, 3, 4]], 'too_many'], [['badge_ids' => [1, 1]], 'duplicate'], [['badge_ids' => ['1']], 'invalid'],
          [['badge_ids' => [0]], 'invalid'], [['badge_ids' => 1], 'invalid'], [['badge_ids' => ['a' => 1]], 'invalid'], [(object)[], 'required']] as [$body, $want]) {
    $x = $e->req('PUT', '/me/showcase', $body);
    if ($x['status'] !== 400 || ($x['json']['error']['details']['fields']['badge_ids'] ?? '') !== $want) {
        $val[] = json_encode($body) . '=' . code($x);
    }
}
$uk = $e->req('PUT', '/me/showcase', ['badge_ids' => [], 'user_id' => 1]);
check('D6 showcase validation: >3 too_many, duplicate, non-integer/zero/non-list invalid, missing required, extra key unknown_field',
    $val === [] && $uk['status'] === 400 && ($uk['json']['error']['details']['fields']['user_id'] ?? '') === 'unknown_field', implode('; ', $val));
$no = $e->req('PUT', '/me/showcase', ['badge_ids' => [1]]);
$ok0 = $e->req('PUT', '/me/showcase', ['badge_ids' => []]);
$nc = $e->req('PUT', '/me/showcase', ['badge_ids' => []], [], false);
check('D7 showcasing an unowned badge -> 409 not_owned {badge_id 1}; [] -> 200 empty showcase; no CSRF -> 403',
    $no['status'] === 409 && $no['json']['error']['code'] === 'not_owned' && $no['json']['error']['details'] === ['badge_id' => 1]
    && $ok0['status'] === 200 && $ok0['json']['data'] === ['showcase' => []] && $nc['status'] === 403, code($no) . ' | ' . code($ok0));
check('D8 none of the above changed placement, rank, topic progress, ledger, balance or ownership', untouchable($db, $eid) === $before);
$co = $e->req('GET', '/me/coins')['json']['data'];
check('D9 /me/coins entries carry badge_id (null for topic rewards)', count($co['entries']) === 1 && $co['entries'][0]['badge_id'] === null
    && $co['entries'][0]['type'] === 'topic_reward');

$adminEmail = "m4.$RUN.admin@example.invalid";
$adminPw = 'Admin test password ' . bin2hex(random_bytes(4));
$proc = proc_open([PHP_BINARY, \Hunter\Support\Paths::projectRoot() . '/backend/bin/create-admin.php', "--email=$adminEmail", '--display-name=M4 Admin'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "$adminPw\n$adminPw\n");
fclose($pipes[0]);
stream_get_contents($pipes[1]);
proc_close($proc);
$adm = new Client($API, $TMP, 'admin');
$al = $adm->req('POST', '/auth/login', ['email' => $adminEmail, 'password' => $adminPw]);
$adminCodes = [$adm->req('GET', '/badges')['status'], $adm->req('POST', '/badges/1/purchase')['status'], $adm->req('GET', '/me/profile')['status'],
               $adm->req('PUT', '/me/showcase', ['badge_ids' => []])['status']];
check('D10 an administrator -> 403 on all four student store/profile endpoints', $al['status'] === 200 && $adminCodes === [403, 403, 403, 403],
    code($al) . ' | ' . implode(',', $adminCodes));

$app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'), $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$denied = [];
foreach ([
    // badge status/price/creation are writable since M5 (admin endpoints); these stay refused
    'UPDATE badges.slug' => "UPDATE badges SET slug = 'x' WHERE id = 0",
    'DELETE badges' => 'DELETE FROM badges WHERE id = 0',
    'UPDATE audit_log' => "UPDATE audit_log SET action = 'update' WHERE id = 0",
    'UPDATE badge_ownership' => 'UPDATE badge_ownership SET price_paid = 1 WHERE id = 0',
    'DELETE badge_ownership' => 'DELETE FROM badge_ownership WHERE id = 0',
    'UPDATE badge_showcase' => 'UPDATE badge_showcase SET position = 1 WHERE user_id = 0',
    'UPDATE app_policies' => 'UPDATE app_policies SET showcase_limit = 20 WHERE id = 0',
    'UPDATE coin_ledger' => 'UPDATE coin_ledger SET amount = 1 WHERE id = 0',
] as $label => $sql) {
    try {
        $app->exec($sql);
        $denied[] = "$label ALLOWED";
    } catch (PDOException $ex) {
        if (!in_array((int)($ex->errorInfo[1] ?? 0), [1142, 1143], true)) {
            $denied[] = "$label errno " . ($ex->errorInfo[1] ?? '?');
        }
    }
}
check('G1 hunter_app refused 8 store operations outside its grants (badge slug/delete, audit rewrite, ownership, showcase, policy, ledger)',
    $denied === [] && \Hunter\Tests\expectedIntegrationAccount($config), $denied === [] ? 'all refused (1142/1143)' : implode('; ', $denied));

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
$n = (int)q($db, 'SELECT COUNT(*) n FROM users WHERE email LIKE ?', ["m4.$RUN.%"])['n'];
printf("\n%d checks, %d passed, %d failed. Test accounts created in this run: %d (m4.%s.*@example.invalid)\n", count($results), count($results) - $failed, $failed, $n, $RUN);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);

/** Recursively list every key of a decoded JSON value. */
function allKeysOf(mixed $v): array
{
    return \Hunter\Tests\allKeys($v);
}
