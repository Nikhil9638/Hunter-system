<?php
// Read-only fingerprints. Baseline verification checks original rows, allowing
// new, clearly labelled fixture students/attempts without changing old history.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/backend/src/bootstrap.php';
use Hunter\Support\Config;
use Hunter\Support\Console;
Console::requireCli();
$c=Config::load();
if ($c->get('DB_NAME') !== 'hunter_system_m3_fixture') { throw new RuntimeException('Fixture only.'); }
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$c->get('DB_NAME').';charset=utf8mb4',$c->get('DB_USER'),$c->get('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$path=dirname(__DIR__).'/history-baseline.json';
$tables=['attempts','attempt_questions','attempt_question_choices','quiz_results','assessment_results','rank_history','coin_ledger'];
if (($argv[1]??'')==='capture') {
    if (is_file($path)) { throw new RuntimeException('Baseline already exists.'); }
    $out=[];
    foreach($tables as $table) {
        $out[$table]=[];
        $key=in_array($table,['quiz_results','assessment_results'],true)?'attempt_id':'id';
        foreach($pdo->query("SELECT * FROM `$table` ORDER BY `$key`") as $row) { $out[$table][(string)$row[$key]]=hash('sha256',json_encode($row)); }
    }
    file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT)."\n");
    echo "Existing history baseline captured.\n";
} else {
    $base=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR); $count=0;
    foreach($base as $table=>$rows) {
        $key=in_array($table,['quiz_results','assessment_results'],true)?'attempt_id':'id';
        $st=$pdo->prepare("SELECT * FROM `$table` WHERE `$key`=?");
        foreach($rows as $id=>$hash) { $st->execute([$id]); $row=$st->fetch(); if(!$row || hash('sha256',json_encode($row))!==$hash) { throw new RuntimeException("History changed: $table/$id"); } $count++; }
    }
    echo "$count original history rows unchanged.\n";
}
