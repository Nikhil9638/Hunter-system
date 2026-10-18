"""Focused acceptance after the audited fixture repair; fixed identity, no dev fallback."""
from pathlib import Path
import sys,json,urllib.parse,subprocess,os
sys.argv=['import_course.py','verify','--include-foundations']
s=Path('content/tools/import_course.py').read_text(encoding='utf-8');n={'__file__':str(Path('content/tools/import_course.py').resolve())};exec(compile(s.split("if args.action == 'plan':")[0],n['__file__'],'exec'),n)
r=n['request'];checks=0
def check(label,condition):
 global checks
 assert condition,label
 checks+=1;print('PASS',label)
topics=r('GET','/admin/topics');check('All18 reviewed topics restored and ready',len(topics)==18 and all(t['is_ready'] for t in topics))
for text in ["' OR 1=1 --",'<script>alert(1)</script>']:
 result=r('GET','/admin/students?query='+urllib.parse.quote(text));check('Hostile-looking search remains a literal, nonmatching query',result['total']==0)
recovery=json.loads(Path('content/review/final-fixture-recovery.json').read_text(encoding='utf-8'))
for item in recovery['replacements']:
 entries=r('GET',f"/admin/audit-log?entity_type=question&entity_id={item['replacement_id']}")['entries']
 check('Replacement create/activate audit attributed to authenticated fixture actor',len(entries)==2 and all(e['admin']['id']==recovery['actor']['id'] for e in entries))
g6=[q for q in r('GET','/admin/questions?usage=assessment&status=active&per_page=100')['questions'] if q['skill_group']=='G6']
if len(g6)==1:
 try:r('POST',f"/admin/questions/{g6[0]['id']}/retire",{'expected_revision':g6[0]['revision']})
 except RuntimeError as error:check('Assessment quota refuses last G6 retirement with no override','would_break_assessment_pool' in str(error))
 else:raise AssertionError('Quota retirement unexpectedly succeeded')
else:print('UNVERIFIED assessment quota boundary: G6 pool is not at quota; no original assessment retirement attempted')
r('POST','/auth/logout',{})
student=json.loads(Path('frontend/tests/results/final-course-acceptance.json').read_text(encoding='utf-8'))['student'];auth=r('POST','/auth/login',{'email':student,'password':'Course fixture student password'});n['csrf']=auth['csrf_token']
helper=['C:/xampp/php/php.exe','frontend/tests/db-helper.php'];env=dict(os.environ,HUNTER_PRIVATE_DIR=os.environ['HUNTER_FIXTURE_PRIVATE_DIR'])
check('Original full-course student still owns six badges with balance0',r('GET','/me/profile')['coins']==0 and len(r('GET','/me/profile')['owned_badges'])==6)
r('POST','/auth/logout',{})
email='repair.'+n['secrets'].token_hex(5)+'.student@example.invalid';auth=r('POST','/auth/register',{'email':email,'password':'Course repaired fixture password','display_name':'[FIXTURE] Repaired pool validation'});n['csrf']=auth['csrf_token']
r('PUT','/me/experience',{'experience_level_id':1});assessment=r('POST','/assessment',{});ak=json.loads(subprocess.check_output(helper+['answer-key',str(assessment['attempt_id'])],env=env))
for q in assessment['questions']:r('PUT',f"/assessment/{assessment['attempt_id']}/answers/{q['position']}",{'choice_id':ak[str(q['position'])]['right']})
r('POST',f"/assessment/{assessment['attempt_id']}/submit",{})
for topic in [17,18]:
 seen=set();bank=json.loads(Path(f'content/topics/{topic:02d}/questions.json').read_text(encoding='utf-8'))
 for retry in range(10 if topic==17 else 1):
  attempt=r('POST',f'/topics/{topic}/quiz-attempts',{'started_via':'direct'});key=json.loads(subprocess.check_output(helper+['answer-key',str(attempt['attempt_id'])],env=env))
  for q in attempt['questions']:
   original=next(b for b in bank if b['stem']==q['stem'] and b['code_snippet']==q['code_snippet']);seen.add(original['key'])
   assert not ('explanation' in q) and all('is_correct' not in c for c in q['choices'])
   choice=next(c for c in q['choices'] if c['id']==key[str(q['position'])]['right']);assert choice['text']==next(c['text'] for c in original['choices'] if c['is_correct'])
   r('PUT',f"/quiz-attempts/{attempt['attempt_id']}/answers/{q['position']}",{'choice_id':choice['id']})
  result=r('POST',f"/quiz-attempts/{attempt['attempt_id']}/submit",{});assert result['correct_count']==5 and result['coins_awarded']==(10 if retry==0 else 0)
  if topic==18:assert result['unlocked_topic'] is None
  if topic==17 and len(seen)==12:break
 check(f'Repaired topic {topic} selects authored pool, grades correctly, no duplicate rewards',len(seen)==(12 if topic==17 else 5))
check('High placement rank is preserved with only two topics passed',r('GET','/me/profile')['coins']==20 and r('GET','/me/profile')['hunter_rank']['code']=='S')
r('POST','/auth/logout',{});print(checks,'focused real fixture checks passed')
