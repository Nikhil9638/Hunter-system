"""Read-only SQL seed extraction and reviewed foundation sources. Never executes SQL."""
import json
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]
seed=(ROOT.parent/'database/seed.sql').read_text(encoding='utf-8')
def rows(section):
    result=[];i=0
    while i<len(section):
        if section.startswith('--',i):
            i=section.find('\n',i)
            if i<0:break
        elif section[i]=='(':
            fields=[];value='';quoted=False;in_string=False;i+=1
            while i<len(section):
                c=section[i]
                if in_string:
                    if c=="'":
                        if i+1<len(section) and section[i+1]=="'":value+="'";i+=1
                        else:in_string=False
                    elif c=='\\':
                        i+=1;value+=dict(n='\n',r='\r',t='\t').get(section[i],section[i])
                    else:value+=c
                elif c=="'":in_string=True;quoted=True;value=''
                elif c in ',)':
                    fields.append(value if quoted else None if value.strip()=='NULL' else int(value.strip()))
                    value='';quoted=False
                    if c==')':break
                elif not quoted:value+=c
                i+=1
            result.append(fields)
        i+=1
    return result
lessons=rows(seed.split('INSERT INTO seed_lessons (id, topic_id, title, body, status, revision) VALUES',1)[1].split('INSERT INTO lessons',1)[0])
# Semicolons inside quoted prose make splitting unsafe: isolate statements by
# their following INSERT/comment boundary instead, preserving quoted semicolons.
questions=[]
for match in re.finditer(r'INSERT INTO seed_questions VALUES\s*(.*?);\s*(?=\n(?:\n|--|INSERT))',seed,re.S):questions.extend(rows(match[1]))
choices=rows(seed.split('INSERT INTO seed_question_choices (id, question_id, label, choice_text, is_correct) VALUES',1)[1].split('INSERT INTO question_choices',1)[0].rstrip().rstrip(';'))
bank=[]
for q in questions:
    if q[1] not in [1,2]:continue
    bank.append(dict(id=q[0],topic_id=q[1],for_quiz=bool(q[2]),for_assessment=bool(q[3]),difficulty=q[4],stem=q[5],code_snippet=q[6],explanation=q[7],status=q[8],choices=[dict(id=c[0],label=c[2],text=c[3],is_correct=bool(c[4])) for c in choices if c[1]==q[0]]))
assert len(bank)==26 and all(len(q['choices'])==4 for q in bank)
snapshot=dict(source='database/seed.sql; live database unavailable during this review; verify current revisions before any eventual audited correction',lessons=[dict(id=l[0],topic_id=l[1],title=l[2],body_markdown=l[3],status=l[4],revision=l[5]) for l in lessons],questions=bank)
directory=ROOT/'review';directory.mkdir(exist_ok=True)
(directory/'foundation-seed-snapshot.json').write_text(json.dumps(snapshot,indent=2)+'\n',encoding='utf-8')
objectives={1:'- Explain what Python is and read simple print calls.\n- Recognize strings, comments, case sensitivity and indentation.',2:'- Assign, update and name variables.\n- Trace several assignments, swaps and independent reassignment.'}
cases=[]
for l in snapshot['lessons']:
    tid=l['topic_id'];body=l['body_markdown']
    body=body.replace('\n\nPython is', '\n\n## Learning objectives\n\n'+objectives[tid]+'\n\nPython is',1) if tid==1 else body.replace('\n\nA **variable**','\n\n## Learning objectives\n\n'+objectives[tid]+'\n\nA **variable**',1)
    body=body.replace('A comment starts with `#`. Python ignores everything after `#` on that line, so comments are notes for people.','Outside a string literal, `#` starts a comment that continues to the end of the line. A # inside quoted text remains part of the string. Comments are notes for people.')
    body=body.replace('Shorthand operators do the same thing:', 'For the numeric values used here, shorthand operators update the number:')
    body=body.replace('a, b = b, a      # swaps the values of a and b','a, b = 1, 2      # give both names starting values\na, b = b, a      # now a is 2 and b is 1\nprint(a, b)')
    body=body.replace('## Assignment does not link two names','## Reassignment does not change another name')
    body=body.replace('`b = a` gives `b` the value `a` had at that moment. Reassigning `a` later does not change `b`.','`b = a` makes b refer to the same current value as a. Reassigning a later does not change b. This is reassignment, not copying every object: mutable lists can be shared through two names, as topic 11 explains.')
    outputs=['Hello, World!\n','Shown\n'] if tid==1 else ['','','2 1\n','10\n']
    for code,stdout in zip(re.findall(r'```python\n(.*?)\n```',body,re.S),outputs):
        cases.append(dict(code=code,stdout=stdout,stdin='',files={},exception=None))
        body=body.replace('```python\n'+code+'\n```','```python\n'+code+'\n```\n\n'+('Expected output:\n\n```text\n'+stdout.rstrip('\n')+'\n```' if stdout else 'Expected result: no printed output.'),1)
    if tid==1:body+='\n## Common mistakes\n\n- Leaving text unquoted.\n- Changing print to Print.\n- Adding indentation where no block has begun.\n'
    body+='\n## Recap\n\n'+('Python reads statements in order after checking syntax. Use print to display values, quotes for text and comments for notes.' if tid==1 else 'Names refer to values. Evaluate the right side before assigning. Initialize both names before a swap, and distinguish reassignment from changes to a shared mutable object.')+'\n\n## Optional practice\n\nPredict a short example on paper. Any local Python practice is optional; Hunter System does not execute student code.\n'
    (directory/f'topic-{tid:02d}-reviewed.md').write_text(body,encoding='utf-8')
# Question text changes are separate proposals, retaining IDs and existing choices.
corrections=[]
for q in bank:
    if q['id'] in [102,112]:
        corrected=dict(q)
        corrected['explanation']=('Outside a string literal, # begins a comment extending to the end of that line.' if q['id']==102 else 'With its default end argument, print() adds a newline, so a call with no values prints an empty line.')
        corrections.append(corrected)
(directory/'foundation-question-corrections.json').write_text(json.dumps(corrections,indent=2)+'\n',encoding='utf-8')
for q in bank:
    if not q['code_snippet']:continue
    expected={101:'Hello, World!\n',104:'A\nC\n',105:'5\n',106:'23\n',108:'Hi there\n',111:'Line1\nLine2\n',201:'7\n',203:'10\n',205:'3 4\n',206:'15\n',207:'Ana\n',208:'2 1\n',210:'four\n',211:'15\n',212:'0 5\n',251:'6 3\n'}
    cases.append(dict(code=q['code_snippet'],stdout=expected.get(q['id'],''),stdin='',files={},exception={109:'IndentationError',209:'NameError'}.get(q['id'])))
(directory/'foundation-validation-cases.json').write_text(json.dumps(cases,indent=2)+'\n',encoding='utf-8')
print(f'Reviewed foundation sources prepared: 2 lessons, {len(bank)} existing questions (24 quiz + 2 assessment), {len(cases)} executable cases. No SQL executed.')
