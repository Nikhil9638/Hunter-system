"""Authenticated, versioned badge batch. No price approval or on-sale operation.

Plan baseline -> apply approved names/prices + off-sale status + sample retirement.
Root/DB passwords never enter this script. Back up separately before apply.
"""
import argparse
import getpass
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request
import warnings

ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser()
p.add_argument('action',choices=['plan','apply','activate-local','verify'])
p.add_argument('--email')
p.add_argument('--target',choices=['dev','fixture'],default='dev')
p.add_argument('--backup',type=Path,help='Existing nonempty logical backup outside htdocs, required for apply')
args=p.parse_args()
if args.target=='dev' and (not args.email or args.email.endswith('@example.invalid')):
    raise SystemExit('Historical test actors cannot attribute real catalogue changes.')
if args.target=='dev' and not sys.stdin.isatty():raise SystemExit('Use an interactive local terminal; piped passwords refused.')
manifest=json.loads((ROOT/'badges/manifest.json').read_text(encoding='utf-8'))
expected=[('Shadow Recruit',10),('Shadow Soldier',15),('Shadow Knight',20),('Elite Shadow Knight',30),('Shadow Commander',45),('Shadow Monarch',60)]
if [(b['name'],b['approved_price']) for b in manifest['badges']]!=expected:raise SystemExit('Approved names/prices changed; refusing import.')
API='http://localhost/hunter-system/api/v1' if args.target=='dev' else 'http://127.0.0.1:8098/hunter-system/api/v1'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
csrf=None
def req(method,path,data=None):
    headers={'Origin':'http://localhost'}
    if csrf:headers['X-CSRF-Token']=csrf
    if data is not None:headers['Content-Type']='application/json'
    try:
        with opener.open(urllib.request.Request(API+path,method=method,headers=headers,data=None if data is None else json.dumps(data).encode()),timeout=30) as r:return json.load(r)['data']
    except urllib.error.HTTPError as e:raise SystemExit(f'{method} {path}: HTTP {e.code}; stopped without retry. Review the server record.')
    except urllib.error.URLError:raise SystemExit('Local API unavailable. Start the authorized local services; no automatic retry or permission changes.')
def fingerprint(value):return hashlib.sha256(json.dumps(value,sort_keys=True,ensure_ascii=False).encode()).hexdigest()
def signature(b):
    return {k:b[k] for k in ['id','slug','name','description','image_ref','price','price_status','status','version']}
def records():return {b['slug']:b for b in req('GET','/admin/badges')}
path=ROOT/('badges/import-'+args.target+'-reviewed.json')
def save():
    tmp=path.with_suffix('.tmp');tmp.write_text(json.dumps(state,indent=2,ensure_ascii=False)+'\n',encoding='utf-8');tmp.replace(path)
if args.target=='fixture':
    private=os.environ.get('HUNTER_FIXTURE_PRIVATE_DIR')
    if not private:raise SystemExit('Explicit fixture private configuration required.')
    identity=req('GET','/__test/identity')
    if identity!={'database':'hunter_system_m3_fixture','account':'hunter_m7_fixture'}:raise SystemExit('Fixture identity mismatch.')
    info=json.loads(subprocess.check_output(['C:/xampp/php/php.exe',str(ROOT/'badges/fixture_state.php'),'identity'],env=dict(os.environ,HUNTER_PRIVATE_DIR=private)))
    if {k:info[k] for k in ['database','account']}!=identity:raise SystemExit('CLI/backend fixture mismatch.')
    args.email=info['fixture_admin_email'];password='Administrator browser fixture password'
else:
    with warnings.catch_warnings():
        warnings.simplefilter('error',getpass.GetPassWarning)
        password=getpass.getpass('Your administrator password (not stored): ')
auth=req('POST','/auth/login',dict(email=args.email,password=password));del password
csrf=auth['csrf_token'];del auth
me=req('GET','/auth/me')['user']
if me['role']!='admin':raise SystemExit('Administrator required.')
actor=dict(id=me['id'],email=me['email'])
catalogue=records()
samples=['syntax-scout','variable-voyager','loop-ranger','gate-breaker']
desired={(('fx-reviewed-' if args.target=='fixture' else '')+b['proposal_key']):dict(name=b['name'],description=b['description'],image_ref=b['image_ref'],price=b['approved_price']) for b in manifest['badges']}
source_hash=fingerprint(desired)
if args.action=='plan':
    if path.exists():raise SystemExit('Plan exists; retain its conflict baseline.')
    state=dict(api=API,source_hash=source_hash,planned_by=actor,records={},complete=False)
    for slug in list(desired)+samples:
        state['records'][slug]=dict(current=signature(catalogue[slug]) if slug in catalogue else None)
        if slug in samples and slug not in catalogue:raise SystemExit('Missing expected sample; review target database.')
    save();print('Badge plan saved: six off-sale approved-title/price records; four audited sample retirements. No catalogue writes.')
else:
    state=json.loads(path.read_text(encoding='utf-8'))
    if state['api']!=API or state['source_hash']!=source_hash:raise SystemExit('Source/target changed since plan.')
    for slug,rec in state['records'].items():
        if (signature(catalogue[slug]) if slug in catalogue else None)!=rec['current']:raise SystemExit(f'{slug}: edit conflict; plan preserved.')
    if args.action=='apply':
        if state.get('activated'):
            req('POST','/auth/logout',{})
            print('Activated catalogue matches saved baseline; repeated import is a no-op, not an unpublication.')
            raise SystemExit(0)
        if args.target=='dev':
            if not args.backup:raise SystemExit('Existing logical backup required before catalogue batch.')
            backup=args.backup.resolve()
            if not backup.is_file() or backup.stat().st_size==0 or backup.is_relative_to(ROOT.parent.parent):raise SystemExit('Backup must be nonempty and outside htdocs.')
            state['backup']={'path':str(backup),'sha256':hashlib.sha256(backup.read_bytes()).hexdigest()};save()
        for slug,payload in desired.items():
            rec=state['records'][slug]
            if rec['current'] is None:
                req('POST','/admin/badges',dict(slug=slug,**payload));rec['current']=signature(records()[slug]);save()
            b=rec['current']
            if b['status']=='retired':raise SystemExit('Approved title has retired catalogue record; never resurrect it silently.')
            if b['status']=='on_sale':
                req('POST',f"/admin/badges/{b['id']}/status",dict(expected_version=b['version'],status='off_sale'));rec['current']=signature(records()[slug]);save();b=rec['current']
            if any(b[k]!=v for k,v in payload.items()):
                req('PUT',f"/admin/badges/{b['id']}",dict(expected_version=b['version'],**payload));rec['current']=signature(records()[slug]);save();b=rec['current']
            if b['status']!='off_sale':
                req('POST',f"/admin/badges/{b['id']}/status",dict(expected_version=b['version'],status='off_sale'));rec['current']=signature(records()[slug]);save()
        for slug in samples:
            b=state['records'][slug]['current']
            if b['status']!='retired':
                req('POST',f"/admin/badges/{b['id']}/status",dict(expected_version=b['version'],status='retired'));state['records'][slug]['current']=signature(records()[slug]);save()
        state['complete']=True;state['last_actor']=actor;save();print('Six badges off sale; four samples retired through authenticated audited APIs. No price-approval event fabricated.')
    elif args.action=='activate-local':
        if not state.get('complete'):raise SystemExit('Complete the approved catalogue import first.')
        if args.target=='dev' and (not args.backup or not args.backup.is_file() or not args.backup.stat().st_size or args.backup.resolve().is_relative_to(ROOT.parent.parent)):
            raise SystemExit('Verified backup outside htdocs required before activation.')
        unexpected=[slug for slug,b in catalogue.items() if b['status']=='on_sale' and slug not in desired]
        if unexpected:raise SystemExit('Unexpected on-sale catalogue entries; reconcile through admin workflow before activation.')
        for slug,payload in desired.items():
            rec=state['records'][slug];b=rec['current']
            if b['price']!=payload['price']:raise SystemExit('Approved price mismatch; refusing approval.')
            if b['price_status']!='approved':
                req('POST',f"/admin/badges/{b['id']}/approve-price",dict(expected_version=b['version'],price=payload['price']));rec['current']=signature(records()[slug]);save();b=rec['current']
            if b['status']!='on_sale':
                req('POST',f"/admin/badges/{b['id']}/status",dict(expected_version=b['version'],status='on_sale'));rec['current']=signature(records()[slug]);save()
        final=records()
        if {slug for slug,b in final.items() if b['status']=='on_sale'}!=set(desired):raise SystemExit('Final on-sale set differs from six approved badges.')
        state['activated']=True;state['last_actor']=actor;save();print('Exactly six approved badges on sale; real administrator price-approval audit events recorded.')
    else:print('Saved catalogue baseline matches server. No catalogue writes.')
req('POST','/auth/logout',{})
