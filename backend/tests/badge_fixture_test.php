<?php
/**
 * Milestone 4 fixture tests: published catalogue, purchases, concurrency, catalogue
 * changes during a purchase, showcase rules and a controlled rollback - against the
 * ISOLATED fixture database (hunter_system_m3_fixture, account hunter_fixture),
 * never hunter_system_dev.
 *
 *   set HUNTER_PRIVATE_DIR=C:\xampp\hunter-system-fixture-private
 *   php -S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php   (and a 2nd on 8099)
 *   C:\xampp\php\php.exe backend\tests\badge_fixture_test.php
 *
 * The admin connection plays the (future) content administrator: it creates this
 * run's "[FIXTURE]" badges (slug fx-<run>-*), changes their price/status, holds row
 * locks, inspects rows, and - for the rollback case only - temporarily REVOKEs INSERT
 * on coin_ledger from the fixture account (restored in finally). At the end the run's
 * badges are retired so the next run starts with an empty store.
 * Test accounts: m4f.<run>.*@example.invalid.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;
use Hunter\Tests\Client;

use function Hunter\Tests\orderedConcurrent;

Console::requireCli();
const FIXTURE_DB = 'hunter_system_m3_fixture';
const FIXTURE_ACCOUNT = "'hunter_fixture'@'127.0.0.1'";
$config = Config::load();
if ($config->get('DB_NAME') !== FIXTURE_DB || $config->get('DB_USER') !== 'hunter_fixture') {
    fwrite(STDERR, "Refusing to run: HUNTER_PRIVATE_DIR must point at the fixture configuration (" . FIXTURE_DB . ").\n");
    exit(2);
}
$API = 'http://127.0.0.1:8098/hunter-system/api/v1';
$API2 = 'http://127.0.0.1:8099/hunter-system/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m4f_' . $RUN;
@mkdir($TMP);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . FIXTURE_DB . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'badge fixture password';
$PREFIX = 'm4f';
$FORBIDDEN_KEYS = [];

require __DIR__ . '/QuizSupport.php';

function onSecondServer(Client $c): Client
{
    global $API2;
    $c2 = clone $c;
    $c2->api = $API2;
    return $c2;
}
/** A labelled fixture badge in the fixture database only. */
function mkBadge(PDO $db, string $key, int $price, string $status = 'on_sale', string $priceStatus = 'approved', string $image = 'placeholder/badges/fixture.png'): int
{
    global $RUN;
    $st = $db->prepare('INSERT INTO badges (slug, name, description, image_ref, price_coins, price_status, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute(["fx-$RUN-$key", "[FIXTURE] $key", '[FIXTURE] Test badge for automated store tests only; not approved content.', $image, $price, $priceStatus, $status]);
    return (int)$db->lastInsertId();
}
/** Price change as the content rules require: withdraw approval, change, re-approve (one transaction, caller commits). */
function repriceSql(PDO $db, int $badgeId, int $price): void
{
    $db->prepare("UPDATE badges SET status = 'off_sale', price_status = 'provisional', price_coins = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?")->execute([$price, $badgeId]);
    $db->prepare("UPDATE badges SET price_status = 'approved', status = 'on_sale', updated_at = UTC_TIMESTAMP(3) WHERE id = ?")->execute([$badgeId]);
}
/** A student placed S (all topics unlocked) who earns 10 coins per failed first completion. */
function richStudent(PDO $db, string $tag, int $coins): array
{
    $c = newStudent($tag);
    place($c, $db, [1, 2, 3, 4, 5, 6]);
    for ($t = 1; $t <= intdiv($coins, 10); $t++) {
        takeQuiz($c, $db, $t, 0);
    }
    return [$c, userId($c)];
}
function balance(PDO $db, int $uid): int
{
    return (int)q($db, 'SELECT coin_balance b FROM student_profiles WHERE user_id = ?', [$uid])['b'];
}
function untouchable(PDO $db, int $uid): string
{
    return json_encode([
        q($db, 'SELECT placement_attempt_id, placement_rank_id, current_rank_id FROM student_profiles WHERE user_id = ?', [$uid]),
        qa($db, 'SELECT topic_id, unlocked_at, unlock_source, lesson_visit_count FROM student_topic_progress WHERE user_id = ? ORDER BY topic_id', [$uid]),
        q($db, 'SELECT COUNT(*) n FROM rank_history WHERE user_id = ?', [$uid]),
        q($db, "SELECT COUNT(*) n FROM attempts WHERE user_id = ?", [$uid]),
    ]);
}
function showcaseIds(Client $c): array
{
    return array_column($c->req('GET', '/me/profile')['json']['data']['showcase'] ?? [], 'badge_id');
}
function err(array $r): string
{
    return $r['json']['error']['code'] ?? '';
}

echo "Hunter System milestone 4 FIXTURE tests (API $API + $API2, DB " . FIXTURE_DB . ")  run=$RUN\n\n";
// housekeeping of earlier fixture runs (fixture DB only): their badges leave the store
$db->exec("UPDATE badges SET status = 'retired', updated_at = UTC_TIMESTAMP(3) WHERE slug LIKE 'fx-%' AND status <> 'retired'");
$h = [(new Client($API, $TMP, 'h1'))->req('GET', '/health')['status'], (new Client($API2, $TMP, 'h2'))->req('GET', '/health')['status']];
$devOnSale = (int)q($db, "SELECT COUNT(*) n FROM hunter_system_dev.badges WHERE status = 'on_sale' OR slug LIKE 'fx-%'")['n'];
check('F0 both fixture servers healthy; dev database has no on-sale or fixture badges', $h === [200, 200] && $devOnSale === 0, "dev=$devOnSale");

[$s1, $u1] = richStudent($db, 's1', 100);
$empty = $s1->req('GET', '/badges');
check('F1 empty store (only draft sample badges exist) -> 200 []', $empty['status'] === 200 && $empty['json']['data'] === [], code($empty));

$A = mkBadge($db, 'A', 20);
$B = mkBadge($db, 'B', 30, 'on_sale', 'approved', 'fixture/badges/b.png');
$C = mkBadge($db, 'C', 25);
$H = mkBadge($db, 'H', 1000);
$K = mkBadge($db, 'K', 10);
$L = mkBadge($db, 'L', 10);
$Dd = mkBadge($db, 'Ddraft', 5, 'draft', 'provisional');
$Eo = mkBadge($db, 'Eoff', 5, 'off_sale', 'approved');
$Fr = mkBadge($db, 'Fretired', 5, 'retired', 'approved');
$Gp = mkBadge($db, 'Gunapproved', 5, 'off_sale', 'provisional');
$cat = $s1->req('GET', '/badges')['json']['data'] ?? [];
$byId = array_column($cat, null, 'id');
check('F2 published catalogue: exactly the 6 on-sale approved badges, cheapest first; draft/off-sale/retired/unapproved excluded',
    array_column($cat, 'id') === [$K, $L, $A, $C, $B, $H] && !isset($byId[$Dd]) && !isset($byId[$Eo]) && !isset($byId[$Fr]) && !isset($byId[$Gp]),
    implode(',', array_column($cat, 'id')));
check('F3 store item fields; placeholder artwork -> image_url null; other ref -> /hunter-system/assets/<ref>; affordable vs balance 100',
    array_keys($byId[$A]) === ['id', 'name', 'description', 'image_url', 'price', 'status', 'owned', 'affordable'] && $byId[$A]['image_url'] === null
    && $byId[$B]['image_url'] === '/hunter-system/assets/fixture/badges/b.png' && $byId[$A]['price'] === 20 && $byId[$A]['owned'] === false
    && $byId[$A]['affordable'] === true && $byId[$H]['affordable'] === false && $byId[$A]['status'] === 'on_sale');

// ---- purchases
$guard = untouchable($db, $u1);
$p = $s1->req('POST', "/badges/$A/purchase");
$own = q($db, 'SELECT id, price_paid FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u1, $A]);
$deb = q($db, "SELECT direction, amount, balance_after, entry_type, badge_ownership_id FROM coin_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$u1]);
check('P1 purchase A (20) with 100 coins -> 200 {badge_id, name, price_paid 20, balance_after 80, purchased_at}; ownership + exact debit',
    $p['status'] === 200 && array_keys($p['json']['data']) === ['badge_id', 'name', 'price_paid', 'balance_after', 'purchased_at']
    && $p['json']['data']['price_paid'] === 20 && $p['json']['data']['balance_after'] === 80 && (int)$own['price_paid'] === 20
    && $deb['direction'] === 'debit' && (int)$deb['amount'] === 20 && (int)$deb['balance_after'] === 80 && $deb['entry_type'] === 'badge_purchase'
    && (int)$deb['badge_ownership_id'] === (int)$own['id'] && balance($db, $u1) === 80, code($p));
$co = $s1->req('GET', '/me/coins')['json']['data'];
check('P2 /me/coins: balance 80, newest entry is the debit with badge_id A', $co['balance'] === 80 && $co['entries'][0]['type'] === 'badge_purchase'
    && $co['entries'][0]['badge_id'] === $A && $co['entries'][0]['direction'] === 'debit' && $co['entries'][0]['amount'] === 20 && $co['entries'][0]['topic_id'] === null);
$ledgerN = (int)counts($db, $u1)['ledger'];
$again = $s1->req('POST', "/badges/$A/purchase");
check('P3 repeated purchase -> 409 already_owned with the original facts {badge_id, price_paid 20, purchased_at}; no second debit',
    $again['status'] === 409 && err($again) === 'already_owned' && $again['json']['error']['details']['price_paid'] === 20
    && $again['json']['error']['details']['purchased_at'] === $p['json']['data']['purchased_at'] && (int)counts($db, $u1)['ledger'] === $ledgerN
    && balance($db, $u1) === 80, code($again));
$refused = [];
foreach ([$Dd, $Eo, $Fr, $Gp] as $x) {
    $refused[] = err($s1->req('POST', "/badges/$x/purchase"));
}
$poor = $s1->req('POST', "/badges/$H/purchase");
check('P4 draft/off-sale/retired/unapproved -> 409 not_on_sale; 1000-coin badge -> 409 insufficient_coins {balance 80, price 1000}; nothing written',
    array_unique($refused) === ['not_on_sale'] && err($poor) === 'insufficient_coins' && $poor['json']['error']['details'] === ['balance' => 80, 'price' => 1000]
    && (int)counts($db, $u1)['ledger'] === $ledgerN && balance($db, $u1) === 80
    && (int)q($db, 'SELECT COUNT(*) n FROM badge_ownership WHERE user_id = ?', [$u1])['n'] === 1, implode(',', $refused) . ' | ' . code($poor));
$s1->req('POST', "/badges/$B/purchase");
$s1->req('POST', "/badges/$C/purchase");
check('P5 purchases B (30) and C (25): balance 25; store shows them owned and not affordable', balance($db, $u1) === 25
    && array_column(array_filter($s1->req('GET', '/badges')['json']['data'], static fn($b) => $b['owned']), 'id') === [$A, $C, $B]);

// ---- concurrency (two servers; the student's profile lock fixes the arrival order)
[$s2, $u2] = richStudent($db, 's2', 40);
$s2b = onSecondServer($s2);
$release = holdProfileLock($db, $u2);
[$x1, $x2] = orderedConcurrent([[$s2, $s2->asyncHandle('POST', "/badges/$C/purchase")], [$s2b, $s2b->asyncHandle('POST', "/badges/$C/purchase")]], $release);
$own2 = (int)q($db, 'SELECT COUNT(*) n FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u2, $C])['n'];
$deb2 = (int)q($db, "SELECT COUNT(*) n FROM coin_ledger WHERE user_id = ? AND entry_type = 'badge_purchase'", [$u2])['n'];
check('C1 simultaneous purchases of the same badge (2 servers) -> 200 then 409 already_owned; one ownership, one debit, balance 15',
    $x1['status'] === 200 && $x2['status'] === 409 && err($x2) === 'already_owned' && $own2 === 1 && $deb2 === 1 && balance($db, $u2) === 15,
    code($x1) . ' | ' . code($x2));

[$s3, $u3] = richStudent($db, 's3', 40);
$s3b = onSecondServer($s3);
$release = holdProfileLock($db, $u3);
[$y1, $y2] = orderedConcurrent([[$s3, $s3->asyncHandle('POST', "/badges/$B/purchase")], [$s3b, $s3b->asyncHandle('POST', "/badges/$C/purchase")]], $release);
$min = (int)q($db, 'SELECT MIN(balance_after) m FROM coin_ledger WHERE user_id = ?', [$u3])['m'];
check('C2 simultaneous B (30) + C (25) with 40 coins -> 200 then 409 insufficient_coins {balance 10, price 25}; balance 10, never negative',
    $y1['status'] === 200 && err($y2) === 'insufficient_coins' && $y2['json']['error']['details'] === ['balance' => 10, 'price' => 25]
    && balance($db, $u3) === 10 && $min >= 0 && (int)q($db, 'SELECT COUNT(*) n FROM badge_ownership WHERE user_id = ?', [$u3])['n'] === 1,
    code($y1) . ' | ' . code($y2));

// ---- catalogue changes racing a purchase (the admin connection holds the badge row)
[$s4, $u4] = richStudent($db, 's4', 40);
$db->beginTransaction();
repriceSql($db, $K, 35);                                         // uncommitted: 10 -> 35, re-approved
[$z] = orderedConcurrent([[$s4, $s4->asyncHandle('POST', "/badges/$K/purchase")]], static function () use ($db): void { $db->commit(); }, 500);
check('C3 price change committed while the purchase waited on the badge row -> purchase pays the NEW price 35 (balance 5)',
    $z['status'] === 200 && $z['json']['data']['price_paid'] === 35 && $z['json']['data']['balance_after'] === 5 && balance($db, $u4) === 5
    && (int)q($db, 'SELECT price_paid p FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u4, $K])['p'] === 35, code($z));
$db->beginTransaction();
$db->prepare("UPDATE badges SET status = 'off_sale' WHERE id = ?")->execute([$L]);
$ln = (int)counts($db, $u2)['ledger'];
[$w] = orderedConcurrent([[$s2, $s2->asyncHandle('POST', "/badges/$L/purchase")]], static function () use ($db): void { $db->commit(); }, 500);
check('C4 badge taken off sale while the purchase waited -> 409 not_on_sale; no ownership, no debit, balance unchanged (15)',
    err($w) === 'not_on_sale' && (int)counts($db, $u2)['ledger'] === $ln && balance($db, $u2) === 15
    && q($db, 'SELECT id FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u2, $L]) === null, code($w));
$db->beginTransaction();
repriceSql($db, $A, 50);
$db->commit();
$prof = $s1->req('GET', '/me/profile')['json']['data'];
$ownA = array_values(array_filter($prof['owned_badges'], static fn($o) => $o['badge_id'] === $A))[0] ?? [];
$storeA = array_values(array_filter($s1->req('GET', '/badges')['json']['data'], static fn($b) => $b['id'] === $A))[0] ?? [];
check('C5 after A is repriced to 50: the owner still shows price_paid 20 (ledger debit 20); the store shows 50',
    ($ownA['price_paid'] ?? 0) === 20 && ($storeA['price'] ?? 0) === 50
    && (int)q($db, "SELECT l.amount a FROM coin_ledger l JOIN badge_ownership o ON o.id = l.badge_ownership_id WHERE o.user_id = ? AND o.badge_id = ?", [$u1, $A])['a'] === 20);

// ---- profile
$keysOwned = array_keys($ownA);
check('R1 profile: own data only (no email), coins 25, owned A,C,B in purchase order with price_paid, status and showcased flag',
    !array_key_exists('email', $prof) && $prof['coins'] === 25 && array_column($prof['owned_badges'], 'badge_id') === [$A, $B, $C]
    && $keysOwned === ['badge_id', 'name', 'description', 'image_url', 'price_paid', 'purchased_at', 'status', 'showcased']
    && array_column($prof['owned_badges'], 'price_paid') === [20, 30, 25] && $prof['hunter_rank']['code'] === 'S');
$p2 = $s2->req('GET', '/me/profile')['json']['data'];
check('R2 another student\'s profile shows only their own badge (C) and balance; nothing of s1', array_column($p2['owned_badges'], 'badge_id') === [$C] && $p2['coins'] === 15);

// ---- showcase
$sc = [];
foreach ([[], [$A], [$B, $A], [$C, $A, $B]] as $sel) {
    $r = $s1->req('PUT', '/me/showcase', ['badge_ids' => $sel]);
    $sc[] = $r['status'] === 200 && array_column($r['json']['data']['showcase'], 'badge_id') === $sel
        && array_column($r['json']['data']['showcase'], 'position') === ($sel === [] ? [] : range(1, count($sel)))
        && showcaseIds($s1) === $sel;
}
check('S1 showcase sizes 0, 1, 2, 3 accepted; submitted order preserved as positions 1..n', $sc === [true, true, true, true], json_encode($sc));
$bad = [$s1->req('PUT', '/me/showcase', ['badge_ids' => [$A, $B, $C, $H]]), $s1->req('PUT', '/me/showcase', ['badge_ids' => [$A, $A]]),
        $s1->req('PUT', '/me/showcase', ['badge_ids' => [$A, $H]]), $s2->req('PUT', '/me/showcase', ['badge_ids' => [$A]])];
check('S2 4 ids -> too_many; duplicate -> duplicate; unowned H -> 409 not_owned {H}; another student\'s badge A -> 409 not_owned; s1 still [C,A,B]',
    ($bad[0]['json']['error']['details']['fields']['badge_ids'] ?? '') === 'too_many' && ($bad[1]['json']['error']['details']['fields']['badge_ids'] ?? '') === 'duplicate'
    && err($bad[2]) === 'not_owned' && $bad[2]['json']['error']['details'] === ['badge_id' => $H] && err($bad[3]) === 'not_owned'
    && showcaseIds($s1) === [$C, $A, $B] && showcaseIds($s2) === []);
$s1->req('PUT', '/me/showcase', ['badge_ids' => [$B]]);
$rows = qa($db, 'SELECT position, badge_id FROM badge_showcase WHERE user_id = ? ORDER BY position', [$u1]);
check('S3 replacing [C,A,B] with [B] leaves exactly one row (position 1, B)', count($rows) === 1 && (int)$rows[0]['position'] === 1 && (int)$rows[0]['badge_id'] === $B);
$s1b = onSecondServer($s1);
$release = holdProfileLock($db, $u1);
[$v1, $v2] = orderedConcurrent([[$s1, $s1->asyncHandle('PUT', '/me/showcase', ['badge_ids' => [$A, $B]])],
                                 [$s1b, $s1b->asyncHandle('PUT', '/me/showcase', ['badge_ids' => [$C]])]], $release);
$rows = array_map('intval', array_column(qa($db, 'SELECT badge_id FROM badge_showcase WHERE user_id = ? ORDER BY position', [$u1]), 'badge_id'));
check('S4 simultaneous showcase updates (2 servers) are applied one after the other: both 200, final = the later one [C], no mixture',
    $v1['status'] === 200 && $v2['status'] === 200 && array_column($v1['json']['data']['showcase'], 'badge_id') === [$A, $B] && $rows === [$C],
    code($v1) . ' | ' . code($v2) . ' final=' . json_encode($rows));
$s1->req('PUT', '/me/showcase', ['badge_ids' => [$A, $C]]);
$db->exec("UPDATE badges SET status = 'retired', updated_at = UTC_TIMESTAMP(3) WHERE id = $A");
$pr = $s1->req('GET', '/me/profile')['json']['data'];
$re = $s1->req('PUT', '/me/showcase', ['badge_ids' => [$C, $A]]);
$buyRetired = $s3->req('POST', "/badges/$A/purchase");
check('S5 retired A stays owned (status retired) and showcased; re-showcasing it is allowed; it left the store; buying it -> not_on_sale',
    array_column($pr['showcase'], 'badge_id') === [$A, $C] && $pr['showcase'][0]['status'] === 'retired'
    && (array_values(array_filter($pr['owned_badges'], static fn($o) => $o['badge_id'] === $A))[0]['showcased'] ?? false) === true
    && $re['status'] === 200 && array_column($re['json']['data']['showcase'], 'badge_id') === [$C, $A]
    && !in_array($A, array_column($s3->req('GET', '/badges')['json']['data'], 'id'), true) && err($buyRetired) === 'not_on_sale');
check('S6 purchases and showcase edits left placement, rank, topic progress and attempts unchanged; showcasing cost nothing',
    untouchable($db, $u1) === $guard && balance($db, $u1) === 25);

// ---- controlled failure: the ledger debit is refused -> the purchase rolls back
[$s5, $u5] = richStudent($db, 's5', 30);
$pre = [balance($db, $u5), counts($db, $u5)['ledger']];
$db->exec('REVOKE INSERT ON ' . FIXTURE_DB . '.coin_ledger FROM ' . FIXTURE_ACCOUNT);
try {
    $fail = $s5->req('POST', "/badges/$C/purchase");
} finally {
    $db->exec('GRANT INSERT ON ' . FIXTURE_DB . '.coin_ledger TO ' . FIXTURE_ACCOUNT);
}
$errId = $fail['json']['error']['details']['error_id'] ?? '';
$noOwn = q($db, 'SELECT id FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u5, $C]) === null;
check('X1 ledger INSERT refused mid-purchase -> 500 internal_error (error_id logged); ownership rolled back; balance and ledger unchanged',
    $fail['status'] === 500 && err($fail) === 'internal_error' && str_contains((string)@file_get_contents(Paths::logFile()), $errId) && $errId !== ''
    && $noOwn && [balance($db, $u5), counts($db, $u5)['ledger']] === $pre, code($fail));
$ok = $s5->req('POST', "/badges/$C/purchase");
check('X2 after restoring the grant the same purchase succeeds once (price 25, balance 5)', $ok['status'] === 200 && $ok['json']['data']['balance_after'] === 5, code($ok));

// ---- integrity over this run
$ids = array_map('intval', array_column(qa($db, 'SELECT id FROM users WHERE email LIKE ?', ["m4f.$RUN.%"]), 'id'));
$in = implode(',', $ids);
$wallet = qa($db, "SELECT user_id FROM v_wallet_reconciliation WHERE user_id IN ($in) AND NOT (cached_balance = ledger_sum AND cached_balance = last_balance_after)");
$debits = qa($db, "SELECT o.id FROM badge_ownership o LEFT JOIN coin_ledger l ON l.badge_ownership_id = o.id
                    WHERE o.user_id IN ($in) AND (l.id IS NULL OR l.amount <> o.price_paid OR l.direction <> 'debit')");
$neg = qa($db, "SELECT id FROM coin_ledger WHERE user_id IN ($in) AND balance_after < 0");
$show = qa($db, "SELECT user_id FROM badge_showcase WHERE user_id IN ($in) GROUP BY user_id HAVING COUNT(*) > 3 OR MAX(position) <> COUNT(*)");
check('I1 wallets reconcile; every ownership has exactly its debit (= price_paid); no negative balance; showcases <= 3 with positions 1..n',
    $wallet === [] && $debits === [] && $neg === [] && $show === [], count($ids) . ' accounts');

$db->exec("UPDATE badges SET status = 'retired', updated_at = UTC_TIMESTAMP(3) WHERE slug LIKE 'fx-$RUN-%' AND status <> 'retired'");
$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
printf("\n%d checks, %d passed, %d failed. Fixture accounts: %d (m4f.%s.*@example.invalid); this run's fixture badges retired. %s only.\n",
    count($results), count($results) - $failed, $failed, count($ids), $RUN, FIXTURE_DB);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
