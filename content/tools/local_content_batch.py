"""Run locally in an interactive terminal: backup -> plans -> drafts/off-sale batch.

Never accepts/stores a password. mysqldump and each importer prompt interactively.
"""
import argparse
import hashlib
from datetime import datetime
from pathlib import Path
import subprocess
import sys
ROOT=Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser()
p.add_argument('--email',required=True)
p.add_argument('--backup-dir',type=Path,default=Path('C:/xampp/mysql_backups'))
p.add_argument('--activate-local',action='store_true',help='Use the user-approved localhost publication and exact-price sale workflows after draft import')
args=p.parse_args()
if not sys.stdin.isatty():raise SystemExit('Run in an interactive local terminal; no piped passwords.')
if args.email.endswith('@example.invalid'):raise SystemExit('Identify your authentic administrator, not a historical test actor.')
directory=args.backup_dir.resolve()
if directory.is_relative_to(ROOT.parent):raise SystemExit('Backup directory must be outside htdocs.')
directory.mkdir(parents=True,exist_ok=True)
backup=directory/('hunter_system_dev_before_content_'+datetime.now().strftime('%Y%m%d_%H%M%S_%f')+'.sql')
if backup.exists():raise SystemExit('Backup already exists; never overwrite it.')
subprocess.run(['C:/xampp/mysql/bin/mysqldump.exe','-u','root','-p','--single-transaction','--routines','--triggers','--events','--hex-blob','--default-character-set=utf8mb4','--databases','hunter_system_dev','--result-file='+str(backup)],check=True,cwd=ROOT)
if not backup.is_file() or not backup.stat().st_size:raise SystemExit('Backup failed; no import attempted.')
dump=backup.read_bytes()
if not all(marker in dump for marker in [b'CREATE TABLE `users`',b'CREATE TABLE `audit_log`',b'INSERT INTO',b'TRIGGER',b'Dump completed']):
    raise SystemExit('Backup content markers incomplete; no import attempted. Existing backup retained.')
print('Backup:',backup,'SHA256:',hashlib.sha256(dump).hexdigest(),'data/schema/trigger/completion markers verified; routines requested in dump options.')
del dump
history=backup.with_suffix('.history.json')
subprocess.run(['C:/xampp/php/php.exe',str(ROOT/'content/tools/capture_acceptance.php'),'capture',str(history),'hunter_system_dev'],check=True,cwd=ROOT)
def run(script,*options):subprocess.run([sys.executable,str(ROOT/'content/tools'/script),*options],check=True,cwd=ROOT)
if not (ROOT/'content/import-dev.json').exists():run('import_course.py','plan','--target','dev','--email',args.email,'--include-foundations')
else:run('import_course.py','revise-source','--target','dev','--email',args.email,'--include-foundations')
if not (ROOT/'content/badges/import-dev-reviewed.json').exists():run('import_badges.py','plan','--email',args.email)
input('Review both saved plans. Enter applies drafts/off-sale badges and approved sample retirements; Ctrl+C cancels: ')
run('import_course.py','apply','--target','dev','--email',args.email,'--backup',str(backup),'--include-foundations')
run('import_badges.py','apply','--email',args.email,'--backup',str(backup))
run('import_course.py','verify','--target','dev','--email',args.email,'--include-foundations')
run('import_badges.py','verify','--email',args.email)
if args.activate_local:
    run('import_course.py','publish-local','--target','dev','--email',args.email,'--backup',str(backup),'--include-foundations')
    run('import_badges.py','activate-local','--email',args.email,'--backup',str(backup))
subprocess.run(['C:/xampp/php/php.exe',str(ROOT/'content/tools/capture_acceptance.php'),'verify',str(history),'hunter_system_dev'],check=True,cwd=ROOT)
print('Local batch complete; history verified. Publication executed only if --activate-local was supplied.')
