"""Negative rehearsal: deliberately stale local revision, no server edit or overwrite."""
import json
from pathlib import Path
import subprocess
import sys
ROOT=Path(__file__).resolve().parents[1]
path=ROOT/'import-fixture.json'
original=path.read_bytes()
try:
    state=json.loads(original)
    state['records']['3']['lesson']['revision']-=1
    path.write_text(json.dumps(state,indent=2)+'\n',encoding='utf-8')
    result=subprocess.run([sys.executable,str(ROOT/'tools/import_course.py'),'verify'],capture_output=True,text=True)
    assert result.returncode!=0 and 'edit conflict' in result.stdout+result.stderr, result.stdout+result.stderr
    assert json.loads(path.read_text(encoding='utf-8'))==state, 'Stale plan must remain available for comparison'
    print('PASS deliberately stale plan rejected before content writes; local plan preserved.')
finally:
    path.write_bytes(original)
