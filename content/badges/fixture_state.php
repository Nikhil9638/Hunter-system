<?php
// Fixture-only read helper; no credentials, sessions or administrator creation.
declare(strict_types=1);
require dirname(__DIR__,2).'/backend/src/bootstrap.php';
use Hunter\Support\Config;
use Hunter\Support\Console;
Console::requireCli();$c=Config::load();
if($c->get('DB_NAME')!=='hunter_system_m3_fixture'||$c->get('DB_USER')!=='hunter_m7_fixture')throw new RuntimeException('Refusing nonfixture config.');
$db=new PDO('mysql:host=127.0.0.1;dbname=hunter_system_m3_fixture;charset=utf8mb4',$c->get('DB_USER'),$c->get('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$path=__DIR__.'/fixture-baseline.json';
if(($argv[1]??'')==='identity'){
    $email=$db->query("SELECT email FROM users WHERE role='admin' AND email LIKE 'b7.%@example.invalid' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if(!$email)throw new RuntimeException('Existing M7 fixture actor required; this task must not create an administrator.');
    echo json_encode(['database'=>$c->get('DB_NAME'),'account'=>$c->get('DB_USER'),'fixture_admin_email'=>$email]);
}elseif(($argv[1]??'')==='capture'){
    if(is_file($path))throw new RuntimeException('Baseline exists; preserve it.');$out=[];
    foreach(['badges','audit_log'] as $table){$out[$table]=[];foreach($db->query("SELECT * FROM $table ORDER BY id") as $row)$out[$table][$row['id']]=hash('sha256',json_encode($row));}
    file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT)."\n");echo "Existing fixture badges/audit baseline captured.\n";
}else{
    $count=0;foreach(json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR) as $table=>$rows){$st=$db->prepare("SELECT * FROM $table WHERE id=?");foreach($rows as $id=>$hash){$st->execute([$id]);if(hash('sha256',json_encode($st->fetch()))!==$hash)throw new RuntimeException('Existing row changed');$count++;}}
    echo "$count existing fixture badge/audit rows unchanged.\n";
}
