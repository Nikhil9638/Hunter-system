<?php
/** Dedicated placeholder regression profile; preserves the reviewed full-course fixture. */
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use Hunter\Support\Console;
use Hunter\Support\Config;
use Hunter\Support\Paths;
Console::requireCli();
$base=realpath(sys_get_temp_dir().'/HunterSystem-M7-Verification');
if(!$base||basename($base)!=='HunterSystem-M7-Verification'||!is_readable($base.'/complete/hunter.env'))throw new RuntimeException('Existing SID-protected fixture root required; no original private-folder changes.');
$existing=Config::load($base.'/complete/hunter.env');
if($existing->get('DB_NAME')!=='hunter_system_m3_fixture')throw new RuntimeException('Fixture identity mismatch.');
$db='hunter_system_final_legacy_fixture';$account='hunter_final_legacy';
$root=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)(getenv('HUNTER_TEST_DB_ADMIN_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($root->query("SELECT schema_version FROM `$db`.schema_info WHERE id=1")->fetchColumn()!=='2026.10.14-3')throw new RuntimeException('Initialize explicit legacy-acceptance profile first.');
$dir=$base.'/legacy';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/hunter.env';
if($root->query("SELECT COUNT(*) FROM mysql.user WHERE User='$account' AND Host='127.0.0.1'")->fetchColumn()){
    if(!is_readable($file))throw new RuntimeException('Account exists without readable protected config; no rotation.');
    $config=Config::load($file);if($config->get('DB_NAME')!==$db||$config->get('DB_USER')!==$account)throw new RuntimeException('Config mismatch');
    echo "Legacy fixture account/config reused; no reset.\n";exit;
}
$password=bin2hex(random_bytes(24));$sqlAccount="'$account'@'127.0.0.1'";
$root->exec("CREATE USER $sqlAccount IDENTIFIED BY ".$root->quote($password));
$grants=str_replace(['{{DB}}','{{ACCOUNT}}'],["`$db`",$sqlAccount],file_get_contents(Paths::projectRoot().'/database/accounts/hunter_app_grants.sql'));
foreach(preg_split('/;\s*(?:\R|$)/',$grants) as $statement){$statement=trim(implode("\n",array_filter(preg_split('/\R/',$statement),static fn($line)=>trim($line)!==''&&!str_starts_with(trim($line),'--'))));if($statement==='')continue;if(!str_starts_with($statement,'GRANT '))throw new RuntimeException('Unexpected template');$root->exec($statement);}
$values=['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_NAME'=>$db,'DB_USER'=>$account,'DB_PASSWORD'=>$password,'THROTTLE_KEY'=>bin2hex(random_bytes(32)),'APP_BASE_PATH'=>'/hunter-system','APP_ORIGINS'=>'http://localhost,http://127.0.0.1','COOKIE_SECURE'=>'auto'];
$text="# TEST ONLY: placeholder regression profile, outside htdocs, inherited SID protection.\n";foreach($values as $key=>$value)$text.="$key=$value\n";file_put_contents($file,$text,LOCK_EX);
foreach(['sessions','logs'] as $sub)if(!is_dir($dir.'/'.$sub))mkdir($dir.'/'.$sub,0700,true);
echo "Restricted legacy regression account/config provisioned; original private folders and reviewed fixture untouched.\n";
