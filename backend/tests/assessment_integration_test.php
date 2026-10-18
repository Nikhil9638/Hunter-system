<?php
/**
 * Milestone 2 integration tests: onboarding, placement assessment, dashboard, topics.
 * Live HTTP through Apache (API runs as hunter_app) + database checks.
 *
 *   C:\xampp\php\php.exe backend\tests\assessment_integration_test.php
 *
 * A database ADMIN connection (root; password from HUNTER_TEST_DB_ADMIN_PASSWORD) is
 * used only to READ the snapshot answer key (the test plays a student who knows the
 * answers), to HOLD a row lock that fixes the arrival order of concurrent requests,
 * and to inspect results. It never writes history. Test accounts:
 * m2.<run>.*@example.invalid (append-only history remains, by design).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;
use Hunter\Tests\Client;

use function Hunter\Tests\allKeys;
use function Hunter\Tests\orderedConcurrent;

Console::requireCli();
$API = 'http://localhost/hunter-system/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m2_' . $RUN;
@mkdir($TMP);
$config = Config::load();
$API=\Hunter\Tests\seedFixtureApi($config,$API);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'placement test password';

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = $ok;
    printf("%-5s %-80s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
}
function code(array $r): string
{
    return $r['status'] . ' ' . ($r['json']['error']['code'] ?? (isset($r['json']['data']) ? 'data' : substr($r['raw'], 0, 80)));
}
function q(PDO $db, string $sql, array $p = []): ?array
{
    $st = $db->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
function qa(PDO $db, string $sql, array $p = []): array
{
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
function newStudent(string $tag): Client
{
    global $API, $TMP, $RUN, $PW;
    $c = new Client($API, $TMP, $tag);
    $r = $c->req('POST', '/auth/register', ['email' => "m2.$RUN.$tag@example.invalid", 'display_name' => "M2 $tag", 'password' => $PW]);
    if ($r['status'] !== 201) {
        throw new RuntimeException("register $tag failed: " . code($r));
    }
    return $c;
}
function userId(Client $c): int
{
    return (int)$c->req('GET', '/auth/me')['json']['data']['user']['id'];
}
/** Answer key from the snapshot: position => [correct choice id, a wrong choice id]. */
function answerKey(PDO $db, int $attemptId): array
{
    $key = [];
    foreach (qa($db, 'SELECT aq.position, c.id, c.is_correct FROM attempt_questions aq JOIN attempt_question_choices c ON c.attempt_question_id = aq.id
                       WHERE aq.attempt_id = ? ORDER BY aq.position, c.label', [$attemptId]) as $r) {
        $slot = (int)$r['is_correct'] === 1 ? 'right' : 'wrong';
        $key[(int)$r['position']][$slot] ??= (int)$r['id'];
    }
    return $key;
}
/** Answer every position; positions in $correct get the right choice. */
function answerAll(Client $c, PDO $db, int $attemptId, array $correct): void
{
    foreach (answerKey($db, $attemptId) as $pos => $k) {
        $r = $c->req('PUT', "/assessment/$attemptId/answers/$pos", ['choice_id' => in_array($pos, $correct, true) ? $k['right'] : $k['wrong']]);
        if ($r['status'] !== 200) {
            throw new RuntimeException("answer $pos failed: " . code($r));
        }
    }
}
/** Hold the student's profile row lock (step 1 of the API's lock order) from the admin connection. */
function holdProfileLock(PDO $db, int $userId): callable
{
    $db->beginTransaction();
    q($db, 'SELECT user_id FROM student_profiles WHERE user_id = ? FOR UPDATE', [$userId]);
    return static function () use ($db): void {
        $db->commit();
    };
}
$FORBIDDEN_KEYS = ['is_correct', 'correct', 'explanation', 'source_choice_id', 'question_id'];

echo "Hunter System milestone 2 integration tests  run=$RUN\n\n";

// ---------------------------------------------------------------------------
// E: experience level
// ---------------------------------------------------------------------------
$s = newStudent('main');
$uid = userId($s);
$r = $s->req('GET', '/experience-levels');
check('E1 GET /experience-levels: the four approved options in order',
    array_column($r['json']['data'] ?? [], 'code') === ['complete_beginner', 'basic', 'intermediate', 'master'], code($r));
$d = $s->req('GET', '/me/dashboard')['json']['data'] ?? [];
check('E2 new student dashboard: not_started, no experience, no ranks, cannot start assessment, 0 coins',
    ($d['onboarding_state'] ?? '') === 'not_started' && $d['experience_level'] === null && $d['placement_rank'] === null
    && $d['hunter_rank'] === null && $d['assessment'] === ['state' => 'not_started', 'attempt_id' => null, 'can_start' => false] && $d['coins'] === 0);
$r0 = $s->req('POST', '/assessment');
check('E3 starting the assessment before choosing experience -> 409 choose_experience_first', $r0['status'] === 409 && $r0['json']['error']['code'] === 'choose_experience_first', code($r0));
$bad = [];
foreach ([[['experience_level_id' => 99], 'invalid'], [['experience_level_id' => '4'], 'invalid'], [['experience_level_id' => 0], 'invalid'], [(object)[], 'required'],
          [['experience_level_id' => 4, 'rank' => 'S'], 'unknown_field']] as [$body, $expect]) {
    $x = $s->req('PUT', '/me/experience', $body);
    $field = array_key_first($x['json']['error']['details']['fields'] ?? []) ;
    if ($x['status'] !== 400 || ($x['json']['error']['details']['fields'][$field] ?? '') !== $expect) {
        $bad[] = json_encode($body) . '=' . code($x);
    }
}
check('E4 invalid experience inputs -> 400 with field codes (5 cases, incl. smuggled "rank")', $bad === [], implode('; ', $bad));
$arrayBody=$s->req('PUT','/me/experience',[]);
check('E4b JSON [] is not an object and is rejected as invalid_json',$arrayBody['status']===400&&$arrayBody['json']['error']['code']==='invalid_json');
$x1 = $s->req('PUT', '/me/experience', ['experience_level_id' => 4], [], false);
$anon = new Client($API, $TMP, 'anon');
$x2 = $anon->req('PUT', '/me/experience', ['experience_level_id' => 4]);
check('E5 experience without CSRF token -> 403; anonymous -> 401', $x1['status'] === 403 && $x2['status'] === 401, code($x1) . ' | ' . code($x2));
$r = $s->req('PUT', '/me/experience', ['experience_level_id' => 4]);
$again = $s->req('PUT', '/me/experience', ['experience_level_id' => 1]);
$row = q($db, 'SELECT p.onboarding_state, e.code, p.placement_rank_id, p.current_rank_id FROM student_profiles p JOIN experience_levels e ON e.id = p.experience_level_id WHERE p.user_id = ?', [$uid]);
check('E6 choose "master" -> 200 and persisted; changing it later -> 409; no rank granted by it (C-05)',
    $r['status'] === 200 && $again['status'] === 409 && $again['json']['error']['code'] === 'experience_already_chosen'
    && $row['code'] === 'master' && $row['onboarding_state'] === 'experience_chosen' && $row['placement_rank_id'] === null && $row['current_rank_id'] === null,
    code($r) . ' | again ' . code($again));

// ---------------------------------------------------------------------------
// A: start, resume, snapshots, no premature answers
// ---------------------------------------------------------------------------
$r = $s->req('POST', '/assessment');
$att = $r['json']['data'] ?? [];
$aid = (int)($att['attempt_id'] ?? 0);
$groups = array_count_values(array_column($att['questions'] ?? [], 'skill_group'));
ksort($groups);
check('A1 start -> 201, 10 questions, positions 1..10, groups G1-G6 = 2/2/2/2/1/1',
    $r['status'] === 201 && count($att['questions']) === 10 && array_column($att['questions'], 'position') === range(1, 10)
    && $groups === ['G1' => 2, 'G2' => 2, 'G3' => 2, 'G4' => 2, 'G5' => 1, 'G6' => 1] && $att['resumed'] === false,
    code($r) . ' ' . json_encode($groups));
$snap = q($db, "SELECT COUNT(*) n, COUNT(DISTINCT aq.question_id) d,
                       SUM(q.for_assessment = 1 AND q.status = 'active'
                           AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id) >= 2
                           AND (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = q.id AND c.is_correct = 1) = 1) AS valid,
                       SUM((SELECT COUNT(*) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id)
                         = (SELECT COUNT(*) FROM question_choices c WHERE c.question_id = aq.question_id)) AS full_choices
                  FROM attempt_questions aq JOIN questions q ON q.id = aq.question_id WHERE aq.attempt_id = ?", [$aid]);
check('A2 snapshot: 10 distinct, valid, active assessment questions with all choices copied',
    (int)$snap['n'] === 10 && (int)$snap['d'] === 10 && (int)$snap['valid'] === 10 && (int)$snap['full_choices'] === 10, json_encode($snap));
$leak = array_values(array_intersect(allKeys($r['json']), $FORBIDDEN_KEYS));
check('A3 start response has no correct flags, explanations or source ids', $leak === [], $leak === [] ? 'clean' : implode(',', $leak));

$key = answerKey($db, $aid);
$a1 = $s->req('PUT', "/assessment/$aid/answers/1", ['choice_id' => $key[1]['wrong']]);
$a1b = $s->req('PUT', "/assessment/$aid/answers/1", ['choice_id' => $key[1]['right']]);
$a2 = $s->req('PUT', "/assessment/$aid/answers/2", ['choice_id' => $key[2]['right']]);
$res = $s->req('POST', '/assessment');
$sel = array_column($res['json']['data']['questions'] ?? [], 'selected_choice_id', 'position');
check('A4 save then change an answer -> 200; resume returns the same attempt with the saved answers',
    $a1['status'] === 200 && $a1b['status'] === 200 && $a2['status'] === 200 && $res['status'] === 200
    && ($res['json']['data']['attempt_id'] ?? 0) === $aid && $res['json']['data']['resumed'] === true
    && ($sel[1] ?? null) === $key[1]['right'] && ($sel[2] ?? null) === $key[2]['right'] && $sel[3] === null && $res['json']['data']['answered_count'] === 2,
    code($res) . ' answered=' . ($res['json']['data']['answered_count'] ?? '?'));
$leak = array_values(array_intersect(array_merge(allKeys($res['json']), allKeys($a1['json'])), $FORBIDDEN_KEYS));
check('A5 resume and answer responses reveal no correct flags or explanations', $leak === [], $leak === [] ? 'clean' : implode(',', $leak));

$other = newStudent('other');
$other->req('PUT', '/me/experience', ['experience_level_id' => 1]);
$oatt = $other->req('POST', '/assessment')['json']['data'];
$oaid = (int)$oatt['attempt_id'];
$okey = answerKey($db, $oaid);
$inv = [
    'choice of another position' => $s->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => $key[4]['right']]),
    "choice of another student's attempt" => $s->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => $okey[3]['right']]),
    'nonexistent choice' => $s->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => 999999999]),
];
$bad = array_keys(array_filter($inv, static fn($x) => $x['status'] !== 400 || $x['json']['error']['code'] !== 'invalid_choice'));
$strType = $s->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => (string)$key[3]['right']]);
$p11 = $s->req('PUT', "/assessment/$aid/answers/11", ['choice_id' => $key[3]['right']]);
$p0 = $s->req('PUT', "/assessment/$aid/answers/0", ['choice_id' => $key[3]['right']]);
$stillNull = q($db, 'SELECT selected_choice_id FROM attempt_questions WHERE attempt_id = ? AND position = 3', [$aid])['selected_choice_id'];
check('A6 invalid choices -> 400 invalid_choice (other position, other attempt, nonexistent); bad type 400; position 0/11 -> 404',
    $bad === [] && $strType['status'] === 400 && $p11['status'] === 404 && $p0['status'] === 404 && $stillNull === null,
    $bad === [] ? 'ok' : implode(',', $bad));
$own1 = $other->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => $key[3]['right']]);
$own2 = $other->req('POST', "/assessment/$aid/submit");
$own3 = $s->req('PUT', "/assessment/$oaid/answers/1", ['choice_id' => $okey[1]['right']]);
$csrf = $s->req('PUT', "/assessment/$aid/answers/3", ['choice_id' => $key[3]['right']], [], false);
$csrf2 = $s->req('POST', "/assessment/$aid/submit", null, [], false);
check("A7 another student's attempt -> 404 (answer, submit, both directions); no CSRF -> 403",
    $own1['status'] === 404 && $own2['status'] === 404 && $own3['status'] === 404 && $csrf['status'] === 403 && $csrf2['status'] === 403,
    code($own1) . ' | ' . code($own2) . ' | ' . code($own3) . ' | ' . code($csrf) . ' | ' . code($csrf2));
$res0 = $s->req('GET', '/assessment/result');
$dash = $s->req('GET', '/me/dashboard')['json']['data'];
check('A8 result before submission -> 404; dashboard shows assessment in_progress',
    $res0['status'] === 404 && $dash['assessment']['state'] === 'in_progress' && $dash['assessment']['attempt_id'] === $aid, code($res0));

// answer 9 of 10 -> incomplete
foreach ($key as $pos => $k) {
    if ($pos <= 9) {
        $s->req('PUT', "/assessment/$aid/answers/$pos", ['choice_id' => $pos <= 4 ? $k['right'] : $k['wrong']]);
    }
}
$inc = $s->req('POST', "/assessment/$aid/submit");
check('A9 submit with 9 of 10 answered -> 409 attempt_incomplete, unanswered_positions [10]',
    $inc['status'] === 409 && $inc['json']['error']['code'] === 'attempt_incomplete' && $inc['json']['error']['details']['unanswered_positions'] === [10], code($inc));

// ---------------------------------------------------------------------------
// R: answer save vs final submit, both lock orders (lock held -> fixed arrival order)
// ---------------------------------------------------------------------------
$s->req('PUT', "/assessment/$aid/answers/10", ['choice_id' => $key[10]['right']]);             // now 4 + q10 correct = 5? (q1-4 right, 5-9 wrong, 10 right)
$release = holdProfileLock($db, $uid);
[$ans, $sub] = orderedConcurrent([
    [$s, $s->asyncHandle('PUT', "/assessment/$aid/answers/1", ['choice_id' => $key[1]['wrong']])],      // arrives first: q1 -> wrong
    [$s, $s->asyncHandle('POST', "/assessment/$aid/submit")],
], $release);
$firstSubmit = $sub;
$sel1 = (int)q($db, 'SELECT selected_choice_id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$aid])['selected_choice_id'];
check('R1 answer-save first, submit second (blocked together): submit grades the changed answer',
    $ans['status'] === 200 && $sub['status'] === 200 && $sub['json']['data']['correct_count'] === 4 && $sel1 === $key[1]['wrong'],
    'answer ' . code($ans) . ' | submit ' . code($sub) . ' correct=' . ($sub['json']['data']['correct_count'] ?? '?') . ' (q1 changed to wrong)');
$late = $s->req('PUT', "/assessment/$aid/answers/2", ['choice_id' => $key[2]['wrong']]);
$sel2 = (int)q($db, 'SELECT selected_choice_id FROM attempt_questions WHERE attempt_id = ? AND position = 2', [$aid])['selected_choice_id'];
check('R2 answer after finalization -> 409 attempt_finalized; stored answer unchanged',
    $late['status'] === 409 && $late['json']['error']['code'] === 'attempt_finalized' && $sel2 === $key[2]['right'], code($late));

// submit first, answer second
$t = newStudent('race2');
$tuid = userId($t);
$t->req('PUT', '/me/experience', ['experience_level_id' => 2]);
$taid = (int)$t->req('POST', '/assessment')['json']['data']['attempt_id'];
$tkey = answerKey($db, $taid);
answerAll($t, $db, $taid, range(1, 10));
$release = holdProfileLock($db, $tuid);
[$sub, $ans] = orderedConcurrent([
    [$t, $t->asyncHandle('POST', "/assessment/$taid/submit")],
    [$t, $t->asyncHandle('PUT', "/assessment/$taid/answers/5", ['choice_id' => $tkey[5]['wrong']])],
], $release);
$sel5 = (int)q($db, 'SELECT selected_choice_id FROM attempt_questions WHERE attempt_id = ? AND position = 5', [$taid])['selected_choice_id'];
$late = q($db, 'SELECT COUNT(*) n FROM attempt_questions aq JOIN attempts a ON a.id = aq.attempt_id WHERE a.id = ? AND aq.answered_at > a.submitted_at', [$taid]);
check('R3 submit first, answer-save second: answer refused 409, 10/10 kept, no answer after submitted_at',
    $sub['status'] === 200 && $sub['json']['data']['correct_count'] === 10 && $ans['status'] === 409 && $ans['json']['error']['code'] === 'attempt_finalized'
    && $sel5 === $tkey[5]['right'] && (int)$late['n'] === 0, 'submit ' . code($sub) . ' | answer ' . code($ans));

// ---------------------------------------------------------------------------
// D: duplicate and simultaneous submissions; one placement only
// ---------------------------------------------------------------------------
$dup = $s->req('POST', "/assessment/$aid/submit");
$counts = q($db, "SELECT (SELECT COUNT(*) FROM assessment_results WHERE attempt_id = ?) r,
                         (SELECT COUNT(*) FROM rank_history WHERE user_id = ? AND reason = 'placement') h,
                         (SELECT COUNT(*) FROM student_topic_progress WHERE user_id = ?) p", [$aid, $uid, $uid]);
check('D1 repeated submit -> 200 already_submitted:true, identical result, no repeated side effects',
    $dup['status'] === 200 && $dup['json']['data']['already_submitted'] === true && $dup['json']['data']['correct_count'] === 4
    && $dup['json']['data']['review'] === $firstSubmit['json']['data']['review']
    && $dup['json']['data']['placement_rank'] === $firstSubmit['json']['data']['placement_rank']
    && (int)$counts['r'] === 1 && (int)$counts['h'] === 1 && (int)$counts['p'] === 18,
    code($dup) . ' results=' . $counts['r'] . ' placement rows=' . $counts['h'] . ' progress rows=' . $counts['p']);
$u = newStudent('dupsim');
$uuid = userId($u);
$u->req('PUT', '/me/experience', ['experience_level_id' => 3]);
$uaid = (int)$u->req('POST', '/assessment')['json']['data']['attempt_id'];
answerAll($u, $db, $uaid, [1, 2, 3, 4, 5, 6, 7]);
$release = holdProfileLock($db, $uuid);
[$x, $y] = orderedConcurrent([[$u, $u->asyncHandle('POST', "/assessment/$uaid/submit")], [$u, $u->asyncHandle('POST', "/assessment/$uaid/submit")]], $release);
$c2 = q($db, "SELECT (SELECT COUNT(*) FROM assessment_results WHERE attempt_id = ?) r, (SELECT COUNT(*) FROM rank_history WHERE user_id = ?) h,
                     (SELECT COUNT(*) FROM student_topic_progress WHERE user_id = ?) p", [$uaid, $uuid, $uuid]);
check('D2 two simultaneous submits -> one finalizes, the other gets the stored result; single side effects',
    $x['status'] === 200 && $y['status'] === 200 && [$x['json']['data']['already_submitted'], $y['json']['data']['already_submitted']] === [false, true]
    && $x['json']['data']['correct_count'] === 7 && $y['json']['data']['correct_count'] === 7 && (int)$c2['r'] === 1 && (int)$c2['h'] === 1 && (int)$c2['p'] === 18,
    "already_submitted: " . json_encode([$x['json']['data']['already_submitted'] ?? null, $y['json']['data']['already_submitted'] ?? null]) . " rows r/h/p=$c2[r]/$c2[h]/$c2[p]");
$again = $s->req('POST', '/assessment');
check('D3 one official placement: starting again after submission -> 409 placement_already_recorded',
    $again['status'] === 409 && $again['json']['error']['code'] === 'placement_already_recorded', code($again));

// ---------------------------------------------------------------------------
// S: simultaneous starts
// ---------------------------------------------------------------------------
$v = newStudent('simstart');
$vuid = userId($v);
$v->req('PUT', '/me/experience', ['experience_level_id' => 1]);
$release = holdProfileLock($db, $vuid);
[$x, $y] = orderedConcurrent([[$v, $v->asyncHandle('POST', '/assessment')], [$v, $v->asyncHandle('POST', '/assessment')]], $release);
$n = q($db, "SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND kind = 'assessment'", [$vuid]);
check('S1 two simultaneous starts -> 201 + 200, the same attempt, exactly one attempt row',
    [$x['status'], $y['status']] === [201, 200] && $x['json']['data']['attempt_id'] === $y['json']['data']['attempt_id'] && (int)$n['n'] === 1,
    "statuses $x[status]/$y[status] attempts=" . $n['n']);

// ---------------------------------------------------------------------------
// B: grading at every placement boundary, recommendation, fallback, review, unlocks
// ---------------------------------------------------------------------------
$ready = array_map('intval', array_column(qa($db, 'SELECT topic_id FROM v_topic_readiness WHERE is_ready = 1 ORDER BY position'), 'topic_id'));
$posOf = array_column(qa($db, 'SELECT id, position FROM topics'), 'position', 'id');
$groupFirstTopic = [1 => 1, 2 => 1, 3 => 4, 4 => 4, 5 => 7, 6 => 7, 7 => 10, 8 => 10, 9 => 14, 10 => 17];   // position -> first topic of its group
$expectRank = [0 => 'E', 2 => 'E', 3 => 'D', 4 => 'D', 5 => 'C', 6 => 'C', 7 => 'B', 8 => 'B', 9 => 'A', 10 => 'S'];
$fails = [];
$sample = null;
foreach ($expectRank as $k => $rank) {
    $c = newStudent("b$k");
    $cuid = userId($c);
    $c->req('PUT', '/me/experience', ['experience_level_id' => 4]);                       // "Master" never changes the outcome
    $caid = (int)$c->req('POST', '/assessment')['json']['data']['attempt_id'];
    answerAll($c, $db, $caid, $k === 0 ? [] : range(1, $k));                             // first k positions right
    $res = $c->req('POST', "/assessment/$caid/submit")['json']['data'] ?? [];
    $recommended = $k === 10 ? 18 : $groupFirstTopic[$k + 1];
    $earlier = array_values(array_filter($ready, static fn(int $t) => $posOf[$t] < $posOf[$recommended]));
    $offered = in_array($recommended, $ready, true) ? null : ($earlier === [] ? null : end($earlier));
    $prof = q($db, 'SELECT pr.code p, cr.code c, p.coin_balance FROM student_profiles p JOIN ranks pr ON pr.id = p.placement_rank_id JOIN ranks cr ON cr.id = p.current_rank_id WHERE p.user_id = ?', [$cuid]);
    $prog = q($db, "SELECT SUM(unlocked_at IS NOT NULL) unl, MAX(IF(unlocked_at IS NOT NULL, t.position, 0)) maxpos, COUNT(*) n
                      FROM student_topic_progress sp JOIN topics t ON t.id = sp.topic_id WHERE sp.user_id = ?", [$cuid]);
    $coins = q($db, 'SELECT (SELECT COUNT(*) FROM coin_ledger WHERE user_id = ?) l, (SELECT COUNT(*) FROM reward_claims WHERE user_id = ?) rc', [$cuid, $cuid]);
    $grp = array_column($res['groups'] ?? [], 'asked', 'code');
    $rev = $res['review'] ?? [];
    $revOk = count($rev) === 10;
    foreach ($rev as $item) {
        $correctIds = array_column(array_filter($item['choices'], static fn($ch) => $ch['correct']), 'id');
        $revOk = $revOk && count($correctIds) === 1 && is_string($item['explanation']) && $item['explanation'] !== ''
            && $item['is_correct'] === ($item['selected_choice_id'] === $correctIds[0]) && $item['is_correct'] === ($item['position'] <= $k);
    }
    $evidence = $res['topics'] ?? [];
    $evOk = count($evidence) === 18 && array_sum(array_column($evidence, 'asked')) === 10;
    foreach ($evidence as $e) {
        $evOk = $evOk && $e['evidence'] === ($e['asked'] === 0 ? 'not_assessed' : 'sampled') && !array_intersect(array_keys($e), ['percent', 'mastery']);
    }
    $ok = ($res['correct_count'] ?? -1) === $k && ($res['placement_rank']['code'] ?? '') === $rank
        && $prof['p'] === $rank && $prof['c'] === $rank && (int)$prof['coin_balance'] === 0 && (int)$coins['l'] === 0 && (int)$coins['rc'] === 0
        && ($res['recommended_topic']['id'] ?? 0) === $recommended && (($res['offered_topic']['id'] ?? null) === $offered)
        && (int)$prog['n'] === 18 && (int)$prog['maxpos'] === $posOf[$recommended] && (int)$prog['unl'] === $posOf[$recommended]
        && $grp === ['G1' => 2, 'G2' => 2, 'G3' => 2, 'G4' => 2, 'G5' => 1, 'G6' => 1] && $revOk && $evOk && ($res['coins_awarded'] ?? null) === 0
        && ($res['experience_level']['code'] ?? '') === 'master' && ($res['label'] ?? '') !== '';
    if (!$ok) {
        $fails[] = "k=$k got " . ($res['correct_count'] ?? '?') . '/' . ($res['placement_rank']['code'] ?? '?') . ' rec=' . ($res['recommended_topic']['id'] ?? '?')
            . ' off=' . json_encode($res['offered_topic']['id'] ?? null) . " exp off=" . json_encode($offered) . " unl=$prog[unl] rev=" . (int)$revOk . ' ev=' . (int)$evOk;
    }
    if ($k === 4) {
        $sample = [$c, $cuid, $res];
    }
}
check('B1 10 boundaries (0,2,3,4,5,6,7,8,9,10 correct -> E,E,D,D,C,C,B,B,A,S): rank, current rank = placement, no coins',
    $fails === [], $fails === [] ? 'all 10 correct' : implode(' || ', $fails));
check('B2 (same runs) recommendation = first topic of earliest missed group (all correct -> 18); unavailable -> nearest earlier ready offered separately',
    $fails === [], 'ready topics: ' . implode(',', $ready));
check('B3 (same runs) review: 10 items, one correct choice each, explanation, is_correct consistent; evidence: not_assessed/sampled, no percentages',
    $fails === []);

// ---------------------------------------------------------------------------
// T: dashboard and topic list after placement (student b4: 4/10 -> D, recommended 7, offered 2)
// ---------------------------------------------------------------------------
[$c, $cuid, $res] = $sample;
$dash = $c->req('GET', '/me/dashboard')['json']['data'];
check('T1 dashboard: experience, placement and Hunter rank as separate fields; submitted; recommended 7 + offered 2; 0 coins; 0 passed',
    $dash['onboarding_state'] === 'assessment_submitted' && $dash['experience_level']['code'] === 'master'
    && $dash['placement_rank']['code'] === 'D' && $dash['hunter_rank']['code'] === 'D' && $dash['assessment']['state'] === 'submitted'
    && $dash['recommended_topic']['id'] === 7 && $dash['recommended_topic']['ready'] === false && $dash['offered_topic']['id'] === 2
    && $dash['coins'] === 0 && $dash['topics_passed'] === 0 && $dash['topics_total'] === 18
    && $dash['current_topic']['id'] === 1 && $dash['next_rank']['code'] === 'C' && $dash['next_rank']['topics_required'] === 6 && $dash['next_rank']['topics_remaining'] === 6,
    json_encode(['current' => $dash['current_topic'], 'next' => $dash['next_rank']]));
$topics = $c->req('GET', '/topics')['json']['data'];
$byId = array_column($topics, null, 'id');
$okT = count($topics) === 18 && array_column($topics, 'position') === range(1, 18);
foreach ($topics as $tp) {
    $shouldUnlock = $tp['position'] <= 7;
    $okT = $okT && $tp['unlocked'] === $shouldUnlock && $tp['status'] === ($shouldUnlock ? 'available' : 'locked')
        && $tp['unlock_source'] === ($shouldUnlock ? 'placement' : null) && $tp['content_ready'] === in_array($tp['id'], $ready, true)
        && $tp['completed_attempts'] === 0 && $tp['first_passed_at'] === null;
}
check('T2 topics: 18 in order; 1-7 unlocked (placement), 8-18 locked; none passed; content_ready only for ready topics',
    $okT, 'unlocked but not ready: ' . implode(',', array_column(array_filter($topics, static fn($t) => $t['unlocked'] && !$t['content_ready']), 'id')));
check('T3 an unlocked topic without content is shown as unlocked + content_ready=false (separate states)',
    $byId[7]['unlocked'] === true && $byId[7]['content_ready'] === false && $byId[1]['unlocked'] === true && $byId[1]['content_ready'] === true);
$rg = $c->req('GET', '/assessment/result');
check('T4 GET /assessment/result returns the stored result with review (no already_submitted flag)',
    $rg['status'] === 200 && $rg['json']['data']['attempt_id'] === $res['attempt_id'] && count($rg['json']['data']['review']) === 10
    && !array_key_exists('already_submitted', $rg['json']['data']), code($rg));
$passedRows = q($db, "SELECT (SELECT COUNT(*) FROM quiz_results r JOIN attempts a ON a.id = r.attempt_id WHERE a.user_id = ?) qr,
                             (SELECT COUNT(*) FROM rank_history WHERE user_id = ? AND reason = 'promotion') pr", [$cuid, $cuid]);
check('T5 placement passes nothing automatically: no quiz results, no promotions', (int)$passedRows['qr'] === 0 && (int)$passedRows['pr'] === 0);

// ---------------------------------------------------------------------------
// P: role enforcement and restricted database account
// ---------------------------------------------------------------------------
$adminEmail = "m2.$RUN.admin@example.invalid";
$adminPw = 'Admin test password ' . bin2hex(random_bytes(4));
$proc = proc_open([PHP_BINARY, Paths::projectRoot() . '/backend/bin/create-admin.php', "--email=$adminEmail", '--display-name=M2 Admin'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "$adminPw\n$adminPw\n");
fclose($pipes[0]);
stream_get_contents($pipes[1]);
proc_close($proc);
$adm = new Client($API, $TMP, 'admin');
$adm->req('POST', '/auth/login', ['email' => $adminEmail, 'password' => $adminPw]);
$codes = [$adm->req('GET', '/me/dashboard')['status'], $adm->req('GET', '/topics')['status'], $adm->req('POST', '/assessment')['status'],
          $adm->req('PUT', '/me/experience', ['experience_level_id' => 1])['status']];
$anonCodes = [$anon->req('GET', '/me/dashboard')['status'], $anon->req('GET', '/topics')['status'], $anon->req('GET', '/assessment/result')['status']];
check('P1 admin -> 403 on student endpoints; anonymous -> 401', $codes === [403, 403, 403, 403] && $anonCodes === [401, 401, 401],
    'admin ' . implode(',', $codes) . ' anon ' . implode(',', $anonCodes));

$app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'), $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$denied = [];
foreach ([
    'UPDATE student_profiles.coin_balance' => 'UPDATE student_profiles SET coin_balance = 999 WHERE user_id = 0',
    'UPDATE attempt_questions.stem' => "UPDATE attempt_questions SET stem = 'x' WHERE id = 0",
    'UPDATE attempt_question_choices' => 'UPDATE attempt_question_choices SET is_correct = 1 WHERE id = 0',
    'UPDATE assessment_results' => 'UPDATE assessment_results SET correct_count = 10 WHERE attempt_id = 0',
    'UPDATE attempts.user_id' => 'UPDATE attempts SET user_id = 0 WHERE id = 0',
    'DELETE attempts' => 'DELETE FROM attempts WHERE id = 0',
    'DELETE rank_history' => 'DELETE FROM rank_history WHERE id = 0',
    // quiz_results and coin_ledger are INSERT-able since milestone 3; rewriting them never is
    'UPDATE quiz_results' => "UPDATE quiz_results SET passed = 1 WHERE attempt_id = 0",
    'DELETE coin_ledger' => 'DELETE FROM coin_ledger WHERE id = 0',
    'DELETE questions' => 'DELETE FROM questions WHERE id = 0',                  // content is editable since M5, never deletable
    'UPDATE placement_rank_bands' => 'UPDATE placement_rank_bands SET rank_id = 6 WHERE policy_id = 0',
    'UPDATE badges.slug' => "UPDATE badges SET slug = 'x' WHERE id = 0",         // slug immutable (catalogue editable since M5)
] as $label => $sql) {
    try {
        $app->exec($sql);
        $denied[] = "$label ALLOWED";
    } catch (PDOException $e) {
        if (!in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143], true)) {
            $denied[] = "$label errno " . ($e->errorInfo[1] ?? '?');
        }
    }
}
check('P2 hunter_app refused 12 operations outside its grants (coin_balance, snapshot text, results, deletes, quiz/ledger rewrites, content)',
    $denied === [], $denied === [] ? 'all refused (1142/1143)' : implode('; ', $denied));
$fileGrants = preg_match_all('/^GRANT /m', (string)file_get_contents(Paths::projectRoot() . '/database/accounts/hunter_app_grants.sql'));
$global = array_filter($db->query("SHOW GRANTS FOR '".$config->get('DB_USER')."'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN),
    static fn(string $g) => str_contains($g, ' ON *.* ') && !str_starts_with($g, 'GRANT USAGE'));
check('P3 grants file has ' . $fileGrants . ' GRANT statements; account has no global privileges; API configured as hunter_app',
    $global === [] && \Hunter\Tests\expectedIntegrationAccount($config));

// ---------------------------------------------------------------------------
$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
$users = q($db, 'SELECT COUNT(*) n FROM users WHERE email LIKE ?', ["m2.$RUN.%"])['n'];
printf("\n%d checks, %d passed, %d failed. Test accounts created in this run: %d (m2.%s.*@example.invalid)\n",
    count($results), count($results) - $failed, $failed, $users, $RUN);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
