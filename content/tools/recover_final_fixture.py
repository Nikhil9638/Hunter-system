"""One-shot recovery of the identified final-admin fixture rehearsal; never dev.
Retirement is permanent. Create audited equivalent replacements and retain all rows.
"""
from pathlib import Path
import sys,json
sys.argv=['import_course.py','verify','--include-foundations']
source=Path('content/tools/import_course.py').read_text(encoding='utf-8')
namespace={'__file__':str(Path('content/tools/import_course.py').resolve())}
exec(compile(source.split("if args.action == 'plan':")[0],namespace['__file__'],'exec'),namespace)
r=namespace['request'];payload=namespace['question_payload'];save=namespace['save']
state=json.loads(Path('content/import-fixture.json').read_text(encoding='utf-8'));namespace['state']=state
receipt=Path('content/review/final-fixture-recovery.json')
assert not receipt.exists(),'Recovery receipt exists; do not repeat or discard it'
changes=[]
rec=state['records']['17']
retired=[]
for key,old in list(rec['new_questions'].items()):
 current=r('GET',f"/admin/questions/{old['id']}")
 assert payload(current)==payload(old),'Unexpected payload edit; stop'
 if current['status']=='retired' and old['status']=='active':retired.append((key,old,current))
 else:assert current['revision']==old['revision'] and current['status']==old['status'],'Unexpected state'
assert len(retired)==3,'Only the three identified fixture retirements may be replaced'
for key,old,current in retired:
 created=r('POST','/admin/questions',payload(old));new=r('GET',f"/admin/questions/{created['id']}")
 r('POST',f"/admin/questions/{new['id']}/activate",{'expected_revision':new['revision']})
 rec['old_questions'].append(current);rec['new_questions'][key]=r('GET',f"/admin/questions/{new['id']}")
 changes.append({'key':key,'retired_id':old['id'],'replacement_id':new['id']})
 save()
topic=next(t for t in r('GET','/admin/topics') if t['id']==17)
assert topic['status']=='draft' and topic['quiz_pool_size']==12
r('PATCH','/admin/topics/17',{'expected_version':topic['version'],'status':'available'})
rec['topic']=next(t for t in r('GET','/admin/topics') if t['id']==17)
rec18=state['records']['18'];current=r('GET','/admin/topics/18/lesson')
assert current['title']=='[FIXTURE] Keep dirty form' and current['body_markdown'].startswith('# [FIXTURE] Display only') and current['status']=='published'
r('PUT','/admin/topics/18/lesson',{'expected_revision':current['revision'],'title':rec18['lesson']['title'],'body_markdown':rec18['lesson']['body_markdown']})
rec18['lesson']=r('GET','/admin/topics/18/lesson');rec18['topic']=next(t for t in r('GET','/admin/topics') if t['id']==18)
save();receipt.write_text(json.dumps({'target':'hunter_system_m3_fixture','actor':namespace['actor'],'reason':'Browser harness incorrectly attempted permanent-retirement reversal; equivalent authored replacements created through audited API. No original row deleted.','replacements':changes,'lesson18_restored':True},indent=2)+'\n',encoding='utf-8')
r('POST','/auth/logout',{})
print('Fixture recovery audited: 3 replacement questions, lesson18 restored; original rows retained.')
