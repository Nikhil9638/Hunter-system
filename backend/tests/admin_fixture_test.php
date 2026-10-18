<?php
/**
 * Milestone 5 fixture tests: successful administrator content changes, readiness
 * thresholds, snapshots vs bank edits, attempt creation during an edit, stale edits,
 * badge approval/sale racing purchases, audit accuracy and rollback - against the
 * ISOLATED fixture database (hunter_system_m3_fixture, account hunter_fixture).
 *
 *   set HUNTER_PRIVATE_DIR=C:\xampp\hunter-system-fixture-private
 *   php -S 127.0.0.1:8098 -t backend\tests\fixtures backend\tests\fixtures\router.php   (and a 2nd on 8099)
 *   C:\xampp\php\php.exe backend\tests\admin_fixture_test.php
 *
 * The database admin connection only: reads answer keys and rows, holds locks to fix
 * arrival order, simulates an in-progress question edit (uncommitted UPDATE of a
 * fixture topic's questions), deletes ONE "[FIXTURE]" lesson so lesson creation can be
 * exercised, and temporarily revokes INSERT on audit_log (restored in finally).
 * Topics touched are restored to "available" at the end. Accounts: m5f.<run>.*.
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
$config = Config::load();
if ($config->get('DB_NAME') !== FIXTURE_DB || !in_array($config->get('DB_USER'), ['hunter_fixture','hunter_m7_fixture'], true)) {
    fwrite(STDERR, "Refusing to run: HUNTER_PRIVATE_DIR must point at the fixture configuration (" . FIXTURE_DB . ").\n");
    exit(2);
}
define('FIXTURE_ACCOUNT', "'" . $config->get('DB_USER') . "'@'127.0.0.1'");
$API = 'http://127.0.0.1:8098/hunter-system/api/v1';
$API2 = 'http://127.0.0.1:8099/hunter-system/api/v1';
\Hunter\Tests\requireFixtureBackend($API, $config->get('DB_NAME'), $config->get('DB_USER'));
\Hunter\Tests\requireFixtureBackend($API2, $config->get('DB_NAME'), $config->get('DB_USER'));
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m5f_' . $RUN;
@mkdir($TMP);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . FIXTURE_DB . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'admin fixture password';
$PREFIX = 'm5f';
$FORBIDDEN_KEYS = ['is_correct', 'correct', 'explanation', 'source_choice_id', 'question_id'];

require __DIR__ . '/QuizSupport.php';

function makeAdmin(string $tag): Client
{
    global $API, $TMP, $RUN, $PREFIX;
    $email = "$PREFIX.$RUN.$tag@example.invalid";
    $pw = 'Admin test password ' . bin2hex(random_bytes(4));
    $proc = proc_open([PHP_BINARY, Paths::projectRoot() . '/backend/bin/create-admin.php', "--email=$email", '--display-name=M5F ' . $tag],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], "$pw\n$pw\n");
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    proc_close($proc);
    $c = new Client($API, $TMP, $tag);
    if ($c->req('POST', '/auth/login', ['email' => $email, 'password' => $pw])['status'] !== 200) {
        throw new RuntimeException("admin $tag login failed");
    }
    return $c;
}
function second(Client $c): Client
{
    global $API2;
    $c2 = clone $c;
    $c2->api = $API2;
    return $c2;
}
function err(array $r): string
{
    return $r['json']['error']['code'] ?? '';
}
function d(array $r): array
{
    return $r['json']['data'] ?? [];
}
function topicOf(Client $adm, int $id): array
{
    foreach (d($adm->req('GET', '/admin/topics')) as $t) {
        if ($t['id'] === $id) {
            return $t;
        }
    }
    return [];
}
function auditCount(PDO $db): int
{
    return (int)q($db, 'SELECT COUNT(*) n FROM audit_log')['n'];
}
function richStudent(PDO $db, string $tag, int $coins): array
{
    $c = newStudent($tag);
    place($c, $db, [1, 2, 3, 4, 5, 6]);
    for ($t = 1; $t <= intdiv($coins, 10); $t++) {
        takeQuiz($c, $db, $t, 0);
    }
    return [$c, userId($c)];
}
function qBody(int $topic, string $stem, array $choices, ?string $explanation = 'Because.', bool $quiz = true, bool $assessment = false): array
{
    $out = [];
    foreach (array_values($choices) as $i => [$text, $ok]) {
        $out[] = ['label' => 'ABCDEFG'[$i], 'text' => $text, 'is_correct' => $ok];
    }
    return ['topic_id' => $topic, 'for_quiz' => $quiz, 'for_assessment' => $assessment, 'difficulty' => 'easy', 'stem' => $stem,
            'code_snippet' => null, 'explanation' => $explanation, 'choices' => $out];
}
/** Expected audit trail, recorded as successful mutations happen: [entity, id, action, operation|null]. */
$EXPECT = [];
function expectAudit(string $entity, int $id, string $action, ?string $op = null): void
{
    global $EXPECT;
    $EXPECT[] = [$entity, $id, $action, $op];
}

function firstDiff(array $a, array $b): string
{
    for ($i = 0; $i < max(count($a), count($b)); $i++) {
        if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
            return " first difference at #$i: got " . json_encode($a[$i] ?? null) . ' expected ' . json_encode($b[$i] ?? null);
        }
    }
    return '';
}

echo "Hunter System milestone 5 FIXTURE tests (API $API + $API2, DB " . FIXTURE_DB . ")  run=$RUN\n\n";
$touched = [15, 16, 17, 18];
$devAudit = (int)q($db, 'SELECT COUNT(*) n FROM hunter_system_dev.audit_log')['n'];
$auditStart = auditCount($db);
$a1 = makeAdmin('a1');
$a2 = makeAdmin('a2');
$a1b = second($a1);
$a1id = (int)q($db, 'SELECT id FROM users WHERE email = ?', ["m5f.$RUN.a1@example.invalid"])['id'];
$a2id = (int)q($db, 'SELECT id FROM users WHERE email = ?', ["m5f.$RUN.a2@example.invalid"])['id'];
[$s1, $u1] = richStudent($db, 's1', 50);                                   // coins 50, every topic unlocked (placed S)

try {
    // =====================================================================
    // H: historical attempts vs bank edits (topic 16)
    // =====================================================================
    [$hA, $hSub] = takeQuiz($s1, $db, 16, 3);
    $resBefore = d($s1->req('GET', "/quiz-attempts/$hA"));
    $snapBefore = md5(json_encode(qa($db, 'SELECT aq.id, aq.question_revision, aq.stem, aq.explanation, aq.is_correct, aq.selected_choice_id, c.label, c.choice_text, c.is_correct ci
        FROM attempt_questions aq JOIN attempt_question_choices c ON c.attempt_question_id = aq.id WHERE aq.attempt_id = ? ORDER BY aq.id, c.label', [$hA])));
    $open = (int)d(startQuiz($s1, 16))['attempt_id'];
    $openKey = answerKey($db, $open);
    foreach ([1, 2] as $p) {
        $s1->req('PUT', "/quiz-attempts/$open/answers/$p", ['choice_id' => $openKey[$p]['right']]);
    }
    $openViewBefore = d($s1->req('GET', "/quiz-attempts/$open"));
    $usedQ = (int)q($db, 'SELECT question_id FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$hA])['question_id'];
    $Q = d($a1->req('GET', "/admin/questions/$usedQ"));
    $newCorrect = $Q['choices'][0]['is_correct'] ? 1 : 0;                   // move the correct answer to another label
    $edit = $Q['choices'];
    $body = ['expected_revision' => $Q['revision'], 'topic_id' => 16, 'for_quiz' => true, 'for_assessment' => false, 'difficulty' => 'medium',
             'stem' => "[FIXTURE] edited by run $RUN", 'code_snippet' => null, 'explanation' => '[FIXTURE] new explanation',
             'choices' => array_map(static fn($c, $i) => ['label' => $c['label'], 'text' => '[FIXTURE] new ' . $c['label'], 'is_correct' => $i === $newCorrect],
                 $edit, array_keys($edit))];
    $ed = $a1->req('PUT', "/admin/questions/$usedQ", $body);
    if ($ed['status'] === 200) {
        expectAudit('question', $usedQ, 'update');
    }
    $snapAfter = md5(json_encode(qa($db, 'SELECT aq.id, aq.question_revision, aq.stem, aq.explanation, aq.is_correct, aq.selected_choice_id, c.label, c.choice_text, c.is_correct ci
        FROM attempt_questions aq JOIN attempt_question_choices c ON c.attempt_question_id = aq.id WHERE aq.attempt_id = ? ORDER BY aq.id, c.label', [$hA])));
    $resAfter = d($s1->req('GET', "/quiz-attempts/$hA"));
    $openViewAfterEdit = d($s1->req('GET', "/quiz-attempts/$open"));
    check('H1 editing a used question (stem, choices, correct label, explanation) -> 200 rev+1; the submitted attempt\'s snapshot, review and grade are unchanged',
        $ed['status'] === 200 && d($ed)['revision'] === $Q['revision'] + 1 && $snapBefore === $snapAfter && $resBefore === $resAfter
        && $resAfter['correct_count'] === 3, code($ed));
    $rmBody = ['expected_revision' => $Q['revision'] + 1] + $body;
    $rmBody['choices'] = array_slice($body['choices'], 0, 2);
    if (!$body['choices'][0]['is_correct'] && !$body['choices'][1]['is_correct']) {
        $rmBody['choices'][1]['is_correct'] = true;
    }
    $rm = $a1->req('PUT', "/admin/questions/$usedQ", $rmBody);
    check('H2 removing choices that were shown in an attempt -> 409 choice_in_use; question unchanged', err($rm) === 'choice_in_use'
        && d($a1->req('GET', "/admin/questions/$usedQ"))['revision'] === $Q['revision'] + 1, code($rm));

    // =====================================================================
    // L: lessons, topic status, stale edits, creation (topics 16 and 18)
    // =====================================================================
    $visits0 = (int)q($db, 'SELECT COALESCE(SUM(lesson_visit_count), 0) n FROM student_topic_progress')['n'];
    $L = d($a1->req('GET', '/admin/topics/16/lesson'));
    $r0 = $L['revision'];
    $e1 = $a1->req('PUT', '/admin/topics/16/lesson', ['expected_revision' => $r0, 'title' => "[FIXTURE] Exceptions ($RUN, a1)", 'body_markdown' => "[FIXTURE] body by a1 $RUN"]);
    $e2 = $a2->req('PUT', '/admin/topics/16/lesson', ['expected_revision' => $r0, 'title' => '[FIXTURE] overwrite by a2', 'body_markdown' => 'stale']);
    $now = d($a1->req('GET', '/admin/topics/16/lesson'));
    if ($e1['status'] === 200) {
        expectAudit('lesson', $L['lesson_id'], 'update');
    }
    check('L1 two admins edit the same lesson revision: first 200 (rev+1), second 409 edit_conflict {current_revision}; first edit kept',
        $e1['status'] === 200 && d($e1)['revision'] === $r0 + 1 && err($e2) === 'edit_conflict' && $e2['json']['error']['details'] === ['current_revision' => $r0 + 1]
        && $now['title'] === "[FIXTURE] Exceptions ($RUN, a1)" && $now['status'] === 'published', code($e1) . ' | ' . code($e2));
    $badL = [];
    foreach ([[['expected_revision' => $r0 + 1, 'title' => '', 'body_markdown' => 'x'], 'title'], [['expected_revision' => $r0 + 1, 'title' => str_repeat('x', 121), 'body_markdown' => 'x'], 'title'],
              [['title' => 'x', 'body_markdown' => 'x'], 'expected_revision'], [['expected_revision' => $r0 + 1, 'title' => 'x', 'body_markdown' => 'x', 'status' => 'published'], 'status']] as [$b, $field]) {
        $x = $a1->req('PUT', '/admin/topics/16/lesson', $b);
        if ($x['status'] !== 400 || !isset($x['json']['error']['details']['fields'][$field])) $badL[] = "$field=" . code($x);
    }
    check('L2 lesson validation: empty / too-long title, missing expected_revision, smuggled status -> 400', $badL === [], implode('; ', $badL));
    $un = $a1->req('POST', '/admin/topics/16/lesson/unpublish', ['expected_revision' => $r0 + 1]);
    $t16 = topicOf($a1, 16);
    $stale = $a2->req('PATCH', '/admin/topics/16', ['expected_version' => '2000-01-01T00:00:00.000Z', 'status' => 'draft']);
    $dr = $a1->req('PATCH', '/admin/topics/16', ['expected_version' => $t16['version'], 'status' => 'draft']);
    if ($dr['status'] === 200) {
        expectAudit('topic', 16, 'unpublish');
    }
    $stuL = $s1->req('GET', '/topics/16/lesson');
    check('L3 unpublishing the lesson of an available topic -> 409 topic_in_use_set_draft_first; stale topic version -> 409; topic -> draft 200 (new version); students -> topic_content_not_available',
        err($un) === 'topic_in_use_set_draft_first' && err($stale) === 'edit_conflict' && $dr['status'] === 200 && d($dr)['status'] === 'draft'
        && d($dr)['version'] !== $t16['version'] && d($dr)['is_ready'] === false && d($dr)['content_complete'] === true
        && err($stuL) === 'topic_content_not_available', code($un) . ' | ' . code($dr) . ' | ' . code($stuL));
    $more = $s1->req('PUT', "/quiz-attempts/$open/answers/3", ['choice_id' => $openKey[3]['right']]);
    foreach ([4, 5] as $p) {
        $s1->req('PUT', "/quiz-attempts/$open/answers/$p", ['choice_id' => $openKey[$p]['wrong']]);
    }
    $osub = $s1->req('POST', "/quiz-attempts/$open/submit");
    check('H3 an attempt in progress when its question was edited and its topic set to draft: view was unchanged, it can still be answered and submitted, graded from its snapshot (3/5)',
        $openViewBefore === $openViewAfterEdit
        && $more['status'] === 200 && $osub['status'] === 200 && d($osub)['correct_count'] === 3, code($more) . ' | ' . code($osub));
    $u = $a1->req('POST', '/admin/topics/16/lesson/unpublish', ['expected_revision' => $r0 + 1]);
    $tv = topicOf($a1, 16)['version'];
    $av = $a1->req('PATCH', '/admin/topics/16', ['expected_version' => $tv, 'status' => 'available']);
    $rt = $a1->req('POST', '/admin/topics/16/lesson/retire', ['expected_revision' => $r0 + 1]);
    $pb = $a1->req('POST', '/admin/topics/16/lesson/publish', ['expected_revision' => $r0 + 1]);
    $pb2 = $a1->req('POST', '/admin/topics/16/lesson/publish', ['expected_revision' => $r0 + 1]);
    $av2 = $a1->req('PATCH', '/admin/topics/16', ['expected_version' => topicOf($a1, 16)['version'], 'status' => 'available']);
    foreach ([[$u, 'unpublish'], [$rt, 'retire'], [$pb, 'publish']] as [$resp, $act]) {
        if ($resp['status'] === 200) expectAudit('lesson', $L['lesson_id'], $act);
    }
    if ($av2['status'] === 200) expectAudit('topic', 16, 'publish');
    $stuL2 = $s1->req('GET', '/topics/16/lesson');
    check('L4 lesson unpublish 200 -> topic available refused (409 topic_not_ready, lesson_published false) -> retire 200 -> publish from retired 200 -> publish again 409 invalid_transition -> topic available 200 -> students read the a1 text',
        $u['status'] === 200 && err($av) === 'topic_not_ready' && $av['json']['error']['details']['lesson_published'] === false
        && d($rt)['status'] === 'retired' && d($pb)['status'] === 'published' && err($pb2) === 'invalid_transition' && $av2['status'] === 200
        && $stuL2['status'] === 200 && d($stuL2)['title'] === "[FIXTURE] Exceptions ($RUN, a1)",
        implode(' ', array_map('code', [$u, $av, $rt, $pb, $pb2, $av2, $stuL2])));
    $visits1 = (int)q($db, 'SELECT COALESCE(SUM(lesson_visit_count), 0) n FROM student_topic_progress')['n'];
    check('L5 administrator lesson reads recorded no student visit (only the student\'s own lesson read counted: +1)', $visits1 === $visits0 + 1, "$visits0 -> $visits1");

    // creation: topic 18 loses its fixture lesson (fixture DB only), then the API creates one
    $t18 = topicOf($a1, 18);
    $a1->req('PATCH', '/admin/topics/18', ['expected_version' => $t18['version'], 'status' => 'draft']);
    expectAudit('topic', 18, 'unpublish');
    $db->exec("DELETE FROM lessons WHERE topic_id = 18 AND title LIKE '[FIXTURE]%'");
    $c0 = $a1->req('PUT', '/admin/topics/18/lesson', ['expected_revision' => 0, 'title' => "[FIXTURE] OOP ($RUN)", 'body_markdown' => '[FIXTURE] created through the API']);
    $c1 = $a2->req('PUT', '/admin/topics/18/lesson', ['expected_revision' => 0, 'title' => '[FIXTURE] second create', 'body_markdown' => 'x']);
    $newLesson = (int)(d($c0)['lesson_id'] ?? 0);
    if ($c0['status'] === 201) expectAudit('lesson', $newLesson, 'create');
    $p18 = $a1->req('POST', '/admin/topics/18/lesson/publish', ['expected_revision' => 1]);
    if ($p18['status'] === 200) expectAudit('lesson', $newLesson, 'publish');
    $a18 = $a1->req('PATCH', '/admin/topics/18', ['expected_version' => topicOf($a1, 18)['version'], 'status' => 'available']);
    if ($a18['status'] === 200) expectAudit('topic', 18, 'publish');
    check('L6 lesson creation: expected_revision 0 -> 201 draft rev 1; a second create -> 409 edit_conflict {current_revision 1}; publish 200; topic available 200',
        $c0['status'] === 201 && d($c0)['status'] === 'draft' && d($c0)['revision'] === 1 && err($c1) === 'edit_conflict'
        && $c1['json']['error']['details'] === ['current_revision' => 1] && $p18['status'] === 200 && $a18['status'] === 200,
        code($c0) . ' | ' . code($c1) . ' | ' . code($a18));

    // =====================================================================
    // Q: questions and readiness threshold (topic 17)
    // =====================================================================
    $pool = topicOf($a1, 17)['quiz_pool_size'];
    $draft = $a1->req('POST', '/admin/questions', qBody(17, "[FIXTURE] draft $RUN", [['only one', false]], null));
    $qid = (int)(d($draft)['id'] ?? 0);
    if ($draft['status'] === 201) expectAudit('question', $qid, 'create');
    $act0 = $a1->req('POST', "/admin/questions/$qid/activate", ['expected_revision' => 1]);
    check('Q1 an incomplete draft (1 choice, no correct, no explanation) is accepted as draft (valid=false); activating it -> 400 needs_two_choices + explanation required',
        $draft['status'] === 201 && d($draft)['status'] === 'draft' && d($draft)['valid'] === false && $act0['status'] === 400
        && ($act0['json']['error']['details']['fields']['choices'] ?? '') === 'needs_two_choices'
        && ($act0['json']['error']['details']['fields']['explanation'] ?? '') === 'required', code($draft) . ' | ' . code($act0));
    $full = qBody(17, "[FIXTURE] complete $RUN", [['wrong', false], ['right', true], ['also wrong', false]]);
    $w1 = $a1->req('PUT', "/admin/questions/$qid", ['expected_revision' => 1] + $full);
    $w2 = $a2->req('PUT', "/admin/questions/$qid", ['expected_revision' => 1] + qBody(17, 'stale overwrite', [['a', true], ['b', false]]));
    if ($w1['status'] === 200) expectAudit('question', $qid, 'update');
    check('Q2 concurrent admin edits of revision 1: first 200 (rev 2, valid), second 409 edit_conflict {current_revision 2}; stem is the first edit',
        $w1['status'] === 200 && d($w1)['revision'] === 2 && d($w1)['valid'] === true && err($w2) === 'edit_conflict'
        && $w2['json']['error']['details'] === ['current_revision' => 2] && d($a2->req('GET', "/admin/questions/$qid"))['stem'] === "[FIXTURE] complete $RUN");
    $act = $a1->req('POST', "/admin/questions/$qid/activate", ['expected_revision' => 2]);
    if ($act['status'] === 200) expectAudit('question', $qid, 'publish');
    check('Q3 activate rev 2 -> active; topic 17 pool +1', $act['status'] === 200 && d($act)['status'] === 'active' && topicOf($a1, 17)['quiz_pool_size'] === $pool + 1,
        code($act));
    $vbad = [];
    foreach ([
        'two correct' => [qBody(17, 'x', [['a', true], ['b', true]]), 'choices', 'more_than_one_correct'],
        'labels out of order' => [array_merge(qBody(17, 'x', [['a', true], ['b', false]]), ['choices' => [['label' => 'B', 'text' => 'a', 'is_correct' => true], ['label' => 'A', 'text' => 'b', 'is_correct' => false]]]), 'choices', 'invalid'],
        'seven choices' => [qBody(17, 'x', array_fill(0, 7, ['a', false])), 'choices', 'invalid'],
        'no usage' => [qBody(17, 'x', [['a', true], ['b', false]], 'e', false, false), 'for_quiz', 'needs_one_usage'],
        'unknown topic' => [qBody(99, 'x', [['a', true], ['b', false]]), 'topic_id', 'invalid'],
        'empty stem' => [qBody(17, '  ', [['a', true], ['b', false]]), 'stem', 'required'],
        'empty choice text' => [qBody(17, 'x', [['', true], ['b', false]]), 'choices', 'invalid_text'],
        'smuggled status' => [qBody(17, 'x', [['a', true], ['b', false]]) + ['status' => 'active'], 'status', 'unknown_field'],
    ] as $label => [$b, $field, $want]) {
        $x = $a1->req('POST', '/admin/questions', $b);
        if ($x['status'] !== 400 || ($x['json']['error']['details']['fields'][$field] ?? '') !== $want) $vbad[] = "$label=" . json_encode($x['json']['error']['details']['fields'] ?? code($x));
    }
    $noExp = $a1->req('PUT', "/admin/questions/$qid", ['expected_revision' => 2] + qBody(17, 'x', [['a', true], ['b', false]], null));
    $retag = $a1->req('PUT', "/admin/questions/$qid", ['expected_revision' => 2] + qBody(17, 'x', [['a', true], ['b', false]], 'e', true, true));
    check('Q4 8 invalid question bodies -> 400 with field codes; an ACTIVE question without explanation -> 400; changing its usage -> 409 tagging_locked',
        $vbad === [] && ($noExp['json']['error']['details']['fields']['explanation'] ?? '') === 'required' && err($retag) === 'tagging_locked',
        implode('; ', $vbad) . ' | ' . code($noExp) . ' | ' . code($retag));

    // bring topic 17 to exactly the threshold, then cross below and above it
    $rq = $a1->req('POST', "/admin/questions/$qid/retire", ['expected_revision' => 2]);
    if ($rq['status'] === 200) expectAudit('question', $qid, 'retire');
    $orig = qa($db, "SELECT id, revision FROM questions WHERE topic_id = 17 AND for_quiz = 1 AND status = 'active' ORDER BY id");
    while (count($orig) > 10) {                                             // earlier runs may have left more than 10
        $o = array_shift($orig);
        $a1->req('POST', "/admin/questions/{$o['id']}/retire", ['expected_revision' => (int)$o['revision']]);
        expectAudit('question', (int)$o['id'], 'retire');
    }
    $victim = $orig[0];
    $auditBefore = auditCount($db);
    $no = $a1->req('POST', "/admin/questions/{$victim['id']}/retire", ['expected_revision' => (int)$victim['revision']]);
    $stillActive = q($db, 'SELECT status FROM questions WHERE id = ?', [$victim['id']])['status'];
    check('Q5 at exactly 10: retiring one more -> 409 would_make_topic_not_ready {topic 17, pool after 9}; question still active, no audit row',
        $rq['status'] === 200 && err($no) === 'would_make_topic_not_ready' && $no['json']['error']['details']['quiz_pool_size_after'] === 9
        && $stillActive === 'active' && auditCount($db) === $auditBefore, code($no));
    $yes = $a1->req('POST', "/admin/questions/{$victim['id']}/retire", ['expected_revision' => (int)$victim['revision'], 'confirm' => true]);
    if ($yes['status'] === 200) {
        expectAudit('question', (int)$victim['id'], 'retire');
        expectAudit('topic', 17, 'unpublish');
    }
    $t17 = topicOf($a1, 17);
    $sl = $s1->req('GET', '/topics/17/lesson');
    $sq = startQuiz($s1, 17);
    check('Q6 with confirm: retired, topic 17 -> draft (pool 9, not ready) in the same transaction; students get topic_content_not_available',
        $yes['status'] === 200 && $t17['status'] === 'draft' && $t17['quiz_pool_size'] === 9 && $t17['is_ready'] === false
        && err($sl) === 'topic_content_not_available' && err($sq) === 'topic_content_not_available');
    $na = $a1->req('PATCH', '/admin/topics/17', ['expected_version' => $t17['version'], 'status' => 'available']);
    $nq = $a1->req('POST', '/admin/questions', qBody(17, "[FIXTURE] refill $RUN", [['right', true], ['wrong', false]]));
    $nqid = (int)(d($nq)['id'] ?? 0);
    expectAudit('question', $nqid, 'create');
    $a1->req('POST', "/admin/questions/$nqid/activate", ['expected_revision' => 1]);
    expectAudit('question', $nqid, 'publish');
    $ya = $a1->req('PATCH', '/admin/topics/17', ['expected_version' => topicOf($a1, 17)['version'], 'status' => 'available']);
    if ($ya['status'] === 200) expectAudit('topic', 17, 'publish');
    check('Q7 below threshold: topic available -> 409 topic_not_ready {pool 9}; after one more active question (10): available 200 and students can start the quiz',
        err($na) === 'topic_not_ready' && $na['json']['error']['details']['quiz_pool_size'] === 9 && $ya['status'] === 200 && d($ya)['is_ready'] === true
        && in_array(startQuiz($s1, 17)['status'], [200, 201], true), code($na) . ' | ' . code($ya));

    // =====================================================================
    // Q8: placement-assessment pool protection (skill group G6, quota from the current policy)
    // =====================================================================
    $g6Sql = "SELECT q.id, q.revision FROM questions q JOIN topics t ON t.id = q.topic_id JOIN skill_groups g ON g.id = t.skill_group_id
               WHERE g.code = 'G6' AND q.for_assessment = 1 AND q.status = 'active' ORDER BY q.id";
    $quota = (int)q($db, "SELECT q.question_count n FROM assessment_group_quotas q JOIN skill_groups g ON g.id = q.skill_group_id
                           WHERE g.code = 'G6' AND q.policy_id = (SELECT id FROM assessment_policies WHERE is_current = 1)")['n'];
    foreach (array_slice(qa($db, $g6Sql), 0, max(0, count(qa($db, $g6Sql)) - $quota)) as $o) {   // first run only: down to the quota
        $x = $a1->req('POST', "/admin/questions/{$o['id']}/retire", ['expected_revision' => (int)$o['revision']]);
        if ($x['status'] === 200) expectAudit('question', (int)$o['id'], 'retire');
    }
    $extra = $a1->req('POST', '/admin/questions', qBody(18, "[FIXTURE] assessment extra $RUN", [['right', true], ['wrong', false]], 'Because.', false, true));
    $exId = (int)(d($extra)['id'] ?? 0);
    if ($extra['status'] === 201) expectAudit('question', $exId, 'create');
    $exAct = $a1->req('POST', "/admin/questions/$exId/activate", ['expected_revision' => 1]);
    if ($exAct['status'] === 200) expectAudit('question', $exId, 'publish');
    $exRet = $a1->req('POST', "/admin/questions/$exId/retire", ['expected_revision' => 1]);
    if ($exRet['status'] === 200) expectAudit('question', $exId, 'retire');
    $last = qa($db, $g6Sql);
    $auditBefore = auditCount($db);
    $block = $a1->req('POST', "/admin/questions/{$last[0]['id']}/retire", ['expected_revision' => (int)$last[0]['revision'], 'confirm' => true]);
    $stillOk = q($db, 'SELECT status FROM questions WHERE id = ?', [$last[0]['id']])['status'];
    $pl = newStudent('placecheck');
    place($pl, $db, []);
    check("Q8 assessment pool: at quota+1 retiring one is allowed; at the quota ($quota) retiring the last -> 409 would_break_assessment_pool {G6, available 0, required $quota} even with confirm; still active, no audit row; placement still works",
        $quota >= 1 && count($last) === $quota && $exRet['status'] === 200 && err($block) === 'would_break_assessment_pool'
        && $block['json']['error']['details'] === ['skill_group' => 'G6', 'available' => $quota - 1, 'required' => $quota]
        && $stillOk === 'active' && auditCount($db) === $auditBefore && d($pl->req('GET', '/me/dashboard'))['placement_rank']['code'] === 'E',
        code($exRet) . ' | ' . code($block));

    // =====================================================================
    // C: attempt creation while a question edit is in progress (topic 15)
    // =====================================================================
    $tag = "[rev $RUN]";
    $db->beginTransaction();                                                // simulated admin edit: question rows first, then choices
    $db->prepare("UPDATE questions SET stem = CONCAT(stem, ' ', ?), revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
                   WHERE topic_id = 15 AND for_quiz = 1 AND status = 'active'")->execute([$tag]);
    $db->prepare("UPDATE question_choices c JOIN questions q ON q.id = c.question_id SET c.choice_text = CONCAT(c.choice_text, ' ', ?)
                   WHERE q.topic_id = 15 AND q.for_quiz = 1 AND q.status = 'active'")->execute([$tag]);
    $t0 = microtime(true);
    [$cs] = orderedConcurrent([[$s1, $s1->asyncHandle('POST', '/topics/15/quiz-attempts', ['started_via' => 'direct'])]],
        static function () use ($db): void { usleep(500000); $db->commit(); }, 300);
    $elapsed = microtime(true) - $t0;
    $ca = (int)(d($cs)['attempt_id'] ?? 0);
    $snap = qa($db, 'SELECT aq.stem, aq.question_revision, q.revision cur, (SELECT COUNT(*) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id) n,
                            (SELECT SUM(c.choice_text LIKE ?) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id) tagged
                       FROM attempt_questions aq JOIN questions q ON q.id = aq.question_id WHERE aq.attempt_id = ?', ['%' . $tag, $ca]);
    $coherent = count($snap) === 5;
    foreach ($snap as $s) {
        $coherent = $coherent && str_ends_with($s['stem'], $tag) && (int)$s['tagged'] === (int)$s['n'] && (int)$s['question_revision'] === (int)$s['cur'];
    }
    check('C1 a quiz start arriving during an uncommitted question+choice edit waits for it and snapshots the NEW stem, NEW choices and NEW revision for all 5 (no mixed version)',
        $cs['status'] === 201 && $coherent && $elapsed >= 0.75, sprintf('%s, %.2fs, %d snapshots', code($cs), $elapsed, count($snap)));

    // =====================================================================
    // B: badge catalogue, approval, sale, races with purchases, retirement
    // =====================================================================
    $mk = $a1->req('POST', '/admin/badges', ['slug' => "fx-$RUN-a", 'name' => '[FIXTURE] Admin badge', 'description' => '[FIXTURE] created by the admin suite',
        'image_ref' => null, 'price' => 30]);
    $B = d($mk);
    $bid = (int)($B['id'] ?? 0);
    if ($mk['status'] === 201) expectAudit('badge', $bid, 'create');
    $art = $a1->req('POST', '/admin/badges', ['slug' => "fx-$RUN-art", 'name' => '[FIXTURE] With art ref', 'description' => '[FIXTURE] x', 'image_ref' => "badges/fx-$RUN.webp", 'price' => 5]);
    if ($art['status'] === 201) expectAudit('badge', (int)d($art)['id'], 'create');
    check('B1 create -> 201 draft, provisional, no approval, image_url null; an approved-path ref maps to /hunter-system/assets/badges/<file>',
        $mk['status'] === 201 && $B['status'] === 'draft' && $B['price_status'] === 'provisional' && $B['price_approval'] === null && $B['image_url'] === null
        && d($art)['image_url'] === "/hunter-system/assets/badges/fx-$RUN.webp", code($mk) . ' | ' . code($art));
    $v = $B['version'];
    $x1 = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => $v, 'status' => 'on_sale']);
    $x2 = $a1->req('POST', "/admin/badges/$bid/approve-price", ['expected_version' => $v, 'price' => 31]);
    $ap = $a1->req('POST', "/admin/badges/$bid/approve-price", ['expected_version' => $v, 'price' => 30]);
    if ($ap['status'] === 200) expectAudit('badge', $bid, 'update', 'approve_price');
    $x3 = $a2->req('POST', "/admin/badges/$bid/approve-price", ['expected_version' => d($ap)['version'] ?? '', 'price' => 30]);
    $AP = d($ap);
    check('B2 sale before approval -> 409 price_not_approved; approving a different price -> 409 price_mismatch; explicit approval by a1 -> approved, price_approval {a1, 30, time}; again -> 409 already_approved',
        err($x1) === 'price_not_approved' && err($x2) === 'price_mismatch' && $ap['status'] === 200 && $AP['price_status'] === 'approved'
        && $AP['price_approval']['approved_by']['id'] === $a1id && $AP['price_approval']['price'] === 30 && is_string($AP['price_approval']['approved_at'])
        && err($x3) === 'already_approved', implode(' ', array_map('code', [$x1, $x2, $ap, $x3])));
    $os = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => $AP['version'], 'status' => 'on_sale']);
    if ($os['status'] === 200) expectAudit('badge', $bid, 'publish');
    $inStore = in_array($bid, array_column(d($s1->req('GET', '/badges')), 'id'), true);
    $pc = $a2->req('PUT', "/admin/badges/$bid", ['expected_version' => d($os)['version'], 'price' => 40]);
    if ($pc['status'] === 200) expectAudit('badge', $bid, 'update', 'price_change');
    $stalePub = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($os)['version'], 'status' => 'on_sale']);
    $freshPub = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($pc)['version'], 'status' => 'on_sale']);
    check('B3 on sale -> listed in the store; a2 changes the price to 40 -> provisional + off_sale + approval cleared, unlisted; a1\'s stale publish -> 409 edit_conflict; fresh publish -> 409 price_not_approved',
        $os['status'] === 200 && $inStore && $pc['status'] === 200 && d($pc)['price_status'] === 'provisional' && d($pc)['status'] === 'off_sale'
        && d($pc)['price_approval'] === null && !in_array($bid, array_column(d($s1->req('GET', '/badges')), 'id'), true)
        && err($stalePub) === 'edit_conflict' && err($freshPub) === 'price_not_approved', code($pc) . ' | ' . code($stalePub) . ' | ' . code($freshPub));
    $ap2 = $a1->req('POST', "/admin/badges/$bid/approve-price", ['expected_version' => d($pc)['version'], 'price' => 40]);
    expectAudit('badge', $bid, 'update', 'approve_price');
    $os2 = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($ap2)['version'], 'status' => 'on_sale']);
    expectAudit('badge', $bid, 'publish');

    // race 1: the admin takes the badge off sale while a purchase waits for the student's wallet
    $release = holdProfileLock($db, $u1);
    [$buy1, $off] = orderedConcurrent([[$s1, $s1->asyncHandle('POST', "/badges/$bid/purchase")],
                                       [$a1b, $a1b->asyncHandle('POST', "/admin/badges/$bid/status", ['expected_version' => d($os2)['version'], 'status' => 'off_sale'])]], $release);
    if ($off['status'] === 200) expectAudit('badge', $bid, 'unpublish');
    check('B4 race: purchase waiting on the wallet while the admin takes the badge off sale -> admin 200 first; the purchase then sees off_sale -> 409 not_on_sale, no debit',
        $off['status'] === 200 && err($buy1) === 'not_on_sale' && q($db, 'SELECT id FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u1, $bid]) === null,
        code($off) . ' | ' . code($buy1));
    $os3 = $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($off)['version'], 'status' => 'on_sale']);
    expectAudit('badge', $bid, 'publish');
    // race 2: a purchase holds the badge row (share) while the admin changes the price
    $db->beginTransaction();
    q($db, 'SELECT id FROM badges WHERE id = ? LOCK IN SHARE MODE', [$bid]);
    [$buy2, $pc2] = orderedConcurrent([[$s1, $s1->asyncHandle('POST', "/badges/$bid/purchase")],
                                       [$a1b, $a1b->asyncHandle('PUT', "/admin/badges/$bid", ['expected_version' => d($os3)['version'], 'price' => 45])]],
        static function () use ($db): void { $db->commit(); });
    if ($pc2['status'] === 200) expectAudit('badge', $bid, 'update', 'price_change');
    $own = q($db, 'SELECT price_paid FROM badge_ownership WHERE user_id = ? AND badge_id = ?', [$u1, $bid]);
    check('B5 race: a purchase sharing the badge row completes at the approved price 40 while the price change waits; the change then applies (45, provisional, off sale); price_paid stays 40',
        $buy2['status'] === 200 && d($buy2)['price_paid'] === 40 && $pc2['status'] === 200 && d($pc2)['price'] === 45 && d($pc2)['status'] === 'off_sale'
        && (int)($own['price_paid'] ?? 0) === 40, code($buy2) . ' | ' . code($pc2));
    $s1->req('PUT', '/me/showcase', ['badge_ids' => [$bid]]);
    $ret = $a2->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($pc2)['version'], 'status' => 'retired']);
    if ($ret['status'] === 200) expectAudit('badge', $bid, 'retire');
    $prof = d($s1->req('GET', '/me/profile'));
    $owned = array_values(array_filter($prof['owned_badges'], static fn($o) => $o['badge_id'] === $bid))[0] ?? [];
    $after = [$a1->req('PUT', "/admin/badges/$bid", ['expected_version' => d($ret)['version'], 'name' => 'x']),
              $a1->req('POST', "/admin/badges/$bid/status", ['expected_version' => d($ret)['version'], 'status' => 'on_sale'])];
    check('B6 retire -> owner keeps it (status retired, price_paid 40) and it stays showcased; later edits or status changes -> 409 badge_retired; owners=1',
        $ret['status'] === 200 && ($owned['price_paid'] ?? 0) === 40 && ($owned['status'] ?? '') === 'retired' && array_column($prof['showcase'], 'badge_id') === [$bid]
        && err($after[0]) === 'badge_retired' && err($after[1]) === 'badge_retired' && d($ret)['owners'] === 1, code($ret));

    // =====================================================================
    // X: content + audit rollback after a controlled failure
    // =====================================================================
    $bv = d($a1->req('GET', '/admin/badges'));
    $art2 = array_values(array_filter($bv, static fn($b) => $b['slug'] === "fx-$RUN-art"))[0];
    $nQ = (int)q($db, 'SELECT COUNT(*) n FROM questions')['n'];
    $nA = auditCount($db);
    $db->exec('REVOKE INSERT ON ' . FIXTURE_DB . '.audit_log FROM ' . FIXTURE_ACCOUNT);
    try {
        $f1 = $a1->req('PUT', "/admin/badges/{$art2['id']}", ['expected_version' => $art2['version'], 'name' => '[FIXTURE] renamed']);
        $f2 = $a1->req('POST', '/admin/questions', qBody(17, '[FIXTURE] never stored', [['a', true], ['b', false]]));
    } finally {
        $db->exec('GRANT INSERT ON ' . FIXTURE_DB . '.audit_log TO ' . FIXTURE_ACCOUNT);
    }
    $art3 = array_values(array_filter(d($a1->req('GET', '/admin/badges')), static fn($b) => $b['slug'] === "fx-$RUN-art"))[0];
    check('X1 audit INSERT refused: badge rename and question create -> 500; badge unchanged (same version and name), no question row, no audit row',
        $f1['status'] === 500 && $f2['status'] === 500 && $art3['version'] === $art2['version'] && $art3['name'] === $art2['name']
        && (int)q($db, 'SELECT COUNT(*) n FROM questions')['n'] === $nQ && auditCount($db) === $nA, code($f1) . ' | ' . code($f2));
    $ok = $a1->req('PUT', "/admin/badges/{$art2['id']}", ['expected_version' => $art2['version'], 'name' => '[FIXTURE] renamed']);
    if ($ok['status'] === 200) expectAudit('badge', (int)$art2['id'], 'update');
    check('X2 after restoring the grant the same rename succeeds once', $ok['status'] === 200 && d($ok)['name'] === '[FIXTURE] renamed', code($ok));

    // =====================================================================
    // A: audit history matches exactly what succeeded
    // =====================================================================
    $since = q($db, 'SELECT DATE_FORMAT(MIN(created_at), "%Y-%m-%dT%H:%i:%s.%fZ") s FROM audit_log WHERE id > ?', [$auditStart])['s'];
    $since = substr($since, 0, 23) . 'Z';
    $all = [];
    for ($page = 1; ; $page++) {
        $pg = d($a1->req('GET', "/admin/audit-log?since=$since&per_page=100&page=$page"));
        $all = array_merge($all, $pg['entries']);
        if (count($pg['entries']) < 100) break;
    }
    $mine = array_values(array_filter(array_reverse($all), static fn($e) => in_array($e['admin']['id'], [$a1id, $a2id], true)));
    $got = array_map(static fn($e) => [$e['entity_type'], $e['entity_id'], $e['action'], $e['operation']], $mine);
    check('A1 the audit trail of this run equals the list of successful mutations, in order (' . count($EXPECT) . ' entries); refused and failed ones left none',
        $got === $EXPECT, 'got ' . count($got) . ' expected ' . count($EXPECT) . firstDiff($got, $EXPECT));
    $pcEntry = array_values(array_filter($mine, static fn($e) => $e['entity_id'] === $bid && $e['operation'] === 'price_change'))[0] ?? [];
    $apEntry = array_values(array_filter($mine, static fn($e) => $e['entity_id'] === $bid && $e['operation'] === 'approve_price'))[0] ?? [];
    check('A2 audit values: price change by a2 records before {30, approved, on_sale} -> after {40, provisional, off_sale}; approval by a1 records price 30',
        $pcEntry['admin']['id'] === $a2id && $pcEntry['before']['price_coins'] === 30 && $pcEntry['before']['price_status'] === 'approved'
        && $pcEntry['before']['status'] === 'on_sale' && $pcEntry['after']['price_coins'] === 40 && $pcEntry['after']['price_status'] === 'provisional'
        && $pcEntry['after']['status'] === 'off_sale' && $apEntry['admin']['id'] === $a1id && $apEntry['after']['price_coins'] === 30);
    $fa = d($a1->req('GET', "/admin/audit-log?entity_type=badge&entity_id=$bid&per_page=100"));
    $fb = d($a1->req('GET', "/admin/audit-log?admin_id=$a2id&action=update&per_page=100"));
    $p1 = d($a1->req('GET', "/admin/audit-log?entity_type=badge&entity_id=$bid&per_page=2&page=1"));
    $p2 = d($a1->req('GET', "/admin/audit-log?entity_type=badge&entity_id=$bid&per_page=2&page=2"));
    $future = d($a1->req('GET', '/admin/audit-log?since=2099-01-01T00:00:00Z'));
    $keys = array_keys($fa['entries'][0] ?? []);
    check('A3 filters: entity -> only that badge; admin+action -> only a2 updates; pages of 2 do not overlap; future since -> 0; entry fields exact',
        $fa['total'] === count(array_filter($EXPECT, static fn($e) => $e[0] === 'badge' && $e[1] === $bid))
        && array_unique(array_column($fa['entries'], 'entity_id')) === [$bid]
        && array_unique(array_map(static fn($e) => $e['admin']['id'] . $e['action'], $fb['entries'])) === [$a2id . 'update']
        && array_intersect(array_column($p1['entries'], 'id'), array_column($p2['entries'], 'id')) === [] && count($p1['entries']) === 2
        && $future['total'] === 0 && $keys === ['id', 'at', 'admin', 'action', 'operation', 'entity_type', 'entity_id', 'before', 'after']);
    $secret = array_filter($all, static fn($e) => preg_match('/password|csrf|session|token|DB_|THROTTLE/i', json_encode([$e['before'], $e['after']])));
    check('A4 no audit entry contains credentials, tokens, session data or configuration', $secret === []);
    check('A5 the dev database audit log was not touched by fixture runs', (int)q($db, 'SELECT COUNT(*) n FROM hunter_system_dev.audit_log')['n'] === $devAudit);
} finally {
    // restore: every touched topic whose content is complete is available again; this run's badges are retired
    foreach ($touched as $t) {
        $db->prepare("UPDATE topics t JOIN v_topic_readiness r ON r.topic_id = t.id SET t.status = 'available'
                       WHERE t.id = ? AND t.status = 'draft' AND r.lesson_published = 1 AND r.quiz_pool_size >= 10")->execute([$t]);
    }
    $db->exec("UPDATE badges SET status = 'retired' WHERE slug LIKE 'fx-$RUN-%' AND status <> 'retired'");
}
$ready = (int)q($db, 'SELECT COUNT(*) n FROM v_topic_readiness WHERE is_ready = 1')['n'];
check('F9 fixture left consistent: all 18 topics ready again', $ready === 18, "ready=$ready");

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
$n = (int)q($db, 'SELECT COUNT(*) n FROM users WHERE email LIKE ?', ["m5f.$RUN.%"])['n'];
printf("\n%d checks, %d passed, %d failed. Fixture accounts: %d (m5f.%s.*@example.invalid), %s only.\n",
    count($results), count($results) - $failed, $failed, $n, $RUN, FIXTURE_DB);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
