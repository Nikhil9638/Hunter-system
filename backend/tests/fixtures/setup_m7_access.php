<?php
/** Test-only private config provisioning. Never reads or changes the original private folders.
 * Creates/reuses a seed-profile test DB and reuses the existing complete M3 fixture DB.
 * Fresh restricted accounts use exactly hunter_app_grants.sql, each on ONE fixture DB.
 * Windows ACL uses the executing token's SID, not the inherited USERNAME environment.
 * php setup_m7_access.php --private-root=<outside-htdocs writable temporary directory>
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
use Hunter\Support\Console;
use Hunter\Support\Config;
use Hunter\Support\Paths;
Console::requireCli();
$options = Console::options($argv);
$base = $options['private-root'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'HunterSystem-M7-Verification';
// Do not allow a typo to apply this ACL to an existing broad directory.
if (basename($base) !== 'HunterSystem-M7-Verification'
    || strcasecmp((string)realpath(dirname($base)), (string)realpath(sys_get_temp_dir())) !== 0) {
    throw new RuntimeException('Only the dedicated HunterSystem-M7-Verification child of the current temporary directory is permitted.');
}
if (!is_dir($base) && !mkdir($base, 0700, true)) throw new RuntimeException('Cannot create test-only private root.');
$base = realpath($base);
$htdocs = realpath(dirname(Paths::projectRoot()));
if ($base === false || $htdocs === false || str_starts_with(strtolower($base . DIRECTORY_SEPARATOR), strtolower($htdocs . DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Test configuration must be outside htdocs.');
}
if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('This local provisioning script requires Windows ACLs.');
exec('whoami /user /fo csv /nh', $identity, $identityExit);
if ($identityExit !== 0 || !preg_match('/S-1-5-21-\d+-\d+-\d+-\d+/', implode('', $identity), $match)) {
    throw new RuntimeException('Cannot resolve the executing Windows identity.');
}
$sid = $match[0];
// All files are created AFTER inheritance is removed on this fresh, task-specific root.
exec('icacls ' . escapeshellarg($base) . ' /inheritance:r /grant:r '
    . escapeshellarg('*' . $sid . ':(OI)(CI)F') . ' "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" /Q 2>&1', $aclOutput, $aclExit);
if ($aclExit !== 0) throw new RuntimeException('Could not protect test-only private directory; no credentials written.');
$adminPassword = Console::readSecret('Database setup administrator password (Enter if none; never saved)');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $adminPassword,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$seedDb = 'hunter_system_m7_seed_fixture';
$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$seedDb'")->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE DATABASE `$seedDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['schema.sql', 'seed.sql'] as $file) {
        $childEnv = getenv();
        if ($adminPassword !== '') $childEnv['MYSQL_PWD'] = $adminPassword;
        $process = proc_open(['C:/xampp/mysql/bin/mysql.exe', '-uroot', '-h127.0.0.1', '--abort-source-on-error', $seedDb],
            [0=>['file', Paths::projectRoot().'/database/'.$file, 'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$childEnv);
        stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Fixture schema/seed import failed; inspect fixture only.');
        unset($childEnv, $error);
    }
}
unset($adminPassword);
foreach ([['seed',$seedDb,'hunter_m7_seed'], ['complete','hunter_system_m3_fixture','hunter_m7_fixture']] as [$folder,$database,$account]) {
    if ($pdo->query("SELECT schema_version FROM `$database`.schema_info WHERE id=1")->fetchColumn() !== '2026.10.14-3') {
        throw new RuntimeException('Unexpected fixture schema; no reset/migration will be performed.');
    }
    $dir=$base.DIRECTORY_SEPARATOR.$folder;
    if (!is_dir($dir)) mkdir($dir,0700,true);
    $file=$dir.DIRECTORY_SEPARATOR.'hunter.env';
    $userExists=(int)$pdo->query("SELECT COUNT(*) FROM mysql.user WHERE User='$account' AND Host='127.0.0.1'")->fetchColumn()>0;
    if ($userExists) {
        if (!is_readable($file)) throw new RuntimeException('Test account exists without accessible task config; refusing rotation.');
        $previous=Config::load($file);
        if ($previous->get('DB_NAME')!==$database || $previous->get('DB_USER')!==$account) throw new RuntimeException('Task config identity mismatch.');
        echo "$folder test configuration reused (no password/grant changes).\n";
        continue;
    }
    $foreign=$pdo->query("SELECT Db FROM mysql.db WHERE User='$account' UNION SELECT Db FROM mysql.tables_priv WHERE User='$account'")->fetchAll();
    if ($foreign !== []) throw new RuntimeException('Unexpected existing account grants; refusing to repoint.');
    $password=bin2hex(random_bytes(24));
    $sqlAccount="'$account'@'127.0.0.1'";
    $pdo->exec("CREATE USER $sqlAccount IDENTIFIED BY " . $pdo->quote($password));
    $grants=str_replace(['{{DB}}','{{ACCOUNT}}'],["`$database`",$sqlAccount],file_get_contents(Paths::projectRoot().'/database/accounts/hunter_app_grants.sql'));
    foreach (preg_split('/;\s*(?:\R|$)/',$grants) as $statement) {
        $statement=trim(implode("\n",array_filter(preg_split('/\R/',$statement),static fn($line)=>trim($line)!=='' && !str_starts_with(trim($line),'--'))));
        if ($statement==='') continue;
        if (!str_starts_with($statement,'GRANT ')) throw new RuntimeException('Unexpected grants template statement.');
        $pdo->exec($statement);
    }
    $values=['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_NAME'=>$database,'DB_USER'=>$account,'DB_PASSWORD'=>$password,
        'THROTTLE_KEY'=>bin2hex(random_bytes(32)),'APP_BASE_PATH'=>'/hunter-system',
        'APP_ORIGINS'=>'http://localhost,http://127.0.0.1','COOKIE_SECURE'=>'auto'];
    $text="# TEST ONLY: isolated fixture credentials; no production or root credentials.\n";
    foreach($values as $key=>$value) $text.="$key=$value\n";
    file_put_contents($file,$text,LOCK_EX);
    foreach (['sessions','logs'] as $sub) if(!is_dir($dir.DIRECTORY_SEPARATOR.$sub)) mkdir($dir.DIRECTORY_SEPARATOR.$sub,0700,true);
    unset($values,$text,$password);
    echo "$folder: $account restricted to $database; private config written (credentials hidden).\n";
}
echo "Test root protected for executing SID $sid, SYSTEM and Administrators. Original private directories unchanged.\n";
