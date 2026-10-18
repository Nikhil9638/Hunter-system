// Final full-content acceptance: real API/Chrome, fixed isolated fixture target.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright-core';
const API='http://127.0.0.1:8098/hunter-system/api/v1';
const BASE='http://localhost:4188/hunter-system';
const privateDir=process.env.HUNTER_FIXTURE_PRIVATE_DIR;
assert.ok(privateDir,'No default dev fallback');
const helper=(...args)=>JSON.parse(execFileSync('C:/xampp/php/php.exe',['tests/db-helper.php',...args.map(String)],{env:{...process.env,HUNTER_PRIVATE_DIR:privateDir}}));
const identity=helper('fixture-identity');
assert.deepEqual(identity,{database:'hunter_system_m3_fixture',account:'hunter_m7_fixture'});
assert.deepEqual((await fetch(API+'/__test/identity').then(r=>r.json())).data,identity);
const RUN=Date.now().toString(36), email=`m8a.${RUN}.student@example.invalid`, password='Course fixture student password';
let cookie='',csrf='',checks=0;
const check=(name,value=true)=>{assert.ok(value,name); checks++; console.log('PASS '+name);};
async function api(method,path,data) {
  const r=await fetch(API+path,{method,headers:{origin:'http://localhost',...(cookie?{cookie}:{}),...(csrf?{'X-CSRF-Token':csrf}:{}),...(data!==undefined?{'Content-Type':'application/json'}:{})},body:data===undefined?undefined:JSON.stringify(data)});
  const set=r.headers.getSetCookie(); if(set.length)cookie=set.map(s=>s.split(';')[0]).join('; ');
  const body=await r.json();assert.ok(r.ok,`${method} ${path}: ${JSON.stringify(body)}`);
  if(body.data.csrf_token)csrf=body.data.csrf_token;
  return body.data;
}
await api('POST','/auth/register',{email,password,display_name:'[FIXTURE M8A] Course progression'});
await api('PUT','/me/experience',{experience_level_id:1});
const assessment=await api('POST','/assessment',{}), akey=helper('answer-key',assessment.attempt_id);
for(const q of assessment.questions)await api('PUT',`/assessment/${assessment.attempt_id}/answers/${q.position}`,{choice_id:akey[q.position].wrong});
await api('POST',`/assessment/${assessment.attempt_id}/submit`,{});
const zeroStore=await api('GET','/badges');
assert.equal(zeroStore.length,6,'Full acceptance requires exactly six activated approved badges');
const denied=await fetch(API+`/badges/${zeroStore[0].id}/purchase`,{method:'POST',headers:{origin:'http://localhost',cookie,'X-CSRF-Token':csrf,'Content-Type':'application/json'},body:'{}'});
const denial=await denied.json();check('Zero-coin student cannot buy an unaffordable badge',denied.status===409&&denial.error.code==='insufficient_coins');
const importState=JSON.parse(readFileSync('../content/import-fixture.json'));
const report={mode:'REAL fixture API and installed Chrome; no simulated responses',status:'incomplete',student:email,pools:[],checks:0};
for(let topic=1;topic<=18;topic++) {
  const seen=new Set();
  const source=topic>=3?JSON.parse(readFileSync(`../content/topics/${String(topic).padStart(2,'0')}/questions.json`)):null;
  for(let retry=0;retry<(topic>=3?3:topic===1?2:1);retry++) {
    const attempt=await api('POST',`/topics/${topic}/quiz-attempts`,{started_via:'direct'});
    assert.equal(attempt.questions.length,5);
    const key=helper('answer-key',attempt.attempt_id);
    for(const q of attempt.questions) {
      assert.ok(!('explanation' in q)&&!('id' in q)&&q.choices.every(c=>!('is_correct'in c)&&!('correct'in c)),'No answers/source ids before submission');
      if(source) {
        const original=source.find(s=>s.stem===q.stem&&s.code_snippet===q.code_snippet);
        assert.ok(original,'Only authored new pool assigned');seen.add(original.key);
        const right=original.choices.find(c=>c.is_correct);
        assert.equal(q.choices.find(c=>c.id===key[q.position].right).text,right.text,'Imported answer matches snapshot');
      }
      await api('PUT',`/quiz-attempts/${attempt.attempt_id}/answers/${q.position}`,{choice_id:topic===1&&retry===0?key[q.position].wrong:key[q.position].right});
    }
    const result=await api('POST',`/quiz-attempts/${attempt.attempt_id}/submit`,{});
    assert.equal(result.correct_count,topic===1&&retry===0?0:5);assert.equal(result.passed,!(topic===1&&retry===0));assert.equal(result.coins_awarded,retry===0?10:0);
    if(topic===18)assert.equal(result.unlocked_topic,null);
  }
  check(`Topic ${topic}: real selection, grading, rewards and progression${source?' — all 12 questions seen':''}`,!source||seen.size===12);
  report.pools.push({topic,covered:seen.size});
}
const ranks=await api('GET','/me/rank-history');check('Final earned rank is S',ranks.at(-1).to==='S');
check('Maximum first-completion earnings are exactly180 despite failed-first quiz/retries',(await api('GET','/me/profile')).coins===180);
await api('POST','/auth/logout',{});
const preview=spawn(process.execPath,['node_modules/vite/bin/vite.js','preview','--host','localhost','--port','4188','--strictPort'],{stdio:'ignore'});
let browser;
try {
  for(let i=0;i<40;i++){try{if((await fetch(BASE+'/login')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  report.chrome=await browser.version();
  const context=await browser.newContext();
  await context.route('**/hunter-system/api/v1/**',async route=>{
    const url=new URL(route.request().url());
    const response=await route.fetch({url:API+url.pathname.split('/api/v1')[1]+url.search,headers:{...route.request().headers(),origin:'http://localhost'}});
    await route.fulfill({response});
  });
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(BASE+'/login');await page.getByLabel('Email').fill(email);await page.getByLabel('Password').fill(password);await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL(/dashboard$/);
  for(const width of [1280,390]) {
    await page.setViewportSize({width,height:900});
    for(const topic of [3,11,18]) {
      await page.goto(BASE+`/topics/${topic}/lesson`);await page.getByRole('heading',{name:'Learning objectives',exact:true}).waitFor();
      const cases=JSON.parse(readFileSync(`../content/topics/${String(topic).padStart(2,'0')}/examples.json`));
      const codes=await page.locator('pre[aria-label="python example"] code').allTextContents();
      check(`Chrome ${width}px topic ${topic}: code and indentation match private source`,JSON.stringify(codes)===JSON.stringify(cases.map(c=>c.code)));
      check(`Chrome ${width}px topic ${topic}: no page overflow`,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      await page.locator('pre').first().focus();check(`Chrome ${width}px topic ${topic}: keyboard code focus`,await page.locator('pre').first().evaluate(el=>el===document.activeElement));
    }
  }
  check('No browser script errors',errors.length===0);
  const approved=JSON.parse(readFileSync('../content/badges/manifest.json')).badges;
  const store=await page.evaluate(async()=>{const r=await fetch('/hunter-system/api/v1/badges');if(!r.ok)throw new Error('Store read failed');return(await r.json()).data;});
  assert.equal(store.length,6,'Exactly the approved six badges, no provisional samples');
  const bought=[];
  await page.goto(BASE+'/store');await page.getByRole('heading',{name:'Badge store',exact:true}).waitFor();
  for(const b of approved){
    const actual=store.find(s=>s.name===b.name);assert.ok(actual);assert.equal(actual.price,b.approved_price);
    const card=page.locator('.badge-card').filter({has:page.getByRole('heading',{name:b.name,exact:true})});
    await card.getByRole('button',{name:'Buy',exact:true}).click();await card.getByRole('button',{name:/^Spend \d+ Mana$/}).click();await card.getByText('Owned',{exact:true}).waitFor();bought.push(actual.id);
  }
  check('Real Chrome purchases all six approved badges for180 coins');
  const profile=await page.evaluate(async()=>{const r=await fetch('/hunter-system/api/v1/me/profile');return(await r.json()).data;});
  assert.equal(profile.coins,0);assert.equal(profile.owned_badges.length,6);assert.equal(profile.topics_passed,18);assert.equal(profile.hunter_rank.code,'S');
  const write=async(method,path,data)=>page.evaluate(async({method,path,data})=>{const me=await fetch('/hunter-system/api/v1/auth/me').then(r=>r.json());const r=await fetch('/hunter-system/api/v1'+path,{method,headers:{'Content-Type':'application/json','X-CSRF-Token':me.data.csrf_token},body:JSON.stringify(data)});return{status:r.status,body:await r.json()};},{method,path,data});
  const duplicate=await write('POST',`/badges/${bought[0]}/purchase`,{});check('Duplicate purchase rejected without another debit',duplicate.status===409&&duplicate.body.error.code==='already_owned');
  const tooMany=await write('PUT','/me/showcase',{badge_ids:bought.slice(0,4)});check('More than three showcase badges rejected',tooMany.status===400);
  const order=[bought[2],bought[0],bought[1]];assert.equal((await write('PUT','/me/showcase',{badge_ids:order})).status,200);
  await page.goto(BASE+'/profile');await page.locator('.showcase li').last().waitFor();
  const saved=await page.evaluate(async()=>{const r=await fetch('/hunter-system/api/v1/me/profile');return(await r.json()).data;});assert.deepEqual(saved.showcase.map(b=>b.badge_id),order);check('Three-slot reordered showcase persists');
  const ledger=await page.evaluate(async()=>{const r=await fetch('/hunter-system/api/v1/me/coins');return(await r.json()).data;});
  check('Ledger/profile agree:18 rewards, six debits, zero balance',ledger.balance===0&&ledger.entries.filter(e=>e.direction==='credit').length===18&&ledger.entries.filter(e=>e.direction==='debit').length===6&&ledger.entries.filter(e=>e.direction==='debit').reduce((sum,e)=>sum+e.amount,0)===180);
  for(const path of ['/content/manifest.json','/content/topics/03/questions.json','/content/topics/18/lesson.md','/content/tools/import_course.py','/content/import-fixture.json','/content/validation-report.json']) {
    const r=await fetch('http://localhost/hunter-system'+path);check('Apache blocks '+path,[403,404].includes(r.status));
  }
  check('No browser errors after purchases/showcase',errors.length===0);report.status='passed';
} finally {await browser?.close();preview.kill();report.checks=checks;mkdirSync('tests/results',{recursive:true});writeFileSync('tests/results/final-course-acceptance.json',JSON.stringify(report,null,2));}
console.log(`${checks} checks passed`);
