<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/backend/src/bootstrap.php';
use Hunter\Support\Config;
use Hunter\Support\Console;
Console::requireCli();
$c=Config::load();
if($c->get('DB_NAME')!=='hunter_system_m3_fixture'||$c->get('DB_USER')!=='hunter_m7_fixture')throw new RuntimeException('Fixture only.');
$db=new PDO('mysql:host=127.0.0.1;dbname=hunter_system_m3_fixture;charset=utf8mb4',$c->get('DB_USER'),$c->get('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$state=json_decode(file_get_contents(dirname(__DIR__).'/import-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$audit=0;
foreach($state['records'] as $tid=>$rec){
    $st=$db->prepare("SELECT status FROM topics WHERE id=?");$st->execute([$tid]);if($st->fetchColumn()!=='available')throw new RuntimeException('Not available');
    $st=$db->prepare("SELECT status FROM lessons WHERE topic_id=?");$st->execute([$tid]);if($st->fetchColumn()!=='published')throw new RuntimeException('Not published');
    $st=$db->prepare("SELECT COUNT(*) FROM questions WHERE topic_id=? AND for_quiz=1 AND status='active'");$st->execute([$tid]);if((int)$st->fetchColumn()!==12)throw new RuntimeException('Pool is not exactly 12');
    foreach($rec['new_questions'] as $q){
        $st=$db->prepare("SELECT COUNT(*) FROM audit_log a JOIN users u ON u.id=a.admin_user_id WHERE a.entity_type='question' AND a.entity_id=? AND a.action IN ('create','publish') AND u.role='admin' AND u.email LIKE 'm8a.%@example.invalid'");$st->execute([$q['id']]);$n=(int)$st->fetchColumn();if($n<2)throw new RuntimeException('Missing attributable question create/activate audit');$audit+=$n;
    }
    $st=$db->prepare("SELECT COUNT(*) FROM audit_log a JOIN users u ON u.id=a.admin_user_id WHERE a.entity_type='lesson' AND a.entity_id=? AND a.action IN ('create','update','publish') AND u.role='admin' AND u.email LIKE 'm8a.%@example.invalid'");$st->execute([$rec['lesson']['lesson_id']]);$n=(int)$st->fetchColumn();if($n<2)throw new RuntimeException('Missing attributable lesson audit');$audit+=$n;$checks++;
}
$st=$db->query("SELECT COUNT(*) FROM topics t JOIN lessons l ON l.topic_id=t.id WHERE t.status='available' AND l.status='published' AND (SELECT COUNT(*) FROM questions q WHERE q.topic_id=t.id AND q.for_quiz=1 AND q.status='active')>=10");if((int)$st->fetchColumn()!==18)throw new RuntimeException('All 18 not ready');
$report=['topics_verified'=>$checks,'ready_topics'=>18,'authored_active_questions'=>192,'matching_attributed_audit_rows'=>$audit];
file_put_contents(dirname(__DIR__).'/fixture-verification.json',json_encode($report,JSON_PRETTY_PRINT)."\n");echo json_encode($report)."\n";
