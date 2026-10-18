"""Explicitly reconcile additive retired browser fixtures and audited lesson18 restore.
Never a generic conflict bypass: exact source payloads/status and fixture actors required.
"""
from pathlib import Path
import sys,json
sys.argv=['import_course.py','verify','--include-foundations']
source=Path('content/tools/import_course.py').read_text(encoding='utf-8');namespace={'__file__':str(Path('content/tools/import_course.py').resolve())}
exec(compile(source.split("if args.action == 'plan':")[0],namespace['__file__'],'exec'),namespace)
r=namespace['request'];payload=namespace['question_payload'];signature=namespace['pool_signature']
state=json.loads(Path('content/import-fixture.json').read_text(encoding='utf-8'));namespace['state']=state
added=[]
for tid,rec in state['records'].items():
 current=r('GET',f'/admin/topics/{tid}/lesson');old=rec['lesson']
 assert all(current[k]==old[k] for k in ['title','body_markdown','status']),f'Unexpected lesson content/status {tid}'
 if current['revision']!=old['revision']:
  assert tid=='18','Only identified lesson18 audited edits allowed'
  audit=r('GET',f"/admin/audit-log?entity_type=lesson&entity_id={current['lesson_id']}&per_page=100")
  assert audit['entries'],'Audited restoration required'
  rec['lesson']=current
 bank=namespace['questions_for'](tid);expected=rec['old_questions']+list(rec['new_questions'].values());ids={q['id'] for q in expected}
 for q in bank:
  if q['id'] not in ids:
   assert q['status']=='retired' and q['stem'].startswith('[FIXTURE] Browser question '),'Unexpected additional question'
   audit=r('GET',f"/admin/audit-log?entity_type=question&entity_id={q['id']}&per_page=100")
   assert audit['total']>=4,'Audited browser lifecycle required'
   rec['old_questions'].append(q);added.append(q['id'])
 assert signature(bank)==signature(rec['old_questions']+list(rec['new_questions'].values())),f'Unexpected question conflict {tid}'
 topic=next(t for t in r('GET','/admin/topics') if str(t['id'])==tid)
 assert topic['status']==rec['topic']['status'] and topic['is_ready'],'Topic not restored'
 if topic['version']!=rec['topic']['version']:assert tid=='18','Unexpected topic edit'
 rec['topic']=topic
namespace['save']();r('POST','/auth/logout',{});print('Fixture receipt reconciled after explicit equality checks; retired browser IDs:',added)
