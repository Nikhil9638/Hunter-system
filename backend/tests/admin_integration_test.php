<?php
/**
 * Milestone 5 integration tests against hunter_system_dev (Apache, API as hunter_app):
 * access control, reads, validation and REFUSED mutations only. Nothing here may
 * change dev content: the suite fingerprints topics, lessons, questions, choices and
 * badges before and after. Successful publishing, approval and concurrency run on
 * the isolated fixture database (admin_fixture_test.php).
 *
 *   C:\xampp\php\php.exe backend\tests\admin_integration_test.php
 *
 * Test accounts: m5.<run>.*@example.invalid (one admin via the CLI, students via the API).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;
use Hunter\Tests\Client;

Console::requireCli();
$API = 'http://localhost/hunter-system/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_m5_' . $RUN;
@mkdir($TMP);
$config = Config::load();
// Explicit isolated mode preserves the standard seed-profile assertions without
// reading production secrets or creating administrator actions in dev.
$isolated = getenv('HUNTER_M7_ISOLATED') === '1';
if ($isolated) {
    if ($config->get('DB_NAME') !== 'hunter_system_m7_seed_fixture' || $config->get('DB_USER') !== 'hunter_m7_seed') {
        fwrite(STDERR, "Refusing unexpected seed fixture config.\n"); exit(2);
    }
    $API = 'http://127.0.0.1:8097/hunter-system/api/v1';
    \Hunter\Tests\requireFixtureBackend($API, $config->get('DB_NAME'), $config->get('DB_USER'));
} elseif ($config->get('DB_NAME') !== 'hunter_system_dev' || $config->get('DB_USER') !== 'hunter_app') {
    fwrite(STDERR, "Use HUNTER_M7_ISOLATED=1 for the explicit seed fixture; refusing target mismatch.\n"); exit(2);
}
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$PW = 'admin suite password';
$PREFIX = 'm5';
$FORBIDDEN_KEYS = [];

require __DIR__ . '/QuizSupport.php';

function makeAdmin(string $tag): Client
{
    global $API, $TMP, $RUN, $PREFIX;
    $email = "$PREFIX.$RUN.$tag@example.invalid";
    $pw = 'Admin test password ' . bin2hex(random_bytes(4));
    $proc = proc_open([PHP_BINARY, Paths::projectRoot() . '/backend/bin/create-admin.php', "--email=$email", '--display-name=M5 ' . $tag],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], "$pw\n$pw\n");
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    proc_close($proc);
    $c = new Client($API, $TMP, $tag);
    $r = $c->req('POST', '/auth/login', ['email' => $email, 'password' => $pw]);
    if ($r['status'] !== 200) {
        throw new RuntimeException("admin login failed: " . code($r));
    }
    return $c;
}
function fingerprint(PDO $db): string
{
    $parts = [];
    foreach (['SELECT id, position, slug, title, summary, skill_group_id, status, updated_at FROM topics ORDER BY id',
              'SELECT id, topic_id, title, MD5(body) b, status, revision, published_at, updated_at FROM lessons ORDER BY id',
              'SELECT id, topic_id, for_quiz, for_assessment, difficulty, MD5(stem) s, MD5(COALESCE(explanation, \'\')) e, status, revision, updated_at FROM questions ORDER BY id',
              'SELECT id, question_id, label, MD5(choice_text) t, is_correct FROM question_choices ORDER BY id',
              'SELECT id, slug, name, image_ref, price_coins, price_status, status, updated_at FROM badges ORDER BY id'] as $sql) {
        $parts[] = md5(json_encode($db->query($sql)->fetchAll()));
    }
    return implode('-', $parts);
}
function fields(array $r): array
{
    return $r['json']['error']['details']['fields'] ?? [];
}

echo "Hunter System milestone 5 integration tests (API $API, DB " . $config->get('DB_NAME') . ")  run=$RUN\n\n";
$fp0 = fingerprint($db);
$audit0 = (int)q($db, 'SELECT COUNT(*) n FROM audit_log')['n'];
$visits0 = (int)q($db, 'SELECT COALESCE(SUM(lesson_visit_count), 0) n FROM student_topic_progress')['n'];

$adm = makeAdmin('admin');
$stu = newStudent('stu');
$stuId = userId($stu);
place($stu, $db, []);
$anon = new Client($API, $TMP, 'anon');

// ---- access control on every admin endpoint
$reads = ['/admin/topics', '/admin/topics/1/lesson', '/admin/questions', '/admin/questions/1', '/admin/badges', '/admin/audit-log',
          "/admin/students/$stuId", "/admin/students/$stuId/logins", '/admin/login-events'];
$writes = [['PATCH', '/admin/topics/1', ['expected_version' => 'x', 'summary' => 'x']], ['PUT', '/admin/topics/1/lesson', ['expected_revision' => 1, 'title' => 'x', 'body_markdown' => 'x']],
           ['POST', '/admin/topics/1/lesson/publish', ['expected_revision' => 1]], ['POST', '/admin/topics/1/lesson/unpublish', ['expected_revision' => 1]],
           ['POST', '/admin/topics/1/lesson/retire', ['expected_revision' => 1]], ['POST', '/admin/questions', ['topic_id' => 1]],
           ['PUT', '/admin/questions/1', ['expected_revision' => 1]], ['POST', '/admin/questions/1/activate', ['expected_revision' => 1]],
           ['POST', '/admin/questions/1/retire', ['expected_revision' => 1]], ['POST', '/admin/badges', ['slug' => 'x']],
           ['PUT', '/admin/badges/1', ['expected_version' => 'x', 'name' => 'x']], ['POST', '/admin/badges/1/approve-price', ['expected_version' => 'x', 'price' => 20]],
           ['POST', '/admin/badges/1/status', ['expected_version' => 'x', 'status' => 'on_sale']]];
$bad = [];
foreach ($reads as $u) {
    if (($s = $stu->req('GET', $u)['status']) !== 403) $bad[] = "student GET $u=$s";
    if (($s = $anon->req('GET', $u)['status']) !== 401) $bad[] = "anon GET $u=$s";
}
foreach ($writes as [$m, $u, $b]) {
    if (($s = $stu->req($m, $u, $b)['status']) !== 403) $bad[] = "student $m $u=$s";
    if (($s = $anon->req($m, $u, $b)['status']) !== 401) $bad[] = "anon $m $u=$s";
    $x = $adm->req($m, $u, $b, [], false);
    if ($x['status'] !== 403 || ($x['json']['error']['code'] ?? '') !== 'csrf_failed') $bad[] = "admin no-CSRF $m $u=" . code($x);
}
check('A1 9 admin reads: student 403, anonymous 401; 13 admin mutations: student 403, anonymous 401, admin without CSRF 403 csrf_failed',
    $bad === [], implode('; ', array_slice($bad, 0, 6)));

// ---- reads
$tp = $adm->req('GET', '/admin/topics')['json']['data'] ?? [];
$t1 = $tp[0] ?? [];
$labelledDraftLessons = $isolated ? array_map('intval', array_column($db->query("SELECT topic_id FROM lessons WHERE title LIKE '[FIXTURE]%' AND status IN ('draft','retired')")->fetchAll(), 'topic_id')) : [];
$draftsValid = count(array_filter(array_slice($tp, 2), static fn($t) => $t['status'] === 'draft' && !$t['is_ready']
    && ($t['lesson'] === null || in_array($t['id'], $labelledDraftLessons, true)))) === 16;
check('R1 GET /admin/topics: 18 in curriculum order; topics 1-2 ready; 3-18 draft/not ready (isolated mode permits labelled draft fixture lessons)',
    count($tp) === 18 && array_column($tp, 'position') === range(1, 18)
    && array_keys($t1) === ['id', 'position', 'slug', 'title', 'summary', 'skill_group', 'status', 'version', 'lesson', 'lesson_published', 'quiz_pool_size',
        'required_pool_size', 'content_complete', 'is_ready', 'students_unlocked']
    && $t1['is_ready'] && $t1['content_complete'] && $tp[1]['is_ready'] && $t1['required_pool_size'] === 10
    && $draftsValid);
$l1 = $adm->req('GET', '/admin/topics/1/lesson');
$l3 = $adm->req('GET', '/admin/topics/3/lesson');
check('R2 admin lesson read: topic 1 -> lesson with status/revision; topic 3 (none) -> 404; no student visit recorded',
    $l1['status'] === 200 && $l1['json']['data']['status'] === 'published' && is_int($l1['json']['data']['revision']) && $l3['status'] === 404
    && (int)q($db, 'SELECT COALESCE(SUM(lesson_visit_count), 0) n FROM student_topic_progress')['n'] === $visits0, code($l1) . ' | ' . code($l3));
$ql = $adm->req('GET', '/admin/questions?topic_id=1&usage=quiz&status=active&per_page=5&page=2')['json']['data'] ?? [];
$q0 = $ql['questions'][0] ?? [];
check('R3 GET /admin/questions (topic 1, quiz, active, page 2 of 5): total 12, 5 rows, correct choice flags visible to admins',
    $ql['total'] === 12 && count($ql['questions']) === 5 && $ql['page'] === 2 && $q0['valid'] === true
    && count(array_filter($q0['choices'], static fn($c) => $c['is_correct'])) === 1 && is_int($q0['used_in_attempts']));
$badQ = [];
foreach (['topic_id=0', 'topic_id=x', 'usage=both', 'status=deleted', 'page=0', 'per_page=101', 'per_page=0', 'page=100001'] as $qs) {
    if (($s = $adm->req('GET', "/admin/questions?$qs")['status']) !== 400) $badQ[] = "$qs=$s";
}
check('R4 question list filter/pagination validation: 8 invalid inputs -> 400', $badQ === [], implode(', ', $badQ));
$bd = $adm->req('GET', '/admin/badges')['json']['data'] ?? [];
check('R5 GET /admin/badges: the 4 samples, draft, provisional, no approval, image_ref/image_url null (placeholder), version present',
    array_column($bd, 'id') === [1, 2, 3, 4] && array_unique(array_column($bd, 'status')) === ['draft'] && array_unique(array_column($bd, 'price_status')) === ['provisional']
    && array_unique(array_map(static fn($b) => json_encode([$b['price_approval'], $b['image_ref'], $b['image_url']]), $bd)) === ['[null,null,null]']
    && is_string($bd[0]['version']));

// ---- refused mutations (nothing may be written)
$v1 = $bd[0]['version'];
$r = [
    'badge on_sale without approval' => [$adm->req('POST', '/admin/badges/1/status', ['expected_version' => $v1, 'status' => 'on_sale']), 409, 'price_not_approved'],
    'badge stale version' => [$adm->req('POST', '/admin/badges/1/approve-price', ['expected_version' => '2000-01-01T00:00:00.000Z', 'price' => 20]), 409, 'edit_conflict'],
    'badge approve wrong price' => [$adm->req('POST', '/admin/badges/1/approve-price', ['expected_version' => $v1, 'price' => 21]), 409, 'price_mismatch'],
    'badge client price_status' => [$adm->req('POST', '/admin/badges', ['slug' => 'x-y', 'name' => 'x', 'description' => 'x', 'image_ref' => null, 'price' => 5, 'price_status' => 'approved']), 400, 'validation_failed'],
    'badge duplicate slug' => [$adm->req('POST', '/admin/badges', ['slug' => 'syntax-scout', 'name' => 'x', 'description' => 'x', 'image_ref' => null, 'price' => 5]), 409, 'slug_taken'],
    'topic 3 available (not ready)' => [$adm->req('PATCH', '/admin/topics/3', ['expected_version' => $tp[2]['version'], 'status' => 'available']), 409, 'topic_not_ready'],
    'topic retired' => [$adm->req('PATCH', '/admin/topics/3', ['expected_version' => $tp[2]['version'], 'status' => 'retired']), 400, 'validation_failed'],
    'topic title' => [$adm->req('PATCH', '/admin/topics/3', ['expected_version' => $tp[2]['version'], 'title' => 'Renamed']), 400, 'validation_failed'],
    'topic stale version' => [$adm->req('PATCH', '/admin/topics/1', ['expected_version' => '2000-01-01T00:00:00.000Z', 'summary' => 'x']), 409, 'edit_conflict'],
    'lesson unpublish while available' => [$adm->req('POST', '/admin/topics/1/lesson/unpublish', ['expected_revision' => $l1['json']['data']['revision']]), 409, 'topic_in_use_set_draft_first'],
    'lesson stale revision' => [$adm->req('PUT', '/admin/topics/1/lesson', ['expected_revision' => 999, 'title' => 'x', 'body_markdown' => 'x']), 409, 'edit_conflict'],
    'lesson publish missing lesson' => [$adm->req('POST', '/admin/topics/3/lesson/publish', ['expected_revision' => 1]), 404, 'not_found'],
    'question two correct' => [$adm->req('POST', '/admin/questions', ['topic_id' => 3, 'for_quiz' => true, 'for_assessment' => false, 'difficulty' => 'easy', 'stem' => 'x',
        'code_snippet' => null, 'explanation' => null, 'choices' => [['label' => 'A', 'text' => 'a', 'is_correct' => true], ['label' => 'B', 'text' => 'b', 'is_correct' => true]]]), 400, 'validation_failed'],
    // stale token: refused before any write (the below-threshold refusal needs a 10-question topic -> fixture suite)
    'question retire stale revision' => [$adm->req('POST', '/admin/questions/' . $q0['id'] . '/retire', ['expected_revision' => $q0['revision'] + 50]), 409, 'edit_conflict'],
];
$wrong = [];
foreach ($r as $label => [$resp, $st, $c]) {
    if ($resp['status'] !== $st || ($c !== null && ($resp['json']['error']['code'] ?? '') !== $c)) {
        $wrong[] = "$label=" . code($resp);
    }
}
check('V1 14 refused mutations return their documented errors (approval, stale tokens, slug, readiness, curriculum fields, two correct)',
    $wrong === [], implode('; ', $wrong));
$img = [];
foreach (['../x.png', 'badges/../x.png', 'badges/x.php', 'badges/x.svg', 'badges/X.png', 'assets/badges/x.png', 'badges/sub/x.png', 'badges\\x.png', 'badges/x.png.php', 'https://e.x/x.png', ''] as $ref) {
    $x = $adm->req('POST', '/admin/badges', ['slug' => 'img-probe', 'name' => 'x', 'description' => 'x', 'image_ref' => $ref, 'price' => 5]);
    if ($x['status'] !== 400 || (fields($x)['image_ref'] ?? '') !== 'invalid') $img[] = "$ref=" . code($x);
}
check('V2 11 unsafe or off-path image references (traversal, .php, .svg, upper case, sub-folder, URL, backslash) -> 400 image_ref invalid',
    $img === [], implode('; ', $img));
check('V3 target content untouched by all refused mutations (fingerprint of topics, lessons, questions, choices, badges) and no audit rows written',
    fingerprint($db) === $fp0 && (int)q($db, 'SELECT COUNT(*) n FROM audit_log')['n'] === $audit0);

// ---- audit history read + validation
$al = $adm->req('GET', '/admin/audit-log?per_page=10');
$badA = [];
foreach (['entity_type=user', 'action=delete', 'entity_id=0', 'admin_id=x', 'since=2026-13-01T00:00:00Z', 'since=yesterday', 'per_page=101', 'page=0'] as $qs) {
    if (($s = $adm->req('GET', "/admin/audit-log?$qs")['status']) !== 400) $badA[] = "$qs=$s";
}
check('L1 GET /admin/audit-log -> 200 {entries, page, per_page, total}; 8 invalid filters -> 400',
    $al['status'] === 200 && array_keys($al['json']['data']) === ['entries', 'page', 'per_page', 'total'] && $badA === [], implode(', ', $badA));

// ---- student tracking
$sd = $adm->req('GET', "/admin/students/$stuId");
$S = $sd['json']['data'] ?? [];
$nf = [$adm->req('GET', '/admin/students/999999999')['status'], $adm->req('GET', '/admin/students/' . (int)q($db, "SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")['id'])['status']];
check('T1 GET /admin/students/{id}: placement, topics, rank history; no coins/hashes; unknown id or an admin id -> 404',
    $sd['status'] === 200 && $S['user_id'] === $stuId && $S['placement']['rank'] === 'E' && count($S['topics']) === 18 && $S['hunter_rank'] === 'E'
    && count($S['rank_history']) === 1 && array_intersect(\Hunter\Tests\allKeys($sd['json']), ['coins', 'coin_balance', 'password_hash', 'session_ref_hash']) === []
    && $nf === [404, 404], code($sd) . ' ' . implode(',', $nf));
$lg = $adm->req('GET', "/admin/students/$stuId/logins?per_page=5");
$G = $lg['json']['data'] ?? [];
check('T2 GET /admin/students/{id}/logins: own login events and sessions (register = 1 session), newest first, paginated',
    $lg['status'] === 200 && $G['sessions_total'] >= 1 && $G['per_page'] === 5 && isset($G['events'], $G['sessions']) && $G['sessions'][0]['end_reason'] === null, code($lg));
$since = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
$le = $adm->req('GET', "/admin/login-events?outcome=success&since=$since&per_page=3");
$badL = [];
foreach (['outcome=ok', 'since=2026-10-14', 'per_page=0', 'page=x'] as $qs) {
    if (($s = $adm->req('GET', "/admin/login-events?$qs")['status']) !== 400) $badL[] = "$qs=$s";
}
check('T3 GET /admin/login-events filtered (success, last hour, 3 per page): only successes with user identity; 4 invalid filters -> 400',
    $le['status'] === 200 && count($le['json']['data']['events']) <= 3 && $le['json']['data']['total'] >= 1
    && array_unique(array_column($le['json']['data']['events'], 'outcome')) === ['success'] && $badL === [], implode(', ', $badL));

// ---- restricted account
$app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'), $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$denied = [];
foreach ([
    'UPDATE audit_log' => "UPDATE audit_log SET action = 'update' WHERE id = 0",
    'DELETE audit_log' => 'DELETE FROM audit_log WHERE id = 0',
    'DELETE questions' => 'DELETE FROM questions WHERE id = 0',
    'DELETE lessons' => 'DELETE FROM lessons WHERE id = 0',
    'DELETE badges' => 'DELETE FROM badges WHERE id = 0',
    'INSERT topics' => "INSERT INTO topics (id, position, slug, title, summary, skill_group_id) VALUES (99, 99, 'x', 'x', 'x', 1)",
    'UPDATE topics.position' => 'UPDATE topics SET position = 1 WHERE id = 0',
    'UPDATE topics.title' => "UPDATE topics SET title = 'x' WHERE id = 0",
    'UPDATE topics.skill_group_id' => 'UPDATE topics SET skill_group_id = 1 WHERE id = 0',
    'UPDATE badges.slug' => "UPDATE badges SET slug = 'x' WHERE id = 0",
    'UPDATE question_choices.label' => "UPDATE question_choices SET label = 'A' WHERE id = 0",
    'UPDATE attempt_questions.stem' => "UPDATE attempt_questions SET stem = 'x' WHERE id = 0",
    'UPDATE attempt_question_choices' => 'UPDATE attempt_question_choices SET is_correct = 1 WHERE id = 0',
    'UPDATE quiz_grading_policies' => 'UPDATE quiz_grading_policies SET pass_mark_correct = 1 WHERE id = 0',
    'UPDATE users.role' => "UPDATE users SET role = 'admin' WHERE id = 0",
    'UPDATE users.status' => "UPDATE users SET status = 'deactivated' WHERE id = 0",
    'INSERT coin_ledger adjustment' => null,
    'UPDATE rank_history' => 'UPDATE rank_history SET to_rank_id = 6 WHERE id = 0',
    'ALTER TABLE' => 'ALTER TABLE badges ADD COLUMN x INT',
    'DROP TABLE' => 'DROP TABLE audit_log',
] as $label => $sql) {
    if ($sql === null) {
        continue;
    }
    try {
        $app->exec($sql);
        $denied[] = "$label ALLOWED";
    } catch (PDOException $ex) {
        if (!in_array((int)($ex->errorInfo[1] ?? 0), [1142, 1143], true)) {
            $denied[] = "$label errno " . ($ex->errorInfo[1] ?? '?');
        }
    }
}
$fileGrants = preg_match_all('/^GRANT /m', (string)file_get_contents(Paths::projectRoot() . '/database/accounts/hunter_app_grants.sql'));
$testedAccount = $config->get('DB_USER');
$global = array_filter($db->query("SHOW GRANTS FOR '$testedAccount'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN),
    static fn(string $g) => str_contains($g, ' ON *.* ') && !str_starts_with($g, 'GRANT USAGE'));
check("P1 $testedAccount refused 19 operations (audit rewrite/delete, deleting content, curriculum fields, slug, snapshots, policies, roles, history, DDL); $fileGrants grants, no global privileges",
    $denied === [] && $global === [], $denied === [] ? 'all refused (1142/1143)' : implode('; ', $denied));
$trig = [];
foreach (['UPDATE audit_log SET action = action WHERE id = (SELECT MIN(id) FROM (SELECT id FROM audit_log) x)', 'DELETE FROM audit_log ORDER BY id LIMIT 1'] as $sql) {
    if ((int)q($db, 'SELECT COUNT(*) n FROM audit_log')['n'] === 0) {
        $trig[] = 'skipped (no audit rows yet)';
        break;
    }
    try {
        $db->exec($sql);
        $trig[] = 'ALLOWED';
    } catch (PDOException $ex) {
        $trig[] = (string)($ex->errorInfo[1] ?? '?');
    }
}
check('P2 audit_log append-only even for the database admin (trigger 1644 on update and delete), or no rows exist yet in dev',
    $trig === ['1644', '1644'] || $trig === ['skipped (no audit rows yet)'], implode(',', $trig));

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
$n = (int)q($db, 'SELECT COUNT(*) n FROM users WHERE email LIKE ?', ["m5.$RUN.%"])['n'];
printf("\n%d checks, %d passed, %d failed. Test accounts created in this run: %d (m5.%s.*@example.invalid)\n", count($results), count($results) - $failed, $failed, $n, $RUN);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
