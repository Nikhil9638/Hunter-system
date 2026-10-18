<?php
/**
 * Milestone 3 integration tests: lessons, topic quizzes, first-completion rewards,
 * next-topic unlock, rank rules, ledger and dashboard consistency.
 * Live HTTP through Apache (API runs as hunter_app) against hunter_system_dev,
 * which has ready content for topics 1 and 2 only. Rank thresholds, the final
 * topic and the controlled rollback run against the isolated fixture database
 * (quiz_fixture_test.php).
 *
 *   C:\xampp\php\php.exe backend\tests\quiz_integration_test.php
 *
 * The database ADMIN connection (root; HUNTER_TEST_DB_ADMIN_PASSWORD) only READS the
 * snapshot answer key, HOLDS a profile row lock to fix the arrival order of
 * concurrent requests, and inspects rows. It never writes history.
 * Test accounts: m3.<run>.*@example.invalid (append-only history remains, by design).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Tests\Client;

use function Hunter\Tests\allKeys;
use function Hunter\Tests\orderedConcurrent;

Console::requireCli();
$API = getenv('HUNTER_TEST_API') ?: 'http://localhost/hunter-system/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m3_' . $RUN;
@mkdir($TMP);
$config = Config::load();
$API=\Hunter\Tests\seedFixtureApi($config,$API);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'quiz test password';
$PREFIX = 'm3';
$FORBIDDEN_KEYS = ['is_correct', 'correct', 'explanation', 'source_choice_id', 'question_id'];
const GRADES = [0 => ['F', 0], 1 => ['E', 20], 2 => ['D', 40], 3 => ['C', 60], 4 => ['B', 80], 5 => ['A', 100]];

require __DIR__ . '/QuizSupport.php';

echo "Hunter System milestone 3 integration tests (API $API, DB " . $config->get('DB_NAME') . ")  run=$RUN\n\n";
$ready = array_map('intval', array_column(qa($db, 'SELECT topic_id FROM v_topic_readiness WHERE is_ready = 1 ORDER BY position'), 'topic_id'));
check('R0 dev content: topics 1 and 2 ready, 3-18 not ready (no content was invented)', $ready === [1, 2], 'ready=' . implode(',', $ready));

// ---------------------------------------------------------------------------
// L: topic access and lessons
// ---------------------------------------------------------------------------
$e = newStudent('e');                                        // will be placed E (topic 1 only)
$eid = userId($e);
$r1 = $e->req('GET', '/topics/1/lesson');
$r2 = startQuiz($e, 1);
check('L1 before placement: lesson and quiz start -> 409 placement_required',
    $r1['status'] === 409 && $r1['json']['error']['code'] === 'placement_required' && $r2['status'] === 409 && $r2['json']['error']['code'] === 'placement_required',
    code($r1) . ' | ' . code($r2));
place($e, $db, []);
$d = $e->req('GET', '/me/dashboard')['json']['data'];
check('L2 placed E with only topic 1 unlocked', $d['hunter_rank']['code'] === 'E' && topicRow($e, 1)['unlocked'] === true && topicRow($e, 2)['unlocked'] === false);

$les = $e->req('GET', '/topics/1/lesson');
$v1 = q($db, 'SELECT first_lesson_visit_at f, last_lesson_visit_at l, lesson_visit_count n FROM student_topic_progress WHERE user_id = ? AND topic_id = 1', [$eid]);
check('L3 GET /topics/1/lesson -> 200 with exactly topic_id, title, body_markdown, revision',
    $les['status'] === 200 && array_keys($les['json']['data']) === ['topic_id', 'title', 'body_markdown', 'revision']
    && $les['json']['data']['topic_id'] === 1 && $les['json']['data']['body_markdown'] !== '', code($les));
usleep(20000);
$e->req('GET', '/topics/1/lesson');
$v2 = q($db, 'SELECT first_lesson_visit_at f, last_lesson_visit_at l, lesson_visit_count n FROM student_topic_progress WHERE user_id = ? AND topic_id = 1', [$eid]);
check('L4 visits recorded: count 1 then 2, first visit kept, last visit advanced',
    (int)$v1['n'] === 1 && $v1['f'] === $v1['l'] && (int)$v2['n'] === 2 && $v2['f'] === $v1['f'] && $v2['l'] > $v1['l'], json_encode([$v1, $v2]));
$t1 = topicRow($e, 1);
$c0 = counts($db, $eid);
check('L5 a lesson visit completes and passes nothing: available, visited, 0 completed, no result/coins',
    $t1['status'] === 'available' && $t1['lesson_visited'] === true && $t1['completed_attempts'] === 0 && $t1['first_passed_at'] === null
    && (int)$c0['results'] === 0 && (int)$c0['balance'] === 0 && (int)$c0['claims'] === 0, json_encode($t1));
$lk1 = $e->req('GET', '/topics/2/lesson');
$lk2 = startQuiz($e, 2);
check('L6 locked topic 2: lesson and quiz start -> 409 topic_locked',
    $lk1['status'] === 409 && $lk1['json']['error']['code'] === 'topic_locked' && $lk2['status'] === 409 && $lk2['json']['error']['code'] === 'topic_locked',
    code($lk1) . ' | ' . code($lk2));
$nf = [$e->req('GET', '/topics/99/lesson')['status'], startQuiz($e, 99)['status'], $e->req('GET', '/topics/0/lesson')['status'],
       $e->req('GET', '/topics/abc/lesson')['status'], $e->req('GET', '/topics/99/quiz-attempts')['status']];
check('L7 unknown or malformed topic ids -> 404', $nf === [404, 404, 404, 404, 404], implode(',', $nf));

$s = newStudent('s');                                        // placed S: every topic unlocked
$sid = userId($s);
place($s, $db, [1, 2, 3, 4, 5, 6]);
$before = (int)q($db, "SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND kind = 'topic_quiz'", [$sid])['n'];
$u1 = $s->req('GET', '/topics/3/lesson');
$u2 = startQuiz($s, 3);
$u3 = startQuiz($s, 18, 'lesson_first');
$after = (int)q($db, "SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND kind = 'topic_quiz'", [$sid])['n'];
$t3 = topicRow($s, 3);
check('L8 unlocked but not ready (topics 3, 18): lesson/quiz -> 409 topic_content_not_available, no attempt created',
    $u1['status'] === 409 && $u1['json']['error']['code'] === 'topic_content_not_available' && $u2['json']['error']['code'] === 'topic_content_not_available'
    && $u3['json']['error']['code'] === 'topic_content_not_available' && $before === 0 && $after === 0
    && $t3['unlocked'] === true && $t3['content_ready'] === false, code($u1) . ' | ' . code($u2) . ' | ' . code($u3));

// ---------------------------------------------------------------------------
// Q: starting, resuming, question selection
// ---------------------------------------------------------------------------
$bad = [];
foreach ([[(object)[], 'required'], [['started_via' => 'random'], 'invalid'], [['started_via' => 1], 'invalid'], [['started_via' => 'direct', 'topic_id' => 2], 'unknown_field']] as [$body, $expect]) {
    $x = $e->req('POST', '/topics/1/quiz-attempts', $body);
    $f = $x['json']['error']['details']['fields'] ?? [];
    if ($x['status'] !== 400 || !in_array($expect, $f, true)) {
        $bad[] = json_encode($body) . '=' . code($x);
    }
}
$nb = $e->req('POST', '/topics/1/quiz-attempts');
$x1 = $e->req('POST', '/topics/1/quiz-attempts', ['started_via' => 'direct'], [], false);
check('Q1 invalid start bodies -> 400 (4 cases); no body -> 400; no CSRF -> 403',
    $bad === [] && $nb['status'] === 400 && $x1['status'] === 403, implode('; ', $bad) . ' nobody=' . code($nb) . ' nocsrf=' . code($x1));

$st = startQuiz($e, 1, 'lesson_first');
$a1 = (int)($st['json']['data']['attempt_id'] ?? 0);
$qs = $st['json']['data']['questions'] ?? [];
check('Q2 first start -> 201: attempt 1, in_progress, lesson_first, 5 questions at positions 1-5 with choices, nothing selected',
    $st['status'] === 201 && $st['json']['data']['attempt_number'] === 1 && $st['json']['data']['status'] === 'in_progress'
    && $st['json']['data']['started_via'] === 'lesson_first' && $st['json']['data']['resumed'] === false && count($qs) === 5
    && array_column($qs, 'position') === [1, 2, 3, 4, 5] && min(array_map(static fn($x) => count($x['choices']), $qs)) >= 2
    && array_filter(array_column($qs, 'selected_choice_id')) === [], code($st));
check('Q3 start response reveals no answers (no is_correct/correct/explanation/question ids)', noReveal($st));
$snap = qa($db, 'SELECT aq.question_id, aq.explanation, aq.topic_id, q.for_quiz, q.status,
                        (SELECT COUNT(*) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id) nc,
                        (SELECT SUM(c.is_correct) FROM attempt_question_choices c WHERE c.attempt_question_id = aq.id) ncor
                   FROM attempt_questions aq JOIN questions q ON q.id = aq.question_id WHERE aq.attempt_id = ?', [$a1]);
check('Q4 snapshot: 5 DISTINCT active quiz questions of topic 1, choices + one correct + explanation copied',
    count($snap) === 5 && count(array_unique(array_column($snap, 'question_id'))) === 5
    && array_unique(array_map('intval', array_column($snap, 'topic_id'))) === [1] && array_unique(array_map('intval', array_column($snap, 'for_quiz'))) === [1]
    && array_unique(array_column($snap, 'status')) === ['active'] && array_unique(array_map('intval', array_column($snap, 'ncor'))) === [1]
    && min(array_map('intval', array_column($snap, 'nc'))) >= 2 && !in_array(null, array_column($snap, 'explanation'), true));

$key1 = answerKey($db, $a1);
$sv = $e->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => $key1[1]['wrong']]);
check('Q5 save answer -> 200 {position, selected_choice_id} only', $sv['status'] === 200
    && $sv['json']['data'] === ['position' => 1, 'selected_choice_id' => $key1[1]['wrong']] && noReveal($sv), code($sv));
$rs = startQuiz($e, 1, 'direct');
check('Q6 repeated start resumes: 200, same attempt, resumed=true, original started_via kept, saved answer shown',
    $rs['status'] === 200 && $rs['json']['data']['attempt_id'] === $a1 && $rs['json']['data']['resumed'] === true
    && $rs['json']['data']['started_via'] === 'lesson_first' && $rs['json']['data']['questions'][0]['selected_choice_id'] === $key1[1]['wrong']
    && $rs['json']['data']['answered_count'] === 1 && noReveal($rs), code($rs));
$ga = $e->req('GET', "/quiz-attempts/$a1");
check('Q7 GET /quiz-attempts/{id} in progress -> attempt view, no answers revealed',
    $ga['status'] === 200 && $ga['json']['data']['status'] === 'in_progress' && !isset($ga['json']['data']['review']) && noReveal($ga), code($ga));

// ---------------------------------------------------------------------------
// A: answers, ownership
// ---------------------------------------------------------------------------
$otherChoice = $key1[2]['right'];
$ic = $e->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => $otherChoice]);
$ic2 = $e->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => 999999999]);
$iv = [$e->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => '5'])['status'], $e->req('PUT', "/quiz-attempts/$a1/answers/1", [])['status'],
       $e->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => 5, 'correct' => true])['status'],
       $e->req('PUT', "/quiz-attempts/$a1/answers/6", ['choice_id' => $key1[1]['right']])['status'],
       $e->req('PUT', "/quiz-attempts/$a1/answers/0", ['choice_id' => $key1[1]['right']])['status']];
check('A1 another question\'s choice / unknown choice -> 400 invalid_choice; bad bodies 400; position 6 or 0 -> 404',
    $ic['status'] === 400 && $ic['json']['error']['code'] === 'invalid_choice' && $ic2['json']['error']['code'] === 'invalid_choice'
    && $iv === [400, 400, 400, 404, 404], code($ic) . ' ' . implode(',', $iv));
$x = [$s->req('GET', "/quiz-attempts/$a1")['status'], $s->req('PUT', "/quiz-attempts/$a1/answers/1", ['choice_id' => $key1[1]['right']])['status'],
      $s->req('POST', "/quiz-attempts/$a1/submit")['status']];
$ownAssessment = (int)q($db, 'SELECT placement_attempt_id a FROM student_profiles WHERE user_id = ?', [$eid])['a'];
$y = [$e->req('GET', "/quiz-attempts/$ownAssessment")['status'], $e->req('POST', "/quiz-attempts/$ownAssessment/submit")['status']];
$still = q($db, 'SELECT selected_choice_id s FROM attempt_questions WHERE attempt_id = ? AND position = 1', [$a1]);
check('A2 another student: GET/PUT/submit of this attempt -> 404; an assessment id on quiz routes -> 404; answer unchanged',
    $x === [404, 404, 404] && $y === [404, 404] && (int)$still['s'] === $key1[1]['wrong'], implode(',', $x) . ' | ' . implode(',', $y));
$anon = new Client($API, $TMP, 'anon');
$an = [$anon->req('GET', "/quiz-attempts/$a1")['status'], $anon->req('GET', '/me/coins')['status'], $anon->req('GET', '/topics/1/lesson')['status']];
check('A3 anonymous -> 401 on quiz, coins and lesson endpoints', $an === [401, 401, 401], implode(',', $an));

// ---------------------------------------------------------------------------
// S: submit, grading, reward, unlock
// ---------------------------------------------------------------------------
for ($p = 1; $p <= 4; $p++) {                                           // 4 of 5 answered
    $e->req('PUT', "/quiz-attempts/$a1/answers/$p", ['choice_id' => $p <= 2 ? $key1[$p]['right'] : $key1[$p]['wrong']]);
}
$inc = $e->req('POST', "/quiz-attempts/$a1/submit");
$st1 = q($db, 'SELECT status FROM attempts WHERE id = ?', [$a1]);
check('S1 incomplete submit -> 409 attempt_incomplete [5]; still in progress, no result, no reward',
    $inc['status'] === 409 && $inc['json']['error']['code'] === 'attempt_incomplete' && $inc['json']['error']['details']['unanswered_positions'] === [5]
    && $st1['status'] === 'in_progress' && (int)counts($db, $eid)['results'] === 0 && (int)counts($db, $eid)['claims'] === 0 && noReveal($inc), code($inc));
$e->req('PUT', "/quiz-attempts/$a1/answers/5", ['choice_id' => $key1[5]['wrong']]);
$sub = $e->req('POST', "/quiz-attempts/$a1/submit");
$R = $sub['json']['data'] ?? [];
check('S2 failed first completion (2/5): D 40%, not passed, label "Quiz performance", already_submitted=false',
    $sub['status'] === 200 && $R['correct_count'] === 2 && $R['grade'] === 'D' && $R['percent'] === 40 && $R['passed'] === false
    && $R['label'] === 'Quiz performance' && $R['already_submitted'] === false && $R['question_count'] === 5, code($sub));
check('S3 failed first completion pays once: reward 10 topic_first_completion, balance_after 10; no unlock; no promotion',
    $R['coins_awarded'] === 10 && $R['reward'] === ['coins' => 10, 'reason' => 'topic_first_completion', 'balance_after' => 10]
    && $R['unlocked_topic'] === null && $R['promotion'] === null && topicRow($e, 2)['unlocked'] === false
    && $e->req('GET', '/topics/2/lesson')['json']['error']['code'] === 'topic_locked');
$rev = $R['review'] ?? [];
$revOk = count($rev) === 5;
foreach ($rev as $i => $item) {
    $k = $key1[$item['position']];
    $corr = array_values(array_filter($item['choices'], static fn($c) => $c['correct'] === true));
    $revOk = $revOk && count($corr) === 1 && $corr[0]['id'] === $k['right'] && $item['explanation'] !== null && $item['explanation'] !== ''
        && $item['selected_choice_id'] === ($item['position'] <= 2 ? $k['right'] : $k['wrong']) && $item['is_correct'] === ($item['position'] <= 2);
}
check('S4 post-submission review: 5 items with the correct choice, explanation, own selection and is_correct', $revOk);
$af = $e->req('PUT', "/quiz-attempts/$a1/answers/3", ['choice_id' => $key1[3]['right']]);
check('S5 answer after submission -> 409 attempt_finalized', $af['status'] === 409 && $af['json']['error']['code'] === 'attempt_finalized', code($af));
$c1 = counts($db, $eid);
$dup = $e->req('POST', "/quiz-attempts/$a1/submit");
$D = $dup['json']['data'] ?? [];
$c2 = counts($db, $eid);
$Rcmp = $R;
$Dcmp = $D;
unset($Rcmp['already_submitted'], $Dcmp['already_submitted']);
check('S6 duplicate submit -> 200 identical stored result with already_submitted=true; no new rows',
    $dup['status'] === 200 && $D['already_submitted'] === true && $Rcmp === $Dcmp && $c1 === $c2, json_encode($c2));
$gs = $e->req('GET', "/quiz-attempts/$a1");
check('S7 GET submitted attempt -> stored result (review included, no already_submitted key)',
    $gs['status'] === 200 && !array_key_exists('already_submitted', $gs['json']['data']) && count($gs['json']['data']['review']) === 5
    && $gs['json']['data']['reward']['balance_after'] === 10, code($gs));

// retry that passes: unlocks topic 2, no second reward
[$a2, $sub2] = takeQuiz($e, $db, 1, 4);
$R2 = $sub2['json']['data'] ?? [];
$n2 = (int)q($db, "SELECT attempt_number n FROM attempts WHERE id = ?", [$a2])['n'];
check('S8 passing retry (4/5, attempt 2): B 80%, passed; coins_awarded 0, reward null; unlocks topic 2 (content_ready true)',
    $sub2['status'] === 200 && $n2 === 2 && $R2['grade'] === 'B' && $R2['percent'] === 80 && $R2['passed'] === true && $R2['coins_awarded'] === 0
    && $R2['reward'] === null && $R2['unlocked_topic'] === ['id' => 2, 'title' => $R2['unlocked_topic']['title'] ?? '', 'content_ready' => true]
    && $R2['promotion'] === null, code($sub2) . ' ' . json_encode($R2['unlocked_topic'] ?? null));
$t2 = topicRow($e, 2);
$c3 = counts($db, $eid);
check('S9 topic 2 now unlocked (passed_previous) and its lesson is readable; balance still 10, one claim, one ledger entry',
    $t2['unlocked'] === true && $t2['unlock_source'] === 'passed_previous' && $e->req('GET', '/topics/2/lesson')['status'] === 200
    && (int)$c3['balance'] === 10 && (int)$c3['claims'] === 1 && (int)$c3['ledger'] === 1, json_encode($c3));
$firstPass = topicRow($e, 1)['first_passed_at'];

// failed retry keeps the earlier pass and unlock
[$a3, $sub3] = takeQuiz($e, $db, 1, 1);
$R3 = $sub3['json']['data'] ?? [];
$t1 = topicRow($e, 1);
check('S10 failed retry (1/5): E 20%, not passed; topic 1 stays passed (first_passed_at unchanged); topic 2 stays unlocked; no reward',
    $R3['grade'] === 'E' && $R3['percent'] === 20 && $R3['passed'] === false && $R3['reward'] === null && $R3['unlocked_topic'] === null
    && $t1['status'] === 'passed' && $t1['first_passed_at'] === $firstPass && topicRow($e, 2)['unlocked'] === true, json_encode($t1));

// unseen-first selection across attempts 1-3 (pool of 12)
$pool = array_map('intval', array_column(qa($db, "SELECT q.id FROM questions q WHERE q.topic_id = 1 AND q.for_quiz = 1 AND q.status = 'active'"), 'id'));
$set = static fn(int $aid) => array_map('intval', array_column(qa($db, 'SELECT question_id FROM attempt_questions WHERE attempt_id = ?', [$aid]), 'question_id'));
[$s1, $s2, $s3] = [$set($a1), $set($a2), $set($a3)];
$neverSeen = array_values(array_diff($pool, $s1, $s2));
check('S11 unseen preference: attempt 2 shares nothing with attempt 1; attempt 3 includes the ' . count($neverSeen) . ' never-seen questions, then reuses seen ones',
    count($pool) === 12 && array_intersect($s1, $s2) === [] && count($neverSeen) === 2 && array_diff($neverSeen, $s3) === []
    && count(array_unique($s3)) === 5 && count(array_intersect($s3, array_merge($s1, $s2))) === 3, 'pool=' . count($pool));

// every score and grade: 2, 4, 1 done; now 0, 3, 5
$grades = [2 => [$R['grade'], $R['percent'], $R['passed']], 4 => [$R2['grade'], $R2['percent'], $R2['passed']], 1 => [$R3['grade'], $R3['percent'], $R3['passed']]];
foreach ([0, 3, 5] as $n) {
    [, $sx] = takeQuiz($e, $db, 1, $n);
    $grades[$n] = [$sx['json']['data']['grade'] ?? '?', $sx['json']['data']['percent'] ?? -1, $sx['json']['data']['passed'] ?? null];
}
ksort($grades);
$expectG = [];
foreach (GRADES as $n => [$g, $pc]) {
    $expectG[$n] = [$g, $pc, $n >= 3];
}
check('S12 every score: 0=F0 1=E20 2=D40 3=C60 4=B80 5=A100; pass at 3+', $grades === $expectG, json_encode($grades));
$hist = $e->req('GET', '/topics/1/quiz-attempts')['json']['data'] ?? [];
check('S13 topic history: 6 attempts in order, all submitted, correct counts 2,4,1,0,3,5',
    array_column($hist, 'attempt_number') === [1, 2, 3, 4, 5, 6] && array_column($hist, 'correct_count') === [2, 4, 1, 0, 3, 5]
    && array_unique(array_column($hist, 'status')) === ['submitted'] && $hist[0]['started_via'] === 'lesson_first');
$c4 = counts($db, $eid);
$t1 = topicRow($e, 1);
$d = $e->req('GET', '/me/dashboard')['json']['data'];
check('S14 repasses add nothing: 1 claim, 1 ledger row, balance 10, no promotion; topics_passed 1; best 5; 6 completed',
    (int)$c4['claims'] === 1 && (int)$c4['ledger'] === 1 && (int)$c4['balance'] === 10 && (int)$c4['ranks'] === 1
    && $d['topics_passed'] === 1 && $t1['best_correct'] === 5 && $t1['completed_attempts'] === 6, json_encode($c4));
$late = $e->req('GET', '/quiz-attempts/' . $hist[4]['attempt_id'])['json']['data'];
check('S15 a later pass (attempt 5) does not claim the unlock: unlocked_topic null; attempt 2 still reports it',
    $late['passed'] === true && $late['unlocked_topic'] === null && $e->req('GET', "/quiz-attempts/$a2")['json']['data']['unlocked_topic']['id'] === 2);

// topic 2 first completion: pays again (new topic), unlocks topic 3 which is NOT ready
[$b1, $sb] = takeQuiz($e, $db, 2, 3, 'direct');
$RB = $sb['json']['data'] ?? [];
$t3 = topicRow($e, 3);
check('S16 topic 2 pass: reward 10 (balance_after 20); unlocks topic 3 with content_ready=false; still E (2 topics passed)',
    $RB['passed'] === true && $RB['reward']['balance_after'] === 20 && $RB['unlocked_topic']['id'] === 3 && $RB['unlocked_topic']['content_ready'] === false
    && $RB['promotion'] === null && $t3['unlocked'] === true && $t3['content_ready'] === false, json_encode($RB['unlocked_topic'] ?? null));
$l3 = $e->req('GET', '/topics/3/lesson');
$q3 = startQuiz($e, 3);
check('S17 unlocking does not make content ready: topic 3 lesson/quiz -> 409 topic_content_not_available',
    $l3['json']['error']['code'] === 'topic_content_not_available' && $q3['json']['error']['code'] === 'topic_content_not_available', code($l3) . ' | ' . code($q3));
$old = $e->req('GET', "/quiz-attempts/$a1")['json']['data'];
check('S18 stored first-submission facts unchanged later: attempt 1 still shows balance_after 10 (current balance 20)',
    $old['reward']['balance_after'] === 10 && $old['passed'] === false && $old['correct_count'] === 2);

// ---------------------------------------------------------------------------
// K: ledger, rank history, dashboard consistency
// ---------------------------------------------------------------------------
$co = $e->req('GET', '/me/coins');
$ent = $co['json']['data']['entries'] ?? [];
check('K1 GET /me/coins: balance 20, newest first, topic_reward credits for topics 2 then 1',
    $co['status'] === 200 && $co['json']['data']['balance'] === 20 && count($ent) === 2 && array_column($ent, 'topic_id') === [2, 1]
    && array_column($ent, 'balance_after') === [20, 10] && array_unique(array_column($ent, 'type')) === ['topic_reward']
    && array_unique(array_column($ent, 'direction')) === ['credit'] && array_unique(array_column($ent, 'amount')) === [10], code($co));
$p1 = $e->req('GET', '/me/coins?limit=1')['json']['data']['entries'] ?? [];
$p2 = $e->req('GET', '/me/coins?limit=1&before_id=' . ($p1[0]['id'] ?? 0))['json']['data']['entries'] ?? [];
$bad = [$e->req('GET', '/me/coins?limit=0')['status'], $e->req('GET', '/me/coins?limit=101')['status'], $e->req('GET', '/me/coins?limit=x')['status'],
        $e->req('GET', '/me/coins?before_id=-1')['status']];
check('K2 coin pagination: limit=1 then before_id -> next entry; invalid limit/before_id -> 400',
    count($p1) === 1 && count($p2) === 1 && $p1[0]['topic_id'] === 2 && $p2[0]['topic_id'] === 1 && $bad === [400, 400, 400, 400], implode(',', $bad));
$rh = $e->req('GET', '/me/rank-history')['json']['data'] ?? [];
check('K3 GET /me/rank-history: only the placement entry {at, reason, from:null, to:E}',
    count($rh) === 1 && array_keys($rh[0]) === ['at', 'reason', 'from', 'to'] && $rh[0]['reason'] === 'placement' && $rh[0]['to'] === 'E');
$d = $e->req('GET', '/me/dashboard')['json']['data'];
check('K4 dashboard: coins 20, topics_passed 2, rank E, next rank D needs 1 more, current topic 3',
    $d['coins'] === 20 && $d['topics_passed'] === 2 && $d['hunter_rank']['code'] === 'E' && $d['next_rank']['code'] === 'D'
    && $d['next_rank']['topics_remaining'] === 1 && ($d['current_topic']['id'] ?? null) === 3, json_encode([$d['coins'], $d['topics_passed'], $d['next_rank'], $d['current_topic']]));

// S-placed student: placement floor, no unlock when already unlocked by placement
[$sa, $ss] = takeQuiz($s, $db, 1, 5);
$RS = $ss['json']['data'] ?? [];
$ds = $s->req('GET', '/me/dashboard')['json']['data'];
check('K5 placed S passes topic 1: reward 10, no promotion, rank stays S, unlocked_topic null (2 was unlocked by placement)',
    $RS['passed'] === true && $RS['reward']['coins'] === 10 && $RS['promotion'] === null && $RS['unlocked_topic'] === null
    && $ds['hunter_rank']['code'] === 'S' && $ds['next_rank'] === null && count($s->req('GET', '/me/rank-history')['json']['data']) === 1);

// ---------------------------------------------------------------------------
// C: concurrency (InnoDB serves waiting lock requests in arrival order)
// ---------------------------------------------------------------------------
$cs = newStudent('cs');
$csid = userId($cs);
place($cs, $db, []);
$release = holdProfileLock($db, $csid);
$st3 = orderedConcurrent([
    [$cs, $cs->asyncHandle('POST', '/topics/1/quiz-attempts', ['started_via' => 'direct'])],
    [$cs, $cs->asyncHandle('POST', '/topics/1/quiz-attempts', ['started_via' => 'lesson_first'])],
    [$cs, $cs->asyncHandle('POST', '/topics/1/quiz-attempts', ['started_via' => 'direct'])],
], $release);
$ids = array_map(static fn($r) => $r['json']['data']['attempt_id'] ?? null, $st3);
$open = (int)q($db, "SELECT COUNT(*) n FROM attempts WHERE user_id = ? AND kind = 'topic_quiz'", [$csid])['n'];
check('C1 3 concurrent starts -> 201, 200, 200 with one attempt id; exactly one attempt row',
    array_column($st3, 'status') === [201, 200, 200] && count(array_unique($ids)) === 1 && $ids[0] !== null && $open === 1,
    implode(',', array_column($st3, 'status')));
$ca = (int)$ids[0];
answerQuiz($cs, $db, $ca, 3);
$release = holdProfileLock($db, $csid);
$sx = orderedConcurrent([
    [$cs, $cs->asyncHandle('POST', "/quiz-attempts/$ca/submit")],
    [$cs, $cs->asyncHandle('POST', "/quiz-attempts/$ca/submit")],
    [$cs, $cs->asyncHandle('POST', "/quiz-attempts/$ca/submit")],
], $release);
$cc = counts($db, $csid);
check('C2 3 concurrent submits -> all 200; first already_submitted=false, others true; 1 result, 1 claim, 1 ledger row, balance 10',
    array_column($sx, 'status') === [200, 200, 200] && array_map(static fn($r) => $r['json']['data']['already_submitted'], $sx) === [false, true, true]
    && (int)$cc['results'] === 1 && (int)$cc['claims'] === 1 && (int)$cc['ledger'] === 1 && (int)$cc['balance'] === 10
    && $sx[1]['json']['data']['reward'] === $sx[0]['json']['data']['reward'], json_encode($cc));

// answer save vs submit, both orders (topic 1 retries of the same student)
$o = startQuiz($cs, 1)['json']['data']['attempt_id'];
$ko = answerQuiz($cs, $db, $o, 2);                                       // 2 right; position 3 wrong
$release = holdProfileLock($db, $csid);
[$ans, $subm] = orderedConcurrent([
    [$cs, $cs->asyncHandle('PUT', "/quiz-attempts/$o/answers/3", ['choice_id' => $ko[3]['right']])],
    [$cs, $cs->asyncHandle('POST', "/quiz-attempts/$o/submit")],
], $release);
$rv3 = array_values(array_filter($subm['json']['data']['review'] ?? [], static fn($i) => $i['position'] === 3))[0] ?? [];
check('C3 answer then submit (queued together): answer 200; submit grades WITH the new answer (3/5, passed)',
    $ans['status'] === 200 && $subm['status'] === 200 && $subm['json']['data']['correct_count'] === 3
    && ($rv3['selected_choice_id'] ?? 0) === $ko[3]['right'], code($ans) . ' | ' . code($subm));
$o2 = startQuiz($cs, 1)['json']['data']['attempt_id'];
$ko2 = answerQuiz($cs, $db, $o2, 2);
$release = holdProfileLock($db, $csid);
[$subm2, $ans2] = orderedConcurrent([
    [$cs, $cs->asyncHandle('POST', "/quiz-attempts/$o2/submit")],
    [$cs, $cs->asyncHandle('PUT', "/quiz-attempts/$o2/answers/3", ['choice_id' => $ko2[3]['right']])],
], $release);
$sel = (int)q($db, 'SELECT selected_choice_id s FROM attempt_questions WHERE attempt_id = ? AND position = 3', [$o2])['s'];
check('C4 submit then answer: submit 200 (2/5); answer -> 409 attempt_finalized; stored answer unchanged',
    $subm2['status'] === 200 && $subm2['json']['data']['correct_count'] === 2 && $ans2['status'] === 409
    && $ans2['json']['error']['code'] === 'attempt_finalized' && $sel === $ko2[3]['wrong'], code($subm2) . ' | ' . code($ans2));

// concurrent first-completion rewards for two different topics (S-placed: topics 1 and 2 open)
$w = newStudent('w');
$wid = userId($w);
place($w, $db, [1, 2, 3, 4, 5, 6]);
$wa = startQuiz($w, 1)['json']['data']['attempt_id'];
$wb = startQuiz($w, 2)['json']['data']['attempt_id'];
answerQuiz($w, $db, $wa, 3);
answerQuiz($w, $db, $wb, 1);
$release = holdProfileLock($db, $wid);
[$ra, $rb] = orderedConcurrent([
    [$w, $w->asyncHandle('POST', "/quiz-attempts/$wa/submit")],
    [$w, $w->asyncHandle('POST', "/quiz-attempts/$wb/submit")],
], $release);
$wc = counts($db, $wid);
$chain = array_map('intval', array_column(qa($db, 'SELECT balance_after FROM coin_ledger WHERE user_id = ? ORDER BY id', [$wid]), 'balance_after'));
check('C5 concurrent submits for topics 1 and 2: both rewarded (balance_after 10 then 20); balance 20; 2 claims, 2 ledger rows',
    $ra['status'] === 200 && $rb['status'] === 200 && $ra['json']['data']['reward']['balance_after'] === 10
    && $rb['json']['data']['reward']['balance_after'] === 20 && (int)$wc['balance'] === 20 && (int)$wc['claims'] === 2
    && (int)$wc['ledger'] === 2 && $chain === [10, 20], json_encode($wc));

// ---------------------------------------------------------------------------
// I: integrity over every account touched by this run
// ---------------------------------------------------------------------------
$ids = array_map('intval', array_column(qa($db, 'SELECT id FROM users WHERE email LIKE ?', ["m3.$RUN.%"]), 'id'));
$in = implode(',', $ids);
$wallet = qa($db, "SELECT user_id FROM v_wallet_reconciliation WHERE user_id IN ($in)
                     AND NOT (cached_balance = ledger_sum AND cached_balance = last_balance_after)");
$claimsWithoutLedger = qa($db, "SELECT c.id FROM reward_claims c LEFT JOIN coin_ledger l ON l.reward_claim_id = c.id WHERE c.user_id IN ($in) AND l.id IS NULL");
$dupClaims = qa($db, "SELECT user_id, topic_id FROM reward_claims WHERE user_id IN ($in) GROUP BY user_id, topic_id HAVING COUNT(*) > 1");
$noResult = qa($db, "SELECT a.id FROM attempts a LEFT JOIN quiz_results r ON r.attempt_id = a.id
                      WHERE a.user_id IN ($in) AND a.kind = 'topic_quiz' AND a.status = 'submitted' AND r.attempt_id IS NULL");
$claimFirst = qa($db, "SELECT c.id FROM reward_claims c JOIN attempts a ON a.id = c.attempt_id
                        WHERE c.user_id IN ($in) AND EXISTS (SELECT 1 FROM attempts b WHERE b.user_id = a.user_id AND b.kind = 'topic_quiz'
                              AND b.topic_id = a.topic_id AND b.status = 'submitted' AND b.submitted_at < a.submitted_at)");
$rankMatch = qa($db, "SELECT p.user_id FROM student_profiles p WHERE p.user_id IN ($in) AND p.current_rank_id IS NOT NULL
                       AND p.current_rank_id <> (SELECT h.to_rank_id FROM rank_history h WHERE h.user_id = p.user_id ORDER BY h.id DESC LIMIT 1)");
$dashOk = true;
foreach ([[$e, $eid], [$s, $sid], [$cs, $csid], [$w, $wid]] as [$cl, $uid]) {
    $dd = $cl->req('GET', '/me/dashboard')['json']['data'];
    $cn = $cl->req('GET', '/me/coins')['json']['data'];
    $tp = (int)q($db, 'SELECT topics_passed n FROM v_student_topics_passed WHERE user_id = ?', [$uid])['n'];
    $dashOk = $dashOk && $dd['coins'] === $cn['balance'] && $cn['balance'] === (int)counts($db, $uid)['balance'] && $dd['topics_passed'] === $tp;
}
check('I1 wallet cache = ledger sum = last balance_after; every claim has its ledger credit; no duplicate claims',
    $wallet === [] && $claimsWithoutLedger === [] && $dupClaims === [], count($ids) . ' accounts');
check('I2 every submitted quiz has a result; each claim is on the FIRST completed attempt; rank = latest history entry',
    $noResult === [] && $claimFirst === [] && $rankMatch === []);
check('I3 dashboard coins = /me/coins balance = DB balance; dashboard topics_passed = v_student_topics_passed (4 students)', $dashOk);

$app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'), $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$denied = [];
foreach ([
    'UPDATE coin_ledger' => 'UPDATE coin_ledger SET amount = 1 WHERE id = 0',
    'DELETE reward_claims' => 'DELETE FROM reward_claims WHERE id = 0',
    'UPDATE quiz_results' => 'UPDATE quiz_results SET passed = 1 WHERE attempt_id = 0',
    'UPDATE student_profiles.coin_balance' => 'UPDATE student_profiles SET coin_balance = 1 WHERE user_id = 0',
    'DELETE lessons' => 'DELETE FROM lessons WHERE id = 0',                      // lessons editable since M5, never deletable
    'UPDATE topics.title' => "UPDATE topics SET title = 'x' WHERE id = 0",       // approved curriculum titles
    'UPDATE question_choices.question_id' => 'UPDATE question_choices SET question_id = 1 WHERE id = 0',
    'DELETE student_topic_progress' => 'DELETE FROM student_topic_progress WHERE user_id = 0',
    'UPDATE reward_policies' => 'UPDATE reward_policies SET coin_amount = 99 WHERE id = 0',
    'DELETE badge_ownership' => 'DELETE FROM badge_ownership WHERE id = 0',   // readable/insertable since M4; never deletable
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
$adm = $e->req('GET', '/admin/students')['status'];
check('P1 hunter_app refused 10 operations outside its grants (ledger/claim/result/ownership rewrites, content publishing); student -> 403 on admin',
    $denied === [] && $adm === 403 && \Hunter\Tests\expectedIntegrationAccount($config), $denied === [] ? 'all refused (1142/1143)' : implode('; ', $denied));

// ---------------------------------------------------------------------------
$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
printf("\n%d checks, %d passed, %d failed. Test accounts created in this run: %d (m3.%s.*@example.invalid)\n",
    count($results), count($results) - $failed, $failed, count($ids), $RUN);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
