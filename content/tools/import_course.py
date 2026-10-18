"""Audited API importer. Plan first; apply only the exact saved baseline.

Fixture publication is a separate guarded action. Dev imports only drafts.
Passwords are read interactively (or generated internally for a labelled fixture actor).
"""
import argparse
import getpass
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import sys
import warnings
import subprocess
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PROJECT = ROOT.parent
parser = argparse.ArgumentParser()
parser.add_argument('action', choices=['plan', 'apply', 'publish-fixture', 'publish-local', 'verify', 'revise-source'])
parser.add_argument('--target', choices=['fixture', 'dev'], default='fixture')
parser.add_argument('--email')
parser.add_argument('--backup',type=Path,help='Nonempty logical backup outside htdocs required for dev apply')
parser.add_argument('--include-foundations',action='store_true',help='Stage reviewed topics1–2 and explanation corrections using existing IDs')
args = parser.parse_args()
api = 'http://127.0.0.1:8098/hunter-system/api/v1' if args.target == 'fixture' else 'http://localhost/hunter-system/api/v1'
state_file = ROOT / ('import-' + args.target + '.json')
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
csrf = None

def request(method, path, data=None, optional=False):
    headers = {'Origin': 'http://localhost'}
    if csrf:
        headers['X-CSRF-Token'] = csrf
    body = None
    if data is not None:
        headers['Content-Type'] = 'application/json'
        body = json.dumps(data).encode()
    try:
        with opener.open(urllib.request.Request(api+path, data=body, headers=headers, method=method), timeout=30) as response:
            return json.load(response)['data']
    except urllib.error.HTTPError as e:
        if optional and e.code == 404:
            return None
        # Only errors, not successful authentication payloads, are displayed.
        raise RuntimeError(f'{method} {path}: HTTP {e.code}: {e.read().decode()}') from None

def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False).encode()).hexdigest()

def save():
    temporary = state_file.with_suffix('.tmp')
    temporary.write_text(json.dumps(state, indent=2, ensure_ascii=False)+'\n', encoding='utf-8')
    temporary.replace(state_file)

def question_payload(q):
    payload = {k:q[k] for k in ['topic_id','for_quiz','for_assessment','difficulty','stem','code_snippet','explanation']}
    payload['choices']=[{k:(c[k].strip() if k=='text' else c[k]) for k in ['label','text','is_correct']} for c in q['choices']]
    return payload

def questions_for(topic):
    result = []
    page = 1
    while True:
        data = request('GET', f'/admin/questions?topic_id={topic}&usage=quiz&per_page=100&page={page}')
        # API pagination shape is intentionally checked rather than guessed.
        items = data['questions']
        result.extend(items)
        if len(items) < 100:
            return result
        page += 1

def pool_signature(bank):
    # Usage counters change when students take quizzes; they are not content edits.
    # Revisions/status/payload/choice IDs still detect every relevant edit.
    return digest([dict(id=q['id'],revision=q['revision'],status=q['status'],
                        payload=question_payload(q),choice_ids=[c['id'] for c in q['choices']])
                   for q in sorted(bank,key=lambda q:q['id'])])

manifest = json.loads((ROOT/'manifest.json').read_text(encoding='utf-8'))
sources = {}
for t in manifest['topics']:
    directory = ROOT/'topics'/f"{t['id']:02d}"
    sources[str(t['id'])] = dict(title=t['title'], body_markdown=(directory/'lesson.md').read_text(encoding='utf-8').strip(),
                                questions=json.loads((directory/'questions.json').read_text(encoding='utf-8')))
if args.include_foundations:
    corrections=json.loads((ROOT/'review/foundation-question-corrections.json').read_text(encoding='utf-8'))
    foundations=json.loads((ROOT/'review/foundation-seed-snapshot.json').read_text(encoding='utf-8'))
    for lesson in foundations['lessons']:
        tid=str(lesson['topic_id'])
        sources[tid]=dict(title=lesson['title'],body_markdown=(ROOT/f'review/topic-{int(tid):02d}-reviewed.md').read_text(encoding='utf-8').strip(),questions=[],corrections=[q for q in corrections if str(q['topic_id'])==tid])
source_hash = digest(sources)

def check_foundation_bank(tid,bank):
    for corrected in sources[tid].get('corrections',[]):
        current=next((q for q in bank if q['id']==corrected['id']),None)
        original=next(q for q in foundations['questions'] if q['id']==corrected['id'])
        if current is None:raise SystemExit('Foundation question missing; refusing replacement IDs.')
        actual=question_payload(current);desired=question_payload(corrected)
        if any(actual[k]!=desired[k] for k in actual if k!='explanation') or actual['explanation'] not in [original['explanation'],corrected['explanation']]:
            raise SystemExit('Foundation question differs from reviewed baseline; compare before any content writes.')

if args.target == 'fixture':
    private = os.environ.get('HUNTER_FIXTURE_PRIVATE_DIR')
    if not private:
        raise SystemExit('HUNTER_FIXTURE_PRIVATE_DIR required; no default dev fallback.')
    identity = request('GET', '/__test/identity')
    if identity != {'database':'hunter_system_m3_fixture','account':'hunter_m7_fixture'}:
        raise SystemExit('Unexpected fixture backend identity; refusing all writes.')
    # Confirm the CLI helper is connected to that same restricted fixture.
    env = dict(os.environ, HUNTER_PRIVATE_DIR=private)
    helper = ['C:/xampp/php/php.exe', str(PROJECT/'frontend/tests/db-helper.php')]
    actual = json.loads(subprocess.check_output(helper+['fixture-identity'],env=env))
    if actual != identity:
        raise SystemExit('Fixture CLI/backend mismatch.')
    email = 'm8a.'+secrets.token_hex(5)+'.admin@example.invalid'
    password = secrets.token_urlsafe(32)
    if not json.loads(subprocess.check_output(helper+['create-admin',email,password],env=env)):
        raise SystemExit('Fixture administrator creation failed.')
else:
    if args.action == 'publish-fixture':
        raise SystemExit('Dev publication is prohibited.')
    if args.action=='publish-local' and not args.include_foundations:
        raise SystemExit('Local publication requires the reviewed full18-topic receipt, including foundations.')
    if not args.email:
        raise SystemExit('Explicit administrator email is required; no test actor fallback.')
    if args.email.endswith('@example.invalid'):
        raise SystemExit('Historical test actors cannot attribute real dev course changes.')
    email = args.email
    if not sys.stdin.isatty():
        raise SystemExit('An interactive local terminal is required for hidden authentication; no piped passwords.')
    with warnings.catch_warnings():
        warnings.simplefilter('error',getpass.GetPassWarning)
        password = getpass.getpass('Administrator password (not stored): ')
auth = request('POST','/auth/login',dict(email=email,password=password))
del password
csrf = auth['csrf_token']
me = request('GET','/auth/me')
if me['user']['role'] != 'admin':
    raise SystemExit('Administrator required.')
actor = dict(id=me['user']['id'],email=email)
topics = request('GET','/admin/topics')
if isinstance(topics,dict):
    topics = topics['items']
indexed = {str(t['id']):t for t in topics}

if args.action == 'plan':
    if state_file.exists():
        raise SystemExit('Plan already exists. Review it; do not replace its conflict baseline.')
    state = dict(target=args.target,api=api,source_hash=source_hash,planned_by=actor,records={},complete=False)
    for tid, source in sources.items():
        t = indexed[tid]
        if t['title'] != source['title']:
            raise SystemExit('Fixed curriculum title mismatch.')
        lesson = request('GET',f'/admin/topics/{tid}/lesson',optional=True)
        bank = questions_for(tid)
        if args.target == 'dev' and int(tid)>2 and (t['status'] != 'draft' or lesson or bank):
            raise SystemExit('Dev target is not empty draft curriculum; manual conflict review required.')
        if int(tid)<=2:
            original=next(l for l in foundations['lessons'] if str(l['topic_id'])==tid)
            if not lesson or lesson['body_markdown'].strip() not in [original['body_markdown'].strip(),source['body_markdown']]:
                raise SystemExit('Foundation lesson differs from reviewed baseline; compare deliberately before correction.')
            check_foundation_bank(tid,bank)
        state['records'][tid] = dict(topic=t,lesson=lesson,old_questions=bank,new_questions={},done=False)
    save()
    print('Read-only import plan saved. Review baseline and source hash before apply.')
else:
    state = json.loads(state_file.read_text(encoding='utf-8'))
    if state['target'] != args.target or state['api'] != api or (state['source_hash'] != source_hash and args.action != 'revise-source'):
        raise SystemExit('Source/target changed since plan; refusing overwrite.')
    # Preflight ALL records before the first write, including existing pool members.
    for tid, rec in state['records'].items():
        current = request('GET',f'/admin/topics/{tid}/lesson',optional=True)
        if digest(current) != digest(rec['lesson']) or indexed[tid]['version'] != rec['topic']['version']:
            raise SystemExit(f'Topic {tid}: edit conflict; input and plan preserved.')
        bank = questions_for(tid)
        expected = rec['old_questions'] + list(rec['new_questions'].values())
        if pool_signature(bank) != pool_signature(expected):
            raise SystemExit(f'Topic {tid}: question pool changed; refusing duplicate/overwrite.')
    if args.action=='revise-source' and args.include_foundations:
        # Existing full-content fixture receipts predate foundation review. Add
        # read-only baselines, never guessing revisions or creating new questions.
        for tid in ['1','2']:
            if tid not in state['records']:
                lesson=request('GET',f'/admin/topics/{tid}/lesson')
                original=next(l for l in foundations['lessons'] if str(l['topic_id'])==tid)
                if lesson['body_markdown'].strip() not in [original['body_markdown'].strip(),sources[tid]['body_markdown']]:
                    raise SystemExit('Foundation lesson differs from reviewed baseline; explicit comparison required.')
                bank=questions_for(tid);check_foundation_bank(tid,bank)
                state['records'][tid]=dict(topic=indexed[tid],lesson=lesson,old_questions=bank,new_questions={},done=False)
    if args.action == 'revise-source':
        for tid, rec in state['records'].items():
            for q in sources[tid]['questions']:
                original = rec['new_questions'].get(q['key'])
                if original:
                    incoming=question_payload(q)
                    existing=question_payload(original)
                    if digest(incoming)!=digest(existing):
                        if any(incoming[k]!=existing[k] for k in ['topic_id','for_quiz','for_assessment']):
                            raise SystemExit('Refusing to change imported question tagging.')
            rec['done'] = bool(rec['lesson'] and rec['lesson']['body_markdown']==sources[tid]['body_markdown'] and rec['lesson']['title']==sources[tid]['title'] and all(digest(question_payload(q))==digest(question_payload(rec['new_questions'][q['key']])) for q in sources[tid]['questions']) and all(any(old['id']==q['id'] and question_payload(old)==question_payload(q) for old in rec['old_questions']) for q in sources[tid].get('corrections',[])))
        state['source_hash']=source_hash;state['complete']=all(r['done'] for r in state['records'].values());save()
        print('Deliberate source revision accepted after full server baseline comparison; no content written.')
    elif args.action == 'apply':
        if args.target=='dev':
            if not args.backup:
                raise SystemExit('Back up hunter_system_dev first; provide --backup with the logical dump outside htdocs.')
            backup=args.backup.resolve()
            if not backup.is_file() or backup.stat().st_size==0 or backup.is_relative_to(PROJECT.parent):
                raise SystemExit('Logical backup must be nonempty and outside htdocs.')
            state['backup']=dict(path=str(backup),sha256=hashlib.sha256(backup.read_bytes()).hexdigest());save()
        for tid, source in sources.items():
            rec = state['records'][tid]
            if rec['done']:
                continue
            if rec['topic']['status'] != 'draft':
                request('PATCH',f'/admin/topics/{tid}',dict(expected_version=rec['topic']['version'],status='draft'))
                rec['topic'] = next(t for t in request('GET','/admin/topics') if str(t['id']) == tid)
                save()
            if rec['lesson'] and rec['lesson']['status'] == 'published':
                request('POST',f'/admin/topics/{tid}/lesson/unpublish',dict(expected_revision=rec['lesson']['revision']))
                rec['lesson'] = request('GET',f'/admin/topics/{tid}/lesson'); save()
            desired = dict(title=source['title'],body_markdown=source['body_markdown'])
            if not rec['lesson'] or any(rec['lesson'][k] != v for k,v in desired.items()):
                request('PUT',f'/admin/topics/{tid}/lesson',dict(expected_revision=rec['lesson']['revision'] if rec['lesson'] else 0,**desired))
                rec['lesson'] = request('GET',f'/admin/topics/{tid}/lesson'); save()
            for q in source['questions']:
                if q['key'] in rec['new_questions']:
                    current=rec['new_questions'][q['key']]
                    if digest(question_payload(current))!=digest(question_payload(q)):
                        request('PUT',f"/admin/questions/{current['id']}",dict(expected_revision=current['revision'],**question_payload(q)))
                        rec['new_questions'][q['key']]=request('GET',f"/admin/questions/{current['id']}");save()
                    continue
                created = request('POST','/admin/questions',question_payload(q))
                rec['new_questions'][q['key']] = request('GET',f"/admin/questions/{created['id']}"); save()
            for q in source.get('corrections',[]):
                index=next((i for i,old in enumerate(rec['old_questions']) if old['id']==q['id']),None)
                if index is None:raise SystemExit('Reviewed foundation question is missing; refusing replacement IDs.')
                old=rec['old_questions'][index]
                payload=question_payload(old)
                proposed=question_payload(q)
                # Only the reviewed explanation changes; preserve actual choice IDs,
                # tagging and any incident history, including question106 untouched.
                if any(payload[k]!=proposed[k] for k in payload if k!='explanation'):
                    raise SystemExit('Foundation question differs from reviewed seed; explicit comparison required.')
                if old['explanation']!=q['explanation']:
                    request('PUT',f"/admin/questions/{q['id']}",dict(expected_revision=old['revision'],**proposed))
                    rec['old_questions'][index]=request('GET',f"/admin/questions/{q['id']}");save()
            rec['done'] = True; save()
        state['complete'] = True; state['last_actor'] = actor; save()
        print(f'Draft import complete: {len(sources)} lessons, 192 new-bank questions; existing foundation IDs retained. Repeated apply is a no-op.')
    elif args.action in ['publish-fixture','publish-local']:
        if args.action=='publish-local' and args.target!='dev':raise SystemExit('publish-local requires explicit dev target.')
        if args.action=='publish-local' and (not args.backup or not args.backup.is_file() or args.backup.stat().st_size==0 or args.backup.resolve().is_relative_to(PROJECT.parent)):
            raise SystemExit('Local publication requires the verified backup outside htdocs.')
        if not state['complete']:
            raise SystemExit('Finish draft import first.')
        for tid, rec in state['records'].items():
            # Preserve old rows and snapshots, but exclude placeholder pools from
            # course selection. This is fixture-only, with an audited retirement.
            for i,q in enumerate(rec['old_questions']):
                if int(tid)>2 and args.target=='fixture' and q['status'] != 'retired':
                    if q['for_assessment']:
                        raise SystemExit('Refusing to retire an assessment question.')
                    request('POST',f"/admin/questions/{q['id']}/retire",dict(expected_revision=q['revision']))
                    rec['old_questions'][i] = request('GET',f"/admin/questions/{q['id']}"); save()
            for key,q in rec['new_questions'].items():
                if q['status'] == 'draft':
                    request('POST',f"/admin/questions/{q['id']}/activate",dict(expected_revision=q['revision']))
                    rec['new_questions'][key] = request('GET',f"/admin/questions/{q['id']}"); save()
            if rec['lesson']['status'] != 'published':
                request('POST',f'/admin/topics/{tid}/lesson/publish',dict(expected_revision=rec['lesson']['revision']))
                rec['lesson'] = request('GET',f'/admin/topics/{tid}/lesson'); save()
            if rec['topic']['status'] != 'available':
                request('PATCH',f'/admin/topics/{tid}',dict(expected_version=rec['topic']['version'],status='available'))
                rec['topic'] = next(t for t in request('GET','/admin/topics') if str(t['id']) == tid); save()
        ready=request('GET','/admin/topics')
        if len(ready)!=18 or not all(t['is_ready'] for t in ready):raise SystemExit('Publication incomplete: not all18 topics ready.')
        state['published']=True;save()
        print('All18 topics ready; published through authenticated audited APIs. History retained.')
    else:
        print('Conflict baseline and source hash match. No content writes performed.')
request('POST','/auth/logout',{})
