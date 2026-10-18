<?php
/** Secret-free row fingerprints outside htdocs; never dumps data or credentials. */
declare(strict_types=1);
require dirname(__DIR__,2).'/backend/src/bootstrap.php';
use Hunter\Support\Config;
use Hunter\Support\Console;
Console::requireCli();$mode=$argv[1]??'';$path=$argv[2]??'';
if(!in_array($mode,['capture','verify'],true)||$path==='')throw new RuntimeException('capture|verify and outside-htdocs path required');
$parent=realpath(dirname($path));$htdocs=realpath(dirname(__DIR__,3));
if(!$parent||str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR),strtolower($htdocs.DIRECTORY_SEPARATOR)))throw new RuntimeException('Fingerprint output must be outside htdocs');
$c=Config::load();$name=$c->get('DB_NAME');
if(isset($argv[3])&&$argv[3]!==$name)throw new RuntimeException('Expected database/config mismatch; no content writes allowed');
if(!in_array($name,['hunter_system_dev','hunter_system_m3_fixture','hunter_system_m7_seed_fixture'],true))throw new RuntimeException('Unexpected database');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4',$c->get('DB_USER'),$c->get('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tables=['attempts','attempt_questions','attempt_question_choices','quiz_results','assessment_results','coin_ledger','rank_history','badge_ownership','audit_log'];
$out=['database'=>$name,'tables'=>[]];
foreach($tables as $table){$hashes=[];foreach($db->query("SELECT * FROM `$table`") as $row){ksort($row);$hashes[]=hash('sha256',json_encode($row));}sort($hashes);$out['tables'][$table]=['count'=>count($hashes),'hashes'=>$hashes];}
foreach(['ir1_question_106'=>'SELECT * FROM questions WHERE id=106','ir1_choices_106'=>'SELECT * FROM question_choices WHERE question_id=106'] as $label=>$query){$hashes=[];foreach($db->query($query) as $row){ksort($row);$hashes[]=hash('sha256',json_encode($row));}sort($hashes);$out['tables'][$label]=['count'=>count($hashes),'hashes'=>$hashes];}
if($mode==='capture'){
    if(file_exists($path))throw new RuntimeException('Refusing to overwrite existing fingerprint baseline');
    file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT)."\n");echo "Captured existing history/audit row fingerprints for $name.\n";
}else{
    $before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if($before['database']!==$name)throw new RuntimeException('Database baseline mismatch');
    foreach($before['tables'] as $table=>$rows){$available=array_count_values($out['tables'][$table]['hashes']);foreach($rows['hashes'] as $hash){if(($available[$hash]??0)<1)throw new RuntimeException("Original history row changed or missing: $table");$available[$hash]--;}}
    echo "All original history/audit rows preserved; additive activity allowed.\n";
}
