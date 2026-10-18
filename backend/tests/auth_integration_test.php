<?php
/**
 * Milestone 1 integration tests: live HTTP through Apache + database checks.
 *
 *   C:\xampp\php\php.exe backend\tests\auth_integration_test.php [--base=http://localhost/hunter-system]
 *
 * - The API under test uses its own configuration (restricted account hunter_app).
 * - This script uses a database ADMIN connection only to set up states that cannot
 *   be produced quickly over HTTP (backdated sessions, stale throttle rows) and to
 *   inspect results. Admin password: HUNTER_TEST_DB_ADMIN_PASSWORD (empty if unset).
 * - Test accounts use unique @example.invalid addresses. Users, sessions and login
 *   events are append-only history and remain in the database; throttle rows created
 *   by the tests are removed at the end.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__.'/TestSupport.php';

use Hunter\Support\Config;
use Hunter\Support\Console;
use Hunter\Support\Paths;

Console::requireCli();
$opt = Console::options($argv);
$BASE = rtrim($opt['base'] ?? 'http://localhost/hunter-system', '/');
$API = $BASE . '/api/v1';
$RUN = date('His') . bin2hex(random_bytes(2));
$TMP = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hunter_it_' . $RUN;
@mkdir($TMP);

$config = Config::load();
$API=\Hunter\Tests\seedFixtureApi($config,$API);
$admin = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', 'root',
    (string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$admin->exec("SET time_zone = '+00:00'");

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------
final class Client
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(string $dir, string $name)
    {
        $this->jar = $dir . DIRECTORY_SEPARATOR . $name . '.cookies';
        @unlink($this->jar);
    }

    /** @return array{status:int, headers:array<string,list<string>>, json:mixed, raw:string} */
    public function req(string $method, string $url, mixed $json = null, array $extraHeaders = [], ?string $rawBody = null, bool $useCsrf = true): array
    {
        $ch = curl_init($url);
        $headers = $extraHeaders;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($useCsrf && $this->csrf !== null && $method !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        } elseif ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['data']['csrf_token'])) {
            $this->csrf = $decoded['data']['csrf_token'];
        }
        return ['status' => $status, 'headers' => $respHeaders, 'json' => $decoded, 'raw' => $raw];
    }

    public function sessionId(): ?string
    {
        if (!is_file($this->jar)) {
            return null;
        }
        foreach (file($this->jar) as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 7 && $parts[5] === 'HSSESSID') {
                return $parts[6];
            }
        }
        return null;
    }

    public function setSessionCookie(string $id): void
    {
        global $API;
        $host=(string)parse_url($API,PHP_URL_HOST);
        file_put_contents($this->jar, "$host\tFALSE\t/hunter-system/\tFALSE\t0\tHSSESSID\t$id\n");
    }
}

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = $ok;
    printf("%-5s %-78s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
}
function code(array $r): string
{
    return $r['status'] . ' ' . ($r['json']['error']['code'] ?? (isset($r['json']['data']) ? 'data' : substr($r['raw'], 0, 60)));
}
function email(string $tag): string
{
    global $RUN;
    return "it.$RUN.$tag@example.invalid";
}
function q(PDO $pdo, string $sql, array $p = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
/** A server-side session (DB row + PHP session file), backdated; returns the cookie ID. */
function backdatedSession(PDO $pdo, int $userId, string $role, int $startedMinAgo, int $lastActivityMinAgo): array
{
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $policy = (int)q($pdo, 'SELECT id FROM app_policies WHERE is_current = 1')['id'];
    $pdo->prepare('INSERT INTO user_sessions (user_id, session_ref_hash, app_policy_id, started_at, last_activity_at)
                   VALUES (?, ?, ?, UTC_TIMESTAMP(3) - INTERVAL ? MINUTE, UTC_TIMESTAMP(3) - INTERVAL ? MINUTE)')
        ->execute([$userId, hash('sha256', $token), $policy, $startedMinAgo, $lastActivityMinAgo]);
    $rowId = (int)$pdo->lastInsertId();
    $sid = substr(strtr(base64_encode(random_bytes(36)), '+/', '-,'), 0, 48);
    $data = 'user_id|' . serialize($userId) . 'role|' . serialize($role) . 'tracking_token|' . serialize($token) . 'csrf_token|' . serialize($csrf);
    file_put_contents(Paths::sessionDir() . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return ['sid' => $sid, 'row' => $rowId, 'csrf' => $csrf];
}
function cookieAttrs(array $r): string
{
    foreach ($r['headers']['set-cookie'] ?? [] as $c) {
        if (str_starts_with($c, 'HSSESSID=') && !str_contains($c, 'deleted') && !str_contains($c, 'HSSESSID=;')) {
            return $c;
        }
    }
    return '';
}

$GOOD = 'correct horse battery staple';
echo "Hunter System milestone 1 integration tests  run=$RUN  api=$API\n\n";

// ---------------------------------------------------------------------------
// P: private files and intended routes
// ---------------------------------------------------------------------------
$anon = new Client($TMP, 'anon');
$private = ['/database/seed.sql', '/database/schema.sql', '/database/verify.sql', '/database/', '/database/migrations/',
    '/database/tests/transactions_check.php', '/database/accounts/hunter_app_grants.sql', '/docs/', '/docs/architecture.md',
    '/backend/', '/backend/src/App.php', '/backend/src/Support/Config.php', '/backend/config/hunter.env.example',
    '/backend/bin/create-admin.php', '/backend/bin/setup-db-account.php', '/backend/tests/auth_integration_test.php',
    '/backend/public/index.php', '/backend/public/', '/.htaccess', '/backend/.htaccess', '/frontend/',
    // since milestone 6 the project root is the student app's entry page (checked separately below);
    // the frontend source, dependencies, config and the build folder itself stay private
    '/frontend/src/main.jsx', '/frontend/package.json', '/frontend/dist/index.html'];
$bad = [];
foreach ($private as $p) {
    $r = $anon->req('GET', $BASE . $p);
    if (!in_array($r['status'], [403, 404], true)) {
        $bad[] = "$p=" . $r['status'];
    }
}
check('P1 ' . count($private) . ' private paths (SQL, docs, code, config, CLI, tests, listings) -> 403/404', $bad === [], $bad === [] ? 'all refused' : implode(', ', $bad));
$built = Paths::projectRoot() . '/frontend/dist/index.html';
$root = $anon->req('GET', $BASE . '/');
check('P1b project root: the built student app page when a build exists (exact frontend/dist/index.html, no listing), otherwise 404',
    is_file($built) ? ($root['status'] === 200 && $root['raw'] === file_get_contents($built)) : $root['status'] === 404, 'status ' . $root['status']);
$r = $anon->req('GET', 'http://localhost/hunter-system-private/hunter.env');
check('P2 private config folder is outside the web root', in_array($r['status'], [403, 404], true), 'HTTP ' . $r['status']);
$r = $anon->req('GET', "$API/health");
check('P3 intended API route works: GET /health', $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'ok',
    code($r) . ' ' . json_encode($r['json']['data']['checks'] ?? null));
$hdr = getenv('HUNTER_M7_ISOLATED')==='1' ? $anon->req('GET',$BASE.'/api/v1/health')['headers'] : $r['headers'];
check('P4 security headers present (nosniff, frame DENY, CSP, no-store)',
    ($hdr['x-content-type-options'][0] ?? '') === 'nosniff' && ($hdr['x-frame-options'][0] ?? '') === 'DENY'
    && str_contains($hdr['content-security-policy'][0] ?? '', "frame-ancestors 'none'") && ($hdr['cache-control'][0] ?? '') === 'no-store');

// ---------------------------------------------------------------------------
// R: registration
// ---------------------------------------------------------------------------
$s1 = new Client($TMP, 's1');
$e1 = email('s1');
$r = $s1->req('POST', "$API/auth/register", ['email' => strtoupper($e1), 'display_name' => '  Test Student One ', 'password' => $GOOD]);
$cookie = cookieAttrs($r);
$u1 = (int)($r['json']['data']['user']['id'] ?? 0);
check('R1 register -> 201, role student, email lower-cased, name trimmed',
    $r['status'] === 201 && ($r['json']['data']['user']['role'] ?? '') === 'student'
    && ($r['json']['data']['user']['email'] ?? '') === $e1 && ($r['json']['data']['user']['display_name'] ?? '') === 'Test Student One',
    code($r) . " user=$u1");
check('R2 session cookie: HttpOnly, SameSite=Strict, Path=/hunter-system/, not Secure on http',
    stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=strict') !== false
    && stripos($cookie, 'path=/hunter-system/') !== false && stripos($cookie, 'secure') === false, $cookie === '' ? 'no cookie' : 'ok');
// Same endpoint over XAMPP's local HTTPS (self-signed certificate: verification off for this local check only)
$ch = curl_init(str_replace('http://', 'https://', $API) . '/auth/register');
$httpsCookie = '';
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: https://localhost'],
    CURLOPT_POSTFIELDS => json_encode(['email' => email('https'), 'display_name' => 'Https', 'password' => $GOOD]),
    CURLOPT_HEADERFUNCTION => static function ($c, string $l) use (&$httpsCookie): int {
        if (stripos($l, 'set-cookie: HSSESSID=') === 0) { $httpsCookie = $l; }
        return strlen($l);
    }]);
curl_exec($ch);
$hs = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
check('R2b over HTTPS the session cookie is Secure (COOKIE_SECURE=auto); https Origin allowed',
    $hs === 201 && stripos($httpsCookie, '; secure') !== false && stripos($httpsCookie, 'samesite=strict') !== false, "HTTP $hs");

$row = q($admin, 'SELECT u.role, u.password_hash, p.onboarding_state FROM users u JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ?', [$u1]);
check('R3 database: student + profile, Argon2id hash', ($row['role'] ?? '') === 'student' && str_starts_with($row['password_hash'] ?? '', '$argon2id$'),
    substr($row['password_hash'] ?? '', 0, 30) . '...');
$sid1 = (string)$s1->sessionId();
$sess = q($admin, "SELECT s.session_ref_hash, (SELECT COUNT(*) FROM login_events e WHERE e.session_id = s.id AND e.outcome = 'success') AS ev
                     FROM user_sessions s WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1", [$u1]);
$leak = q($admin, 'SELECT COUNT(*) AS n FROM user_sessions WHERE session_ref_hash = ? OR session_ref_hash = ?', [hash('sha256', $sid1), $sid1]);
check('R4 tracking row stores a hash of a separate token, never the PHP session ID',
    preg_match('/^[0-9a-f]{64}$/', $sess['session_ref_hash'] ?? '') === 1 && (int)$sess['ev'] === 1 && (int)$leak['n'] === 0,
    'success events=' . ($sess['ev'] ?? '?'));

$r = (new Client($TMP, 'r5'))->req('POST', "$API/auth/register", ['email' => email('r5'), 'display_name' => 'Wannabe Admin', 'password' => $GOOD, 'role' => 'admin']);
$made = q($admin, 'SELECT COUNT(*) AS n FROM users WHERE email = ?', [email('r5')]);
check('R5 browser cannot choose a role: "role" field rejected, nothing created',
    $r['status'] === 400 && ($r['json']['error']['details']['fields']['role'] ?? '') === 'unknown_field' && (int)$made['n'] === 0, code($r));
$r = (new Client($TMP, 'r6'))->req('POST', "$API/auth/register", ['email' => ' ' . strtoupper($e1), 'display_name' => 'Dup', 'password' => $GOOD]);
check('R6 duplicate email (different case/spaces) -> 409 email_taken', $r['status'] === 409 && $r['json']['error']['code'] === 'email_taken', code($r));

$invalid = [
    'bad email' => [['email' => 'not-an-email', 'display_name' => 'X', 'password' => $GOOD], 'email', 'invalid_format'],
    'missing email' => [['display_name' => 'X', 'password' => $GOOD], 'email', 'required'],
    'blank name' => [['email' => email('r7a'), 'display_name' => '   ', 'password' => $GOOD], 'display_name', 'invalid'],
    'name 81 chars' => [['email' => email('r7b'), 'display_name' => str_repeat('n', 81), 'password' => $GOOD], 'display_name', 'invalid'],
    'password 11 chars' => [['email' => email('r7c'), 'display_name' => 'X', 'password' => 'elevenchars'], 'password', 'too_short'],
    'password 257 chars' => [['email' => email('r7d'), 'display_name' => 'X', 'password' => str_repeat('p', 257)], 'password', 'too_long'],
    'password 257 x 4-byte chars' => [['email' => email('r7e'), 'display_name' => 'X', 'password' => str_repeat('😀', 257)], 'password', 'too_long'],
    'password not a string' => [['email' => email('r7f'), 'display_name' => 'X', 'password' => 123456789012], 'password', 'required'],
];
$fails = [];
foreach ($invalid as $label => [$body, $field, $expect]) {
    $r = (new Client($TMP, 'inv'))->req('POST', "$API/auth/register", $body);
    if ($r['status'] !== 400 || ($r['json']['error']['details']['fields'][$field] ?? '') !== $expect) {
        $fails[] = "$label:" . code($r);
    }
}
check('R7 invalid inputs -> 400 with field codes (' . count($invalid) . ' cases)', $fails === [], implode('; ', $fails));
$r = (new Client($TMP, 'r8'))->req('POST', "$API/auth/register", null, ['Content-Type: application/json'], '{"email": ');
$r2 = (new Client($TMP, 'r8b'))->req('POST', "$API/auth/register", null, ['Content-Type: text/plain'], '{"email":"a@b.c"}');
$r3 = (new Client($TMP, 'r8c'))->req('POST', "$API/auth/register", null, ['Content-Type: application/x-www-form-urlencoded'], 'email=a%40b.c');
check('R8 malformed JSON -> 400 invalid_json; non-JSON content types -> 415',
    $r['status'] === 400 && $r['json']['error']['code'] === 'invalid_json' && $r2['status'] === 415 && $r3['status'] === 415,
    code($r) . ' | ' . code($r2) . ' | ' . code($r3));

// ---------------------------------------------------------------------------
// L: login, wrong credentials, regeneration
// ---------------------------------------------------------------------------
$before = $s1->sessionId();
$r = $s1->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD]);
$after = $s1->sessionId();
check('L1 login (same browser) -> 200 and a NEW session ID', $r['status'] === 200 && $before !== null && $after !== null && $before !== $after, code($r));
$old = new Client($TMP, 'oldsid');
$old->setSessionCookie((string)$before);
$r = $old->req('GET', "$API/auth/me");
$prevRow = q($admin, 'SELECT end_reason FROM user_sessions WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$u1]);
check('L2 the pre-login session ID is invalid afterwards; its tracking row ended', $r['status'] === 401 && ($prevRow['end_reason'] ?? '') === 'logged_out',
    code($r) . ' previous row: ' . ($prevRow['end_reason'] ?? 'open'));

$fix = new Client($TMP, 'fixation');
$attacker = 'AttackerChosenSessionId1234567890abcdefXYZ';
$fix->setSessionCookie($attacker);
$r = $fix->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD]);
check('L3 session fixation: an attacker-chosen ID is replaced at login', $r['status'] === 200 && $fix->sessionId() !== $attacker
    && !is_file(Paths::sessionDir() . '/sess_' . $attacker), code($r));

$wrong = (new Client($TMP, 'w'))->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD . '!']);
$unknown = (new Client($TMP, 'u'))->req('POST', "$API/auth/login", ['email' => email('nobody'), 'password' => $GOOD]);
check('L4 wrong password and unknown email -> identical 401 invalid_credentials',
    $wrong['status'] === 401 && $unknown['status'] === 401 && $wrong['json'] === $unknown['json'], code($wrong) . ' | ' . code($unknown));
$ev = q($admin, "SELECT SUM(outcome = 'wrong_password' AND user_id = ?) AS w,
                        SUM(outcome = 'unknown_account' AND user_id IS NULL AND occurred_at > UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE) AS u
                   FROM login_events", [$u1]);
check('L5 failures recorded: wrong_password (user known), unknown_account (no identity)', (int)$ev['w'] >= 1 && (int)$ev['u'] >= 1,
    "wrong_password=$ev[w] unknown_account(last min)=$ev[u]");
$cols = $admin->query("SELECT GROUP_CONCAT(COLUMN_NAME) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('login_events','login_throttle')")->fetch()['c'];
check('L6 no column can hold a typed email or password in login_events / login_throttle',
    !preg_match('/email|password|ip_addr|identifier/i', $cols), $cols);

// ---------------------------------------------------------------------------
// U: Unicode normalization and long passwords (no truncation)
// ---------------------------------------------------------------------------
$nfc = "Ünïcödé pässwörd ✓ 𝔘𝔫𝔦";
$nfd = Normalizer::normalize($nfc, Normalizer::FORM_D);
$uc = new Client($TMP, 'uni');
$r = $uc->req('POST', "$API/auth/register", ['email' => email('uni'), 'display_name' => 'Ünïcode', 'password' => $nfc]);
$r2 = (new Client($TMP, 'uni2'))->req('POST', "$API/auth/login", ['email' => email('uni'), 'password' => $nfd]);
check('U1 Unicode password: registered in NFC, login with NFD bytes succeeds (NFKC)', $r['status'] === 201 && $r2['status'] === 200 && $nfc !== $nfd,
    code($r) . ' | ' . code($r2) . ' (' . strlen($nfc) . ' vs ' . strlen($nfd) . ' bytes)');
$full = 'ｃｏｍｐａｔｉｂｉｌｉｔｙ ｐａｓｓ';                                     // full-width forms
$r = (new Client($TMP, 'fw'))->req('POST', "$API/auth/register", ['email' => email('fw'), 'display_name' => 'Fullwidth', 'password' => $full]);
$r2 = (new Client($TMP, 'fw2'))->req('POST', "$API/auth/login", ['email' => email('fw'), 'password' => 'compatibility pass']);
check('U2 NFKC folds compatibility characters (full-width = ASCII)', $r['status'] === 201 && $r2['status'] === 200, code($r) . ' | ' . code($r2));

$longA = str_repeat('a', 100) . 'X';
$longB = str_repeat('a', 100) . 'Y';
$r = (new Client($TMP, 'long'))->req('POST', "$API/auth/register", ['email' => email('long'), 'display_name' => 'Long', 'password' => $longA]);
$rb = (new Client($TMP, 'long2'))->req('POST', "$API/auth/login", ['email' => email('long'), 'password' => $longB]);
$ra = (new Client($TMP, 'long3'))->req('POST', "$API/auth/login", ['email' => email('long'), 'password' => $longA]);
check('U3 no truncation: 101-char password differing only after byte 100 is rejected', $r['status'] === 201 && $rb['status'] === 401 && $ra['status'] === 200,
    'register ' . $r['status'] . ', different tail ' . $rb['status'] . ', exact ' . $ra['status']);
$max = str_repeat('😀', 256);                                                  // 256 chars = 1024 bytes
$maxB = str_repeat('😀', 255) . '😁';
$r = (new Client($TMP, 'max'))->req('POST', "$API/auth/register", ['email' => email('max'), 'display_name' => 'Max', 'password' => $max]);
$rb = (new Client($TMP, 'max2'))->req('POST', "$API/auth/login", ['email' => email('max'), 'password' => $maxB]);
$ra = (new Client($TMP, 'max3'))->req('POST', "$API/auth/login", ['email' => email('max'), 'password' => $max]);
check('U4 maximum (256 chars / 1024 bytes) accepted; last character still matters', $r['status'] === 201 && $rb['status'] === 401 && $ra['status'] === 200,
    'register ' . $r['status'] . ', last char changed ' . $rb['status'] . ', exact ' . $ra['status']);

// ---------------------------------------------------------------------------
// C: CSRF and cross-site requests
// ---------------------------------------------------------------------------
$c = new Client($TMP, 'csrf');
$c->req('POST', "$API/auth/register", ['email' => email('csrf'), 'display_name' => 'Csrf', 'password' => $GOOD]);
$r1 = $c->req('POST', "$API/auth/logout", null, [], null, false);
$r2 = $c->req('POST', "$API/auth/logout", null, ['X-CSRF-Token: ' . str_repeat('0', 64)], null, false);
$r3 = $c->req('POST', "$API/auth/logout", null, ['Origin: http://evil.example'], null, true);
$still = $c->req('GET', "$API/auth/me");
check('C1 logout without / with wrong CSRF token / from a foreign Origin -> 403; session intact',
    $r1['status'] === 403 && $r2['status'] === 403 && $r3['status'] === 403 && $still['status'] === 200,
    code($r1) . ' | ' . code($r2) . ' | ' . code($r3) . ' | me ' . $still['status']);
$r = (new Client($TMP, 'xs'))->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD], ['Origin: http://evil.example']);
check('C2 cross-site login attempt (foreign Origin) -> 403', $r['status'] === 403 && $r['json']['error']['code'] === 'csrf_failed', code($r));

// ---------------------------------------------------------------------------
// S: logout invalidation, idle expiry, activity updates
// ---------------------------------------------------------------------------
$sidBefore = (string)$c->sessionId();
$r = $c->req('POST', "$API/auth/logout");
$after = $c->req('GET', "$API/auth/me");
$replay = new Client($TMP, 'replay');
$replay->setSessionCookie($sidBefore);
$rr = $replay->req('GET', "$API/auth/me");
$row = q($admin, "SELECT end_reason, ended_at IS NOT NULL AS ended FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE u.email = ? ORDER BY s.id DESC LIMIT 1", [email('csrf')]);
check('S1 logout -> 200; row ended logged_out; PHP session deleted; replayed cookie -> 401',
    $r['status'] === 200 && $after['status'] === 401 && $rr['status'] === 401 && ($row['end_reason'] ?? '') === 'logged_out'
    && !is_file(Paths::sessionDir() . '/sess_' . $sidBefore), code($r) . ' | me ' . code($after) . ' | replay ' . code($rr));

$idle = backdatedSession($admin, $u1, 'student', 45, 31);                   // 31 min without activity
$ic = new Client($TMP, 'idle');
$ic->setSessionCookie($idle['sid']);
$r = $ic->req('GET', "$API/auth/me");
$row = q($admin, 'SELECT end_reason, TIMESTAMPDIFF(SECOND, last_activity_at, ended_at) AS gap FROM user_sessions WHERE id = ?', [$idle['row']]);
check('S2 idle 31 min -> 401 session_expired; ended_at = last activity + 30 min',
    $r['status'] === 401 && $r['json']['error']['code'] === 'session_expired' && ($row['end_reason'] ?? '') === 'expired' && (int)$row['gap'] === 1800,
    code($r) . ' gap=' . ($row['gap'] ?? '?') . 's');

$act = backdatedSession($admin, $u1, 'student', 20, 5);                     // active 5 min ago
$ac = new Client($TMP, 'act');
$ac->setSessionCookie($act['sid']);
$before = q($admin, 'SELECT last_activity_at FROM user_sessions WHERE id = ?', [$act['row']])['last_activity_at'];
$r = $ac->req('GET', "$API/auth/me");
$mid = q($admin, 'SELECT last_activity_at, TIMESTAMPDIFF(SECOND, last_activity_at, UTC_TIMESTAMP(3)) AS age FROM user_sessions WHERE id = ?', [$act['row']]);
$r2 = $ac->req('GET', "$API/auth/me");
$end = q($admin, 'SELECT last_activity_at FROM user_sessions WHERE id = ?', [$act['row']]);
check('S3 activity older than 60 s is updated; a second request within 60 s does not write',
    $r['status'] === 200 && $r2['status'] === 200 && $mid['last_activity_at'] !== $before && (int)$mid['age'] <= 5 && $end['last_activity_at'] === $mid['last_activity_at'],
    "before=$before after=" . $mid['last_activity_at'] . ' unchanged on 2nd=' . var_export($end['last_activity_at'] === $mid['last_activity_at'], true));

// ---------------------------------------------------------------------------
// A: administrator creation (CLI) and role enforcement
// ---------------------------------------------------------------------------
$r = $s1->req('GET', "$API/admin/students");
$r0 = $anon->req('GET', "$API/admin/students");
check('A1 student -> /admin/students 403 forbidden; anonymous -> 401', $r['status'] === 403 && $r0['status'] === 401, code($r) . ' | ' . code($r0));

$php = PHP_BINARY;
$cli = Paths::projectRoot() . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'create-admin.php';
function runCli(string $php, string $cli, array $args, string $stdin): array
{
    $proc = proc_open(array_merge([$php, $cli], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($proc), $out];
}
$adminEmail = email('admin');
$adminPw = 'Admin pässwörd for tests ' . bin2hex(random_bytes(4));
[$code1, $out1] = runCli($php, $cli, ["--email=$adminEmail", '--display-name=Test Admin'], "$adminPw\n$adminPw\n");
$arow = q($admin, 'SELECT role, password_hash FROM users WHERE email = ?', [$adminEmail]);
check('A2 create-admin CLI creates role=admin with Argon2id; password not printed',
    $code1 === 0 && ($arow['role'] ?? '') === 'admin' && str_starts_with($arow['password_hash'] ?? '', '$argon2id$') && !str_contains($out1, $adminPw),
    trim($out1));
[$code2, $out2] = runCli($php, $cli, ["--email=$adminEmail", '--display-name=Again'], "$adminPw\n$adminPw\n");
[$code3, $out3] = runCli($php, $cli, ['--email=' . email('admin2'), '--display-name=Mismatch'], "$adminPw\nsomething else entirely\n");
[$code4, $out4] = runCli($php, $cli, ['--email=' . email('admin3'), '--display-name=Short'], "short\nshort\n");
check('A3 CLI refuses duplicate email, mismatched repeat, too-short password',
    $code2 !== 0 && $code3 !== 0 && $code4 !== 0 && q($admin, 'SELECT COUNT(*) n FROM users WHERE email IN (?, ?)', [email('admin2'), email('admin3')])['n'] == 0,
    "exit codes $code2/$code3/$code4");
$ad = new Client($TMP, 'admin');
$r = $ad->req('POST', "$API/auth/login", ['email' => $adminEmail, 'password' => $adminPw]);
$list = $ad->req('GET', "$API/admin/students?per_page=100&query=" . urlencode("it.$RUN"));
$keys = array_keys($list['json']['data']['students'][0] ?? []);
check('A4 admin logs in and lists students (no coin fields, no hashes)',
    $r['status'] === 200 && $list['status'] === 200 && ($list['json']['data']['total'] ?? 0) >= 5
    && !array_intersect($keys, ['coins', 'coin_balance', 'password_hash', 'session_ref_hash']),
    code($list) . ' students in this run=' . ($list['json']['data']['total'] ?? '?') . ' fields=' . implode(',', $keys));
$recent = array_column($list['json']['data']['students'] ?? [], 'recently_active', 'email');
check('A5 admin list: recently_active true for a student active now', ($recent[$e1] ?? null) === true, json_encode($recent[$e1] ?? null));

// ---------------------------------------------------------------------------
// D: restricted database account
// ---------------------------------------------------------------------------
$app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4', $config->get('DB_USER'), $config->get('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$denied = [];
$attempts = [
    'DROP TABLE users' => 'DROP TABLE users',
    'ALTER TABLE users' => 'ALTER TABLE users ADD COLUMN x INT',
    'CREATE TABLE' => 'CREATE TABLE t_probe (id INT)',
    'CREATE TRIGGER' => 'CREATE TRIGGER t_probe BEFORE INSERT ON users FOR EACH ROW SET @x = 1',
    'DROP TRIGGER' => 'DROP TRIGGER trg_coin_ledger_bi',
    'DELETE users' => 'DELETE FROM users WHERE id = 0',
    'UPDATE users.role' => "UPDATE users SET role = 'admin' WHERE id = 0",
    'UPDATE users.email' => "UPDATE users SET email = 'x@y.z' WHERE id = 0",
    'UPDATE topics.position' => 'UPDATE topics SET position = 1 WHERE id = 0',   // curriculum order is never editable (content columns are, since M5)
    'DELETE user_sessions' => 'DELETE FROM user_sessions WHERE id = 0',
    'DELETE login_events' => 'DELETE FROM login_events WHERE id = 0',
    'other database' => 'SELECT * FROM hunter_system.users LIMIT 1',
    'CREATE USER' => "CREATE USER 'probe'@'127.0.0.1'",
    'GRANT' => "GRANT SELECT ON hunter_system_dev.coin_ledger TO 'hunter_app'@'127.0.0.1'",
];
foreach ($attempts as $label => $sql) {
    try {
        $app->exec($sql);
        $denied[] = "$label ALLOWED";
    } catch (PDOException $e) {
        if (!in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143, 1044, 1227, 1045, 1370, 1410], true)) {
            $denied[] = "$label errno " . ($e->errorInfo[1] ?? '?');
        }
    }
}
check('D1 app account denied: ' . count($attempts) . ' schema/admin/out-of-scope operations', $denied === [], $denied === [] ? 'all refused with privilege errors' : implode('; ', $denied));
$ok = $app->query('SELECT COUNT(*) FROM users')->fetchColumn() !== false && $app->query('SELECT COUNT(*) FROM v_admin_student_overview')->fetchColumn() !== false;
$grants = $admin->query("SHOW GRANTS FOR '".$config->get('DB_USER')."'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN);
$global = array_filter($grants, static fn(string $g): bool => str_contains($g, ' ON *.* ') && !str_starts_with($g, 'GRANT USAGE'));
check('D2 app account can read what it needs; no global privileges; ' . (count($grants) - 1) . ' table grants', $ok && $global === [] && in_array($config->get('DB_USER'),['hunter_app','hunter_m7_seed'],true),
    'API configured user = ' . $config->get('DB_USER'));

// ---------------------------------------------------------------------------
// T: throttling and retention (run last; IP lock would affect later tests)
// ---------------------------------------------------------------------------
$tc = new Client($TMP, 'thr');
$te = email('thr');
$tc->req('POST', "$API/auth/register", ['email' => $te, 'display_name' => 'Throttle', 'password' => $GOOD]);
$codes = [];
for ($i = 1; $i <= 5; $i++) {
    $codes[] = (new Client($TMP, "t$i"))->req('POST', "$API/auth/login", ['email' => $te, 'password' => "wrong password $i"])['status'];
}
$locked = (new Client($TMP, 't6'))->req('POST', "$API/auth/login", ['email' => $te, 'password' => $GOOD]);
$retry = (int)($locked['headers']['retry-after'][0] ?? 0);
check('T1 5 failures for one email -> correct password also refused 429 with Retry-After',
    $codes === [401, 401, 401, 401, 401] && $locked['status'] === 429 && $retry > 800 && $retry <= 900,
    implode(',', $codes) . ' then ' . code($locked) . " Retry-After=$retry");
$other = (new Client($TMP, 't7'))->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD]);
check('T2 a different account from the same IP is not blocked by that email lock', $other['status'] === 200, code($other));
$rows = $admin->query("SELECT key_type, key_hash, failure_count, locked_until IS NOT NULL AS locked FROM login_throttle")->fetchAll();
$emailRow = array_values(array_filter($rows, static fn($r) => $r['key_type'] === 'email' && (int)$r['locked'] === 1));
$raw = q($admin, 'SELECT COUNT(*) n FROM login_throttle WHERE key_hash LIKE ? OR key_hash LIKE ?', ['%example.invalid%', '%127.0.0.1%']);
check('T3 throttle rows hold only 64-hex keyed hashes (no raw email or IP)',
    count($emailRow) >= 1 && (int)$raw['n'] === 0 && !array_filter($rows, static fn($r) => !preg_match('/^[0-9a-f]{64}$/', $r['key_hash'])),
    count($rows) . ' rows; locked email keys=' . count($emailRow));

// retention: stale rows inserted directly
$admin->exec("INSERT INTO login_throttle (key_hash, key_type, window_start, failure_count, locked_until, created_at, updated_at) VALUES
    (SHA2('it-old-$RUN', 256), 'ip', UTC_TIMESTAMP(3) - INTERVAL 25 HOUR, 3, NULL, UTC_TIMESTAMP(3) - INTERVAL 25 HOUR, UTC_TIMESTAMP(3) - INTERVAL 25 HOUR),
    (SHA2('it-idle-$RUN', 256), 'email', UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE, 2, NULL, UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE, UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE),
    (SHA2('it-lock-$RUN', 256), 'email', UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE, 5, UTC_TIMESTAMP(3) + INTERVAL 5 MINUTE, UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE, UTC_TIMESTAMP(3) - INTERVAL 20 MINUTE),
    (SHA2('it-oldlock-$RUN', 256), 'ip', UTC_TIMESTAMP(3) - INTERVAL 25 HOUR, 9, UTC_TIMESTAMP(3) + INTERVAL 5 MINUTE, UTC_TIMESTAMP(3) - INTERVAL 25 HOUR, UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE)");
$anon->req('GET', "$API/auth/me");                                              // any API request purges
$left = $admin->query("SELECT key_hash FROM login_throttle WHERE key_hash IN (SHA2('it-old-$RUN',256), SHA2('it-idle-$RUN',256), SHA2('it-lock-$RUN',256), SHA2('it-oldlock-$RUN',256))")->fetchAll(PDO::FETCH_COLUMN);
check('T4 retention: >24 h rows (even locked) and idle unlocked rows purged; active lock kept',
    $left === [hash('sha256', "it-lock-$RUN")], count($left) . ' of 4 test rows left');

// IP throttle: unknown emails from this IP until locked
$n = 0;
$ipLocked = null;
for ($i = 1; $i <= 40; $i++) {
    $r = (new Client($TMP, 'ip'))->req('POST', "$API/auth/login", ['email' => email("ipx$i"), 'password' => $GOOD]);
    $n++;
    if ($r['status'] === 429) {
        $ipLocked = $r;
        break;
    }
}
$fresh = (new Client($TMP, 'ipf'))->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD]);
check('T5 IP throttle: repeated failures across many emails lock the IP (even a valid login)',
    $ipLocked !== null && $fresh['status'] === 429, "locked after $n attempts in this loop; valid login then " . code($fresh));

// cleanup of this run's throttle state (not history)
$deleted = $admin->exec("DELETE FROM login_throttle WHERE key_hash = SHA2('it-lock-$RUN', 256) OR updated_at > UTC_TIMESTAMP(3) - INTERVAL 1 HOUR");
$r = (new Client($TMP, 'after'))->req('POST', "$API/auth/login", ['email' => $e1, 'password' => $GOOD]);
check('T6 after removing this run\'s throttle rows, login works again', $r['status'] === 200, "throttle rows removed=$deleted; " . code($r));

// ---------------------------------------------------------------------------
$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
$users = q($admin, 'SELECT COUNT(*) n FROM users WHERE email LIKE ?', ["it.$RUN.%"])['n'];
printf("\n%d checks, %d passed, %d failed. Test accounts created in this run: %d (it.%s.*@example.invalid)\n",
    count($results), count($results) - $failed, $failed, $users, $RUN);
array_map('unlink', glob($TMP . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($TMP);
exit($failed === 0 ? 0 : 1);
