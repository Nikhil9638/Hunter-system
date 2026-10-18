<?php
/**
 * Test-only helper for the browser journeys (CLI only; frontend/ is denied by Apache).
 * Reads answer keys from attempt snapshots (the test plays a student who knows the
 * answers), prepares an idle-expired session, creates a test administrator, and -
 * in the FIXTURE database only - creates/retires labelled "[FIXTURE]" badges.
 * The target database is whatever HUNTER_PRIVATE_DIR's configuration names.
 *
 *   php db-helper.php <command> [args…]   -> prints one JSON value
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/backend/src/bootstrap.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;

Console::requireCli();
$config = Config::load();
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'),
    $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$fixtureOnly = static function () use ($config): void {
    if ($config->get('DB_NAME') !== 'hunter_system_m3_fixture') {
        fwrite(STDERR, "fixture-only command\n");
        exit(2);
    }
};
$cmd = $argv[1] ?? '';
$out = null;

switch ($cmd) {
    case 'answer-key':                       // <attemptId> -> {position: {right, wrong, group}}
        $out = [];
        $st = $db->prepare('SELECT aq.position, aq.skill_group_id, c.id, c.is_correct FROM attempt_questions aq
                              JOIN attempt_question_choices c ON c.attempt_question_id = aq.id WHERE aq.attempt_id = ? ORDER BY aq.position, c.label');
        $st->execute([(int)$argv[2]]);
        foreach ($st as $r) {
            $p = (int)$r['position'];
            $out[$p]['group'] = (int)$r['skill_group_id'];
            if ((int)$r['is_correct'] === 1) {
                $out[$p]['right'] = (int)$r['id'];
            } else {
                $out[$p]['wrong'] ??= (int)$r['id'];
            }
        }
        break;

    case 'open-assessment':                  // <email> -> attempt id
        $st = $db->prepare("SELECT a.id FROM attempts a JOIN users u ON u.id = a.user_id WHERE u.email = ? AND a.kind = 'assessment' AND a.status = 'in_progress'");
        $st->execute([$argv[2]]);
        $out = (int)$st->fetchColumn();
        break;

    case 'idle-session':                     // <email> -> new session id whose last activity was 31 minutes ago
        $st = $db->prepare("SELECT id, role FROM users WHERE email = ?");
        $st->execute([$argv[2]]);
        $u = $st->fetch();
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $policy = (int)$db->query('SELECT id FROM app_policies WHERE is_current = 1')->fetchColumn();
        $db->prepare('INSERT INTO user_sessions (user_id, session_ref_hash, app_policy_id, started_at, last_activity_at)
                      VALUES (?, ?, ?, UTC_TIMESTAMP(3) - INTERVAL 45 MINUTE, UTC_TIMESTAMP(3) - INTERVAL 31 MINUTE)')
            ->execute([(int)$u['id'], hash('sha256', $token), $policy]);
        $sid = substr(strtr(base64_encode(random_bytes(36)), '+/', '-,'), 0, 48);
        file_put_contents(Paths::sessionDir() . DIRECTORY_SEPARATOR . 'sess_' . $sid,
            'user_id|' . serialize((int)$u['id']) . 'role|' . serialize($u['role']) . 'tracking_token|' . serialize($token) . 'csrf_token|' . serialize($csrf));
        $out = $sid;
        break;

    case 'create-admin':                     // <email> <password>
        $proc = proc_open([PHP_BINARY, $root . '/backend/bin/create-admin.php', '--email=' . $argv[2], '--display-name=Browser Test Admin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $argv[3] . "\n" . $argv[3] . "\n");
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $out = proc_close($proc) === 0;
        break;

    case 'make-badges':                      // <run> -> ids (fixture only): [FIXTURE] approved, on sale
        $fixtureOnly();
        $out = [];
        foreach ([['a', 10], ['b', 20], ['c', 25], ['h', 1000]] as [$k, $price]) {
            $db->prepare("INSERT INTO badges (slug, name, description, image_ref, price_coins, price_status, status)
                          VALUES (?, ?, '[FIXTURE] Browser-test badge; not approved content.', 'placeholder/badges/fixture', ?, 'approved', 'on_sale')")
                ->execute(["fx-$argv[2]-$k", '[FIXTURE] Badge ' . strtoupper($k), $price]);
            $out[$k] = (int)$db->lastInsertId();
        }
        break;

    case 'badge-status':                     // <id> <status> (fixture only)
        $fixtureOnly();
        $db->prepare('UPDATE badges SET status = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$argv[3], (int)$argv[2]]);
        $out = true;
        break;

    case 'retire-run-badges':                // <run> (fixture only)
        $fixtureOnly();
        $db->prepare("UPDATE badges SET status = 'retired' WHERE slug LIKE ? AND status <> 'retired'")->execute(['fx-' . $argv[2] . '-%']);
        $out = true;
        break;

    case 'db-name':
        $out = $config->get('DB_NAME');
        break;

    case 'fixture-identity':
        $fixtureOnly();
        if (!in_array($config->get('DB_USER'), ['hunter_fixture', 'hunter_m7_fixture'], true)) {
            fwrite(STDERR, "fixture account required\n");
            exit(2);
        }
        $out = ['database' => $config->get('DB_NAME'), 'account' => $config->get('DB_USER')];
        break;

    case 'test-identity':
        if ($config->get('DB_NAME') !== 'hunter_system_m7_seed_fixture' || $config->get('DB_USER') !== 'hunter_m7_seed') {
            fwrite(STDERR, "seed fixture identity required\n"); exit(2);
        }
        $out = ['database' => $config->get('DB_NAME'), 'account' => $config->get('DB_USER')];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(2);
}
echo json_encode($out);
