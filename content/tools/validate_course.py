"""Execute only explicitly authored validation cases, each in a disposable directory."""
import ast
import collections
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
failures = []
cases = 0
questions = 0
mix = collections.Counter()

def check(condition, message):
    if not condition:
        failures.append(message)

def run(case, label):
    global cases
    code = case['code']
    try:
        tree = ast.parse(code)
    except SyntaxError as error:
        check(case['exception'] == type(error).__name__, label + ': unexpected syntax error')
        tree = None
    if tree:
        for node in ast.walk(tree):
            if isinstance(node, (ast.Import, ast.ImportFrom)):
                names = [n.name for n in node.names] if isinstance(node, ast.Import) else [node.module]
                check(all(n in {'math', 'tempfile'} for n in names), label + ': unapproved import')
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name):
                check(node.func.id not in {'eval', 'exec', '__import__', 'compile', 'breakpoint'}, label + ': unsafe call')
    if failures:
        return
    with tempfile.TemporaryDirectory(prefix='hunter-course-') as folder:
        directory = Path(folder)
        for name, text in case['files'].items():
            check(Path(name).name == name and name not in {'.', '..'}, label + ': unsafe fixture path')
            (directory / name).write_text(text, encoding='utf-8', newline='')
        # Source was reviewed before being placed in these explicit manifests.
        # File cases use only relative fixture paths or TemporaryDirectory paths.
        script = directory / 'case.py'
        script.write_text(code, encoding='utf-8', newline='\n')
        env = dict(os.environ, TEMP=folder, TMP=folder, PYTHONIOENCODING='utf-8')
        result = subprocess.run([sys.executable, '-I', '-X', 'utf8', str(script)], cwd=folder,
                                input=case['stdin'], text=True, capture_output=True, timeout=5, env=env)
        cases += 1
        if case['exception']:
            check(result.returncode != 0 and re.search(r'^' + re.escape(case['exception']) + r'(?::|$)', result.stderr, re.M), label + ': expected exception not observed')
        else:
            check(result.returncode == 0, label + ': unexpected failure ' + result.stderr[-200:])
            check(result.stdout == case['stdout'], label + ': output mismatch ' + repr(result.stdout))

manifest = json.loads((ROOT / 'manifest.json').read_text(encoding='utf-8'))
check([t['id'] for t in manifest['topics']] == list(range(3, 19)), 'Curriculum identifiers')
seen = set()
for topic in manifest['topics']:
    directory = ROOT / 'topics' / f"{topic['id']:02d}"
    lesson = (directory / 'lesson.md').read_text(encoding='utf-8')
    examples = json.loads((directory / 'examples.json').read_text(encoding='utf-8'))
    bank = json.loads((directory / 'questions.json').read_text(encoding='utf-8'))
    check(re.findall(r'```python\n(.*?)\n```', lesson, re.S) == [c['code'] for c in examples], f"Topic {topic['id']}: snippet manifest coverage")
    for heading in ['Learning objectives', 'Common mistakes', 'Recap', 'Optional practice']:
        check('## ' + heading in lesson, f"Topic {topic['id']}: missing {heading}")
    check('{{EX' not in lesson and '<script' not in lesson.lower(), 'Unresolved or unsafe lesson text')
    check(len(bank) >= 12, 'Insufficient bank')
    check(len({(q['stem'].strip(),q['code_snippet']) for q in bank})==len(bank), f"Topic {topic['id']}: duplicated question")
    check(all(any(q['kind']==kind for q in bank) for kind in ['concept','output','mistake']), f"Topic {topic['id']}: missing question category")
    for i, case in enumerate(examples):
        run(case, f"Topic {topic['id']} example {i+1}")
    for q in bank:
        questions += 1
        mix[q['kind']] += 1
        check(q['key'] not in seen, 'Duplicate source key')
        seen.add(q['key'])
        check(q['topic_id'] == topic['id'] and q['skill_group'] == topic['skill_group'] and q['for_quiz'] and not q['for_assessment'], q['key'] + ': tags')
        check(bool(q['explanation'].strip()) and bool(q['objective'].strip()), q['key'] + ': missing explanation/objective')
        choices = q['choices']
        check(len(choices) == 4 and len({c['text'] for c in choices}) == 4 and sum(c['is_correct'] for c in choices) == 1, q['key'] + ': choices')
        check(all(c['text'].strip() for c in choices), q['key'] + ': empty choice')
        if q.get('validation'):
            case = q['validation']
            check(case['code'] == q['code_snippet'], q['key'] + ': code mismatch')
            right = next(c['text'] for c in choices if c['is_correct'])
            check(right == (case['exception'] or case['stdout'].rstrip('\n')), q['key'] + ': answer mismatch')
            run(case, q['key'])
foundation_path=ROOT/'review/foundation-validation-cases.json'
if foundation_path.exists():
    foundation_cases=json.loads(foundation_path.read_text(encoding='utf-8'))
    foundation_snapshot=json.loads((ROOT/'review/foundation-seed-snapshot.json').read_text(encoding='utf-8'))
    check(len(foundation_snapshot['questions'])==26,'Foundation question count')
    for q in foundation_snapshot['questions']:
        check(len(q['choices'])==4 and len({c['text'] for c in q['choices']})==4 and sum(c['is_correct'] for c in q['choices'])==1 and bool(q['explanation']),f"Foundation question {q['id']}: structure")
    foundation_fences=[]
    for tid in [1,2]:
        text=(ROOT/f'review/topic-{tid:02d}-reviewed.md').read_text(encoding='utf-8')
        foundation_fences.extend(re.findall(r'```python\n(.*?)\n```',text,re.S))
    check(foundation_fences==[c['code'] for c in foundation_cases[:len(foundation_fences)]],'Foundation snippet manifest coverage')
    for i,case in enumerate(foundation_cases):
        run(case,f'Foundation reviewed case {i+1}')
else:
    foundation_cases=[]
report = dict(python=sys.version, executable=sys.executable, lessons=len(manifest['topics']), questions=questions,
              foundation_cases=len(foundation_cases),
              reviewed_total_lessons=18 if foundation_cases else 16,reviewed_quiz_questions=216 if foundation_cases else 192,
              executed_cases=cases, question_mix=dict(mix), failures=failures,
              scope='Explicit reviewed manifests only; isolated temporary directories; no app execution feature.',
              human_review_required=['Educational progression and wording', 'Conceptual distractors', 'Publication approval'])
(ROOT / 'validation-report.json').write_text(json.dumps(report, indent=2)+'\n', encoding='utf-8')
(ROOT / 'validation-report.md').write_text(f"# Course draft validation\n\nPython: {sys.version.split()[0]}\n\n{report['lessons']} lessons; {questions} questions; {cases} executable cases; {len(failures)} failures.\n\nMix: {dict(mix)}. Explicit manifests, controlled input and disposable temporary files were used. No Markdown was executed blindly. Concept questions and educational quality still need human review.\n\n"+'\n'.join('- '+f for f in failures)+'\n',encoding='utf-8')
print(json.dumps(report, indent=2))
sys.exit(bool(failures))
