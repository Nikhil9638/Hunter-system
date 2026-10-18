<?php
/**
 * Milestone 3 fixture tests: rank thresholds, placement floor, final topic, concurrent
 * promotions and a controlled rollback - against the ISOLATED fixture database
 * (hunter_system_m3_fixture, account hunter_fixture), never hunter_system_dev.
 *
 *   set HUNTER_PRIVATE_DIR=C:\xampp\hunter-system-fixture-private
 *   php -S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php   (and a 2nd on 8099)
 *   C:\xampp\php\php.exe backend\tests\quiz_fixture_test.php
 *
 * Two single-threaded built-in servers give real concurrency against one database.
 * The admin connection reads answer keys, holds row locks, inspects rows and - for
 * the rollback case only - temporarily REVOKEs INSERT on coin_ledger from the fixture
 * account (restored in a finally block). Test accounts: m3f.<run>.*@example.invalid.
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
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m3f_' . $RUN;
@mkdir($TMP);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . FIXTURE_DB . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'quiz fixture password';
$PREFIX = 'm3f';
$FORBIDDEN_KEYS = ['is_correct', 'correct', 'explanation', 'source_choice_id', 'question_id'];

require __DIR__ . '/QuizSupport.php';

/** The same session on the second server. */
function onSecondServer(Client $c): Client
{
    global $API2;
    $c2 = clone $c;
    $c2->api = $API2;
    return $c2;
}

/** The promotion field of a result; 'missing' only when the field is absent (null is a valid value). */
function promotionOf(array $r): mixed
{
    $data = $r['json']['data'] ?? [];
    return is_array($data) && array_key_exists('promotion', $data) ? $data['promotion'] : 'missing';
}

echo "Hunter System milestone 3 FIXTURE tests (API $API + $API2, DB " . FIXTURE_DB . ")  run=$RUN\n\n";
$h = [(new Client($API, $TMP, 'h1'))->req('GET', '/health'), (new Client($API2, $TMP, 'h2'))->req('GET', '/health')];
$fixtureLessons = (int)q($db, "SELECT COUNT(*) n FROM lessons WHERE title LIKE '[FIXTURE]%'")['n'];
$ready = (int)q($db, 'SELECT COUNT(*) n FROM v_topic_readiness WHERE is_ready = 1')['n'];
$devUntouched = (int)q($db, "SELECT COUNT(*) n FROM hunter_system_dev.lessons WHERE title LIKE '[FIXTURE]%'")['n'];
check('F0 both fixture servers healthy; 16 labelled fixture lessons, 18 ready topics; dev DB has no fixture content',
    $h[0]['status'] === 200 && $h[1]['status'] === 200 && $fixtureLessons === 16 && $ready === 18 && $devUntouched === 0,
    "lessons=$fixtureLessons ready=$ready dev=$devUntouched");

// ---------------------------------------------------------------------------
// R: one E-placed student passes all 18 topics in order
// ---------------------------------------------------------------------------
$r = newStudent('rank');
$rid = userId($r);
place($r, $db, []);
$expectPromo = [3 => ['E', 'D'], 6 => ['D', 'C'], 9 => ['C', 'B'], 12 => ['B', 'A'], 15 => ['A', 'S']];
$mismatch = [];
$promos = [];
$evidence = [];
for ($t = 1; $t <= 18; $t++) {
    if ($t === 3) {
        // ---- controlled failure: the ledger credit is refused -> the whole submission rolls back
        $aid = (int)startQuiz($r, 3)['json']['data']['attempt_id'];
        answerQuiz($r, $db, $aid, 3);
        $pre = counts($db, $rid);
        $db->exec('REVOKE INSERT ON ' . FIXTURE_DB . '.coin_ledger FROM ' . FIXTURE_ACCOUNT);
        try {
            $fail = $r->req('POST', "/quiz-attempts/$aid/submit");
        } finally {
            $db->exec('GRANT INSERT ON ' . FIXTURE_DB . '.coin_ledger TO ' . FIXTURE_ACCOUNT);
        }
        $post = counts($db, $rid);
        $att = q($db, 'SELECT status, (SELECT COUNT(is_correct) FROM attempt_questions WHERE attempt_id = a.id) graded FROM attempts a WHERE id = ?', [$aid]);
        $t4 = q($db, 'SELECT unlocked_at FROM student_topic_progress WHERE user_id = ? AND topic_id = 4', [$rid]);
        $rank = q($db, 'SELECT r.code FROM student_profiles p JOIN ranks r ON r.id = p.current_rank_id WHERE p.user_id = ?', [$rid])['code'];
        $errId = $fail['json']['error']['details']['error_id'] ?? '';
        $logged = $errId !== '' && str_contains((string)@file_get_contents(Paths::logFile()), $errId);
        check('X1 ledger INSERT refused mid-submission -> 500 internal_error (error_id logged, no internals in the response)',
            $fail['status'] === 500 && $fail['json']['error']['code'] === 'internal_error' && $logged && !str_contains($fail['raw'], 'coin_ledger'), code($fail));
        check('X2 full rollback: attempt still in progress and ungraded; no result, claim, ledger row, unlock or promotion; balance unchanged',
            $att['status'] === 'in_progress' && (int)$att['graded'] === 0 && $pre === $post && $t4['unlocked_at'] === null && $rank === 'E',
            json_encode([$att, $post]));
        // the same attempt then submits normally (handled by the loop below as a resume)
    }
    [$aid, $sub] = takeQuiz($r, $db, $t, 3);
    $R = $sub['json']['data'] ?? [];
    $evidence[$t] = $aid;
    $wantUnlock = $t < 18 ? $t + 1 : null;
    if ($sub['status'] !== 200 || $R['passed'] !== true || ($R['reward']['coins'] ?? 0) !== 10 || ($R['reward']['balance_after'] ?? 0) !== 10 * $t
        || ($R['unlocked_topic']['id'] ?? null) !== $wantUnlock) {
        $mismatch[] = "t$t:" . code($sub) . ' ' . json_encode([$R['reward'] ?? null, $R['unlocked_topic'] ?? null]);
    }
    if ($R['promotion'] !== null) {
        $promos[$t] = $R['promotion'];
    }
    if ($t === 3) {
        check('X3 after restoring the grant the same attempt submits: reward, unlock of topic 4 and E->D promotion together',
            $aid === (int)q($db, "SELECT id FROM attempts WHERE user_id = ? AND topic_id = 3 AND kind = 'topic_quiz' ORDER BY id LIMIT 1", [$rid])['id']
            && ($R['reward']['balance_after'] ?? 0) === 30 && ($R['unlocked_topic']['id'] ?? 0) === 4 && ($R['promotion']['to'] ?? '') === 'D',
            json_encode([$R['reward'] ?? null, $R['promotion'] ?? null]));
    }
}
check('F1 18 topics passed in order: each pays 10 once (balance_after 10..180) and unlocks the next topic; topic 18 unlocks nothing',
    $mismatch === [], implode('; ', $mismatch));
$okPromo = array_keys($promos) === array_keys($expectPromo);
foreach ($expectPromo as $t => [$from, $to]) {
    $okPromo = $okPromo && ($promos[$t] ?? null) === ['from' => $from, 'to' => $to, 'topics_passed' => $t, 'policy_version' => 1];
}
check('F2 promotions exactly at 3 (E->D), 6 (D->C), 9 (C->B), 12 (B->A), 15 (A->S) with topics_passed and policy version 1; none elsewhere',
    $okPromo, json_encode(array_map(static fn($p) => $p['from'] . '>' . $p['to'], $promos)));
$rh = $r->req('GET', '/me/rank-history')['json']['data'] ?? [];
$okRh = count($rh) === 6 && $rh[0]['reason'] === 'placement';
foreach (array_keys($expectPromo) as $i => $t) {
    $okRh = $okRh && $rh[$i + 1]['reason'] === 'promotion' && $rh[$i + 1]['evidence_attempt_id'] === $evidence[$t] && $rh[$i + 1]['topics_passed'] === $t;
}
check('F3 rank history: placement + 5 promotions, each citing the passing attempt and the distinct count', $okRh, count($rh) . ' entries');
$d = $r->req('GET', '/me/dashboard')['json']['data'];
$co = $r->req('GET', '/me/coins?limit=100')['json']['data'];
check('F4 final state: rank S, next_rank null, 18/18 passed, 180 coins = 18 topic_reward ledger entries',
    $d['hunter_rank']['code'] === 'S' && $d['next_rank'] === null && $d['topics_passed'] === 18 && $d['topics_total'] === 18
    && $d['coins'] === 180 && $co['balance'] === 180 && count($co['entries']) === 18, json_encode([$d['hunter_rank']['code'], $d['topics_passed'], $d['coins']]));
$c18 = counts($db, $rid);
[, $again] = takeQuiz($r, $db, 18, 5);
[, $fail18] = takeQuiz($r, $db, 18, 0);
$c18b = counts($db, $rid);
$d2 = $r->req('GET', '/me/dashboard')['json']['data'];
check('F5 final topic re-pass and failed retry: no reward, unlock or promotion; still S, 18 passed; no new claim/ledger/history',
    $again['json']['data']['passed'] === true && $again['json']['data']['reward'] === null && $again['json']['data']['unlocked_topic'] === null
    && $again['json']['data']['promotion'] === null && $fail18['json']['data']['passed'] === false && $d2['hunter_rank']['code'] === 'S'
    && $d2['topics_passed'] === 18 && [$c18['claims'], $c18['ledger'], $c18['ranks']] === [$c18b['claims'], $c18b['ledger'], $c18b['ranks']]);

// ---------------------------------------------------------------------------
// P: placement floor
// ---------------------------------------------------------------------------
$sp = newStudent('splaced');
place($sp, $db, [1, 2, 3, 4, 5, 6]);
$pr = [];
for ($t = 1; $t <= 3; $t++) {
    $pr[] = promotionOf(takeQuiz($sp, $db, $t, 4)[1]);
}
$ds = $sp->req('GET', '/me/dashboard')['json']['data'];
check('P1 placed S passing 3 topics (earned D): no promotion, rank stays S (placement floor; never lowered)',
    $pr === [null, null, null] && $ds['hunter_rank']['code'] === 'S' && $ds['topics_passed'] === 3 && count($sp->req('GET', '/me/rank-history')['json']['data']) === 1);

$c = newStudent('cplaced');
$cid = userId($c);
place($c, $db, [1, 2, 3]);
$dc = $c->req('GET', '/me/dashboard')['json']['data'];
$open = array_values(array_filter($c->req('GET', '/topics')['json']['data'], static fn($t) => $t['unlocked']));
check('P2 placed C: topics 1-10 unlocked by placement', $dc['hunter_rank']['code'] === 'C' && array_column($open, 'id') === range(1, 10));
$pr = [];
for ($t = 1; $t <= 8; $t++) {
    $pr[] = promotionOf(takeQuiz($c, $db, $t, 3)[1]);
}
$dc = $c->req('GET', '/me/dashboard')['json']['data'];
check('P3 placed C passing 8 topics (earned D at 3, C at 6): no promotion; rank C; next rank B needs 1 more',
    array_unique($pr, SORT_REGULAR) === [null] && $dc['hunter_rank']['code'] === 'C' && $dc['topics_passed'] === 8
    && $dc['next_rank']['code'] === 'B' && $dc['next_rank']['topics_remaining'] === 1);

// ---------------------------------------------------------------------------
// C: concurrent submissions for different topics on two servers (promotion + balance)
// ---------------------------------------------------------------------------
$a9 = (int)startQuiz($c, 9)['json']['data']['attempt_id'];
$a10 = (int)startQuiz($c, 10)['json']['data']['attempt_id'];
answerQuiz($c, $db, $a9, 4);
answerQuiz($c, $db, $a10, 3);
$c2 = onSecondServer($c);
$release = holdProfileLock($db, $cid);
[$x9, $x10] = orderedConcurrent([
    [$c, $c->asyncHandle('POST', "/quiz-attempts/$a9/submit")],
    [$c2, $c2->asyncHandle('POST', "/quiz-attempts/$a10/submit")],
], $release);
$cc = counts($db, $cid);
$chain = array_map('intval', array_column(qa($db, 'SELECT balance_after FROM coin_ledger WHERE user_id = ? ORDER BY id', [$cid]), 'balance_after'));
$P9 = promotionOf($x9);
$P10 = promotionOf($x10);
check('C1 topics 9 and 10 submitted concurrently (2 servers): both 200 and rewarded (90, 100); ledger chain 10..100 unbroken',
    $x9['status'] === 200 && $x10['status'] === 200 && $x9['json']['data']['reward']['balance_after'] === 90
    && $x10['json']['data']['reward']['balance_after'] === 100 && $chain === range(10, 100, 10) && (int)$cc['balance'] === 100,
    code($x9) . ' | ' . code($x10));
$dc = $c->req('GET', '/me/dashboard')['json']['data'];
$rhc = $c->req('GET', '/me/rank-history')['json']['data'];
check('C2 exactly one promotion C->B (topics_passed 9, on the first-committed submit); none on the second (10); rank B',
    $P9 === ['from' => 'C', 'to' => 'B', 'topics_passed' => 9, 'policy_version' => 1] && $P10 === null && $dc['hunter_rank']['code'] === 'B'
    && $dc['topics_passed'] === 10 && count($rhc) === 2 && $rhc[1]['evidence_attempt_id'] === $a9, json_encode([$P9, $P10]));
check('C3 unlocks: topic 9 unlocked nothing (10 was open from placement); topic 10 unlocked 11',
    $x9['json']['data']['unlocked_topic'] === null && ($x10['json']['data']['unlocked_topic']['id'] ?? 0) === 11
    && topicRow($c, 11)['unlock_source'] === 'passed_previous');

// ---------------------------------------------------------------------------
// I: integrity over this run's accounts
// ---------------------------------------------------------------------------
$ids = array_map('intval', array_column(qa($db, 'SELECT id FROM users WHERE email LIKE ?', ["m3f.$RUN.%"]), 'id'));
$in = implode(',', $ids);
$wallet = qa($db, "SELECT user_id FROM v_wallet_reconciliation WHERE user_id IN ($in) AND NOT (cached_balance = ledger_sum AND cached_balance = last_balance_after)");
$rankMatch = qa($db, "SELECT p.user_id FROM student_profiles p WHERE p.user_id IN ($in)
                       AND p.current_rank_id <> (SELECT h.to_rank_id FROM rank_history h WHERE h.user_id = p.user_id ORDER BY h.id DESC LIMIT 1)");
$promoCount = qa($db, "SELECT h.id FROM rank_history h WHERE h.user_id IN ($in) AND h.reason = 'promotion'
                        AND h.topics_passed <> (SELECT COUNT(DISTINCT a.topic_id) FROM attempts a JOIN quiz_results q ON q.attempt_id = a.id
                                                 WHERE a.user_id = h.user_id AND q.passed = 1 AND a.submitted_at <= h.created_at)");
$grants = $db->query("SHOW GRANTS FOR 'hunter_fixture'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN);
$ledgerGrant = (bool)array_filter($grants, static fn($g) => str_contains($g, '`coin_ledger`') && str_contains($g, 'INSERT'));
check('I1 wallets reconcile; rank = latest history; each promotion count matches passes at that time; ledger grant restored',
    $wallet === [] && $rankMatch === [] && $promoCount === [] && $ledgerGrant, count($ids) . ' accounts');

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
printf("\n%d checks, %d passed, %d failed. Fixture accounts created in this run: %d (m3f.%s.*@example.invalid, %s only)\n",
    count($results), count($results) - $failed, $failed, count($ids), $RUN, FIXTURE_DB);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
