<?php
/**
 * Build (or re-check) the ISOLATED Milestone 3 fixture database. CLI only.
 *
 *   C:\xampp\php\php.exe backend\tests\fixtures\setup_m3_fixture.php
 *
 * - Database hunter_system_m3_fixture only; it never touches hunter_system_dev.
 * - If the database is absent: CREATE DATABASE, load database/schema.sql and
 *   database/seed.sql (mysql client, aborting on the first error), then add
 *   clearly labelled "[FIXTURE]" lessons and 10 valid quiz questions for topics
 *   3-18 and mark those topics available - IN THIS DATABASE ONLY. This is test
 *   scaffolding for rank and final-topic cases, not course content.
 * - If it exists: verifies the schema version and the fixture marker and reuses it
 *   (test accounts are unique per run). Nothing is dropped or truncated.
 * - Admin password: HUNTER_TEST_DB_ADMIN_PASSWORD (empty if unset). Never printed.
 *
 * Then create its restricted account and private config (outside the web root):
 *   set HUNTER_PRIVATE_DIR=C:\xampp\hunter-system-fixture-private
 *   php backend\bin\setup-db-account.php --db=hunter_system_m3_fixture --account=hunter_fixture
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Hunter\Support\Console;
use Hunter\Support\Paths;

Console::requireCli();

define('FIXTURE_DB', in_array('--legacy-acceptance', $argv, true) ? 'hunter_system_final_legacy_fixture' : 'hunter_system_m3_fixture');
const EXPECTED_SCHEMA = '2026.10.14-3';
const MARKER = '[FIXTURE]';
const MYSQL = 'C:\\xampp\\mysql\\bin\\mysql.exe';

$pw = (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: '');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $pw,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . FIXTURE_DB . "'")->fetchColumn() > 0;
if ($exists) {
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . FIXTURE_DB . "'")->fetchColumn();
    if ($tables === 0) {
        fwrite(STDERR, FIXTURE_DB . " exists but is empty; refusing to guess. Inspect it manually.\n");
        exit(1);
    }
} else {
    $pdo->exec('CREATE DATABASE `' . FIXTURE_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach (['schema.sql', 'seed.sql'] as $file) {
        $path = Paths::projectRoot() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $file;
        $env = getenv();
        if ($pw !== '') {
            $env['MYSQL_PWD'] = $pw;                                        // not on the command line
        }
        $proc = proc_open([MYSQL, '-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', FIXTURE_DB],
            [0 => ['file', $path, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            fwrite(STDERR, "Loading $file into " . FIXTURE_DB . " failed (exit $code): $out\n");
            exit(1);
        }
        echo "Loaded $file.\n";
    }
}

$pdo->exec('USE `' . FIXTURE_DB . '`');
$version = $pdo->query('SELECT schema_version FROM schema_info WHERE id = 1')->fetchColumn();
if ($version !== EXPECTED_SCHEMA) {
    fwrite(STDERR, FIXTURE_DB . " is at schema " . var_export($version, true) . ', expected ' . EXPECTED_SCHEMA . ".\n");
    exit(1);
}

$fixtureLessons = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE title LIKE '" . MARKER . "%'")->fetchColumn();
if ($fixtureLessons === 0) {
    if ($exists) {
        fwrite(STDERR, FIXTURE_DB . " exists without fixture content; refusing to modify a database this script did not create.\n");
        exit(1);
    }
    $pdo->beginTransaction();
    $topics = $pdo->query('SELECT t.id, t.position, t.title FROM topics t LEFT JOIN lessons l ON l.topic_id = t.id
                            WHERE l.id IS NULL ORDER BY t.position')->fetchAll();
    $insLesson = $pdo->prepare("INSERT INTO lessons (topic_id, title, body, status, published_at) VALUES (?, ?, ?, 'published', UTC_TIMESTAMP(3))");
    $insQ = $pdo->prepare("INSERT INTO questions (topic_id, for_quiz, for_assessment, difficulty, stem, code_snippet, explanation, status)
                           VALUES (?, 1, 0, 'easy', ?, NULL, ?, 'active')");
    $insC = $pdo->prepare('INSERT INTO question_choices (question_id, label, choice_text, is_correct) VALUES (?, ?, ?, ?)');
    foreach ($topics as $t) {
        $insLesson->execute([$t['id'], MARKER . ' ' . $t['title'],
            MARKER . " Placeholder lesson for automated Milestone 3 tests only (topic {$t['position']}). This is not course content and exists only in " . FIXTURE_DB . '.']);
        for ($k = 1; $k <= 10; $k++) {
            $insQ->execute([$t['id'], MARKER . " Topic {$t['position']} question $k: which choice is the fixture answer?",
                MARKER . " The fixture answer for question $k is choice " . 'ABCD'[$k % 4] . '.']);
            $qid = (int)$pdo->lastInsertId();
            foreach (['A', 'B', 'C', 'D'] as $i => $label) {
                $insC->execute([$qid, $label, MARKER . " option $label", $i === $k % 4 ? 1 : 0]);
            }
        }
    }
    $pdo->exec("UPDATE topics SET status = 'available', updated_at = UTC_TIMESTAMP(3) WHERE status = 'draft'");
    $pdo->commit();
    echo 'Added fixture lessons and 10 quiz questions each for ' . count($topics) . " topics.\n";
}

$ready = $pdo->query('SELECT GROUP_CONCAT(topic_id ORDER BY position) FROM v_topic_readiness WHERE is_ready = 1')->fetchColumn();
echo FIXTURE_DB . " ready topics: $ready\n";
