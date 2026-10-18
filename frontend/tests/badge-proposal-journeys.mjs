// M8B real fixture lifecycle. Reuses an existing FIXTURE actor; creates no admin.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright-core';
const API='http://127.0.0.1:8098/hunter-system/api/v1',BASE='http://localhost:4189/hunter-system';
const privateDir=process.env.HUNTER_FIXTURE_PRIVATE_DIR;assert.ok(privateDir,'Explicit fixture configuration required');
const env={...process.env,HUNTER_PRIVATE_DIR:privateDir};
const info=JSON.parse(execFileSync('C:/xampp/php/php.exe',['../content/badges/fixture_state.php','identity'],{env}));
assert.deepEqual((await fetch(API+'/__test/identity').then(r=>r.json())).data,{database:info.database,account:info.account});
assert.equal(info.database,'hunter_system_m3_fixture');assert.equal(info.account,'hunter_m7_fixture');
const manifest=JSON.parse(readFileSync('../content/badges/manifest.json'));
assert.equal(manifest.badges.reduce((sum,b)=>sum+b.approved_price,0),180,'Approved prices total exactly180');
class Client {
  cookie='';csrf='';
  async req(method,path,data,expected=200){
    const r=await fetch(API+path,{method,headers:{origin:'http://localhost',...(this.cookie?{cookie:this.cookie}:{}),...(this.csrf?{'X-CSRF-Token':this.csrf}:{}),...(data!==undefined?{'Content-Type':'application/json'}:{})},body:data===undefined?undefined:JSON.stringify(data)});
    const cookies=r.headers.getSetCookie();if(cookies.length)this.cookie=cookies.map(c=>c.split(';')[0]).join('; ');
    const body=await r.json();assert.equal(r.status,expected,`${method} ${path}: ${JSON.stringify(body)}`);
    if(body.data?.csrf_token)this.csrf=body.data.csrf_token;return body.data??body;
  }
}
let checks=0;const check=(name,condition=true)=>{assert.ok(condition,name);checks++;console.log('PASS '+name);};
const admin=new Client();await admin.req('POST','/auth/login',{email:info.fixture_admin_email,password:'Administrator browser fixture password'});
const me=await admin.req('GET','/auth/me');assert.equal(me.user.role,'admin');
const run=Date.now().toString(36),created=[];
const report={mode:'REAL fixture API/Chrome, no mocks',actor:{id:me.user.id,email:info.fixture_admin_email},badges:[],checks:0};
const current=async id=>(await admin.req('GET','/admin/badges')).find(b=>b.id===id);
for(const b of manifest.badges){
  const result=await admin.req('POST','/admin/badges',{slug:`fx-m8b-${run}-${b.proposal_key}`,name:b.name,description:b.description,image_ref:b.image_ref,price:b.proposed_price},201);
  const id=result.id;assert.ok(id);created.push(id);let record=await current(id);
  check(`${b.name}: draft with provisional proposal price`,record.status==='draft'&&record.price_status==='provisional');
  await admin.req('POST',`/admin/badges/${id}/status`,{expected_version:record.version,status:'on_sale'},409);
  await admin.req('POST',`/admin/badges/${id}/approve-price`,{expected_version:record.version,price:b.proposed_price});record=await current(id);
  await admin.req('POST',`/admin/badges/${id}/status`,{expected_version:record.version,status:'on_sale'});
  check(`${b.name}: exact-price approval and fixture sale`,(await current(id)).status==='on_sale');report.badges.push({id,name:b.name,price:b.proposed_price});
}
let changed=await current(created[0]);await admin.req('PUT',`/admin/badges/${changed.id}`,{expected_version:changed.version,price:11});changed=await current(changed.id);
check('Price change invalidates approval and takes badge off sale',changed.price_status==='provisional'&&changed.status==='off_sale');
await admin.req('PUT',`/admin/badges/${changed.id}`,{expected_version:changed.version,price:10});changed=await current(changed.id);
await admin.req('POST',`/admin/badges/${changed.id}/approve-price`,{expected_version:changed.version,price:10});changed=await current(changed.id);
await admin.req('POST',`/admin/badges/${changed.id}/status`,{expected_version:changed.version,status:'on_sale'});
const student=new Client(),email=`m8b.${run}.student@example.invalid`,password='Badge proposal fixture student password';
await student.req('POST','/auth/register',{email,password,display_name:'[FIXTURE M8B] Badge review'},201);
await student.req('PUT','/me/experience',{experience_level_id:1});
const helper=id=>JSON.parse(execFileSync('C:/xampp/php/php.exe',['tests/db-helper.php','answer-key',String(id)],{env}));
const assessment=await student.req('POST','/assessment',{},201),key=helper(assessment.attempt_id);
for(const q of assessment.questions)await student.req('PUT',`/assessment/${assessment.attempt_id}/answers/${q.position}`,{choice_id:key[q.position].wrong});
await student.req('POST',`/assessment/${assessment.attempt_id}/submit`,{});
for(let topic=1;topic<=6;topic++){
  const attempt=await student.req('POST',`/topics/${topic}/quiz-attempts`,{started_via:'direct'},201),answers=helper(attempt.attempt_id);
  for(const q of attempt.questions)await student.req('PUT',`/quiz-attempts/${attempt.attempt_id}/answers/${q.position}`,{choice_id:answers[q.position].right});
  await student.req('POST',`/quiz-attempts/${attempt.attempt_id}/submit`,{});
}
const before=await student.req('GET','/me/profile');check('Fixture coins earned through unchanged quiz rewards',before.coins===60);
const preview=spawn(process.execPath,['node_modules/vite/bin/vite.js','preview','--host','localhost','--port','4189','--strictPort'],{stdio:'ignore'});let browser;
try{
  for(let i=0;i<40;i++){try{if((await fetch(BASE+'/login')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});report.chrome=await browser.version();
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  await context.route('**/hunter-system/api/v1/**',async route=>{const url=new URL(route.request().url());const response=await route.fetch({url:API+url.pathname.split('/api/v1')[1]+url.search,headers:{...route.request().headers(),origin:'http://localhost'}});await route.fulfill({response});});
  await context.route('**/hunter-system/assets/badges/**',async route=>{const response=await route.fetch({url:'http://localhost'+new URL(route.request().url()).pathname});await route.fulfill({response});});
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(BASE+'/login');await page.getByLabel('Email').fill(email);await page.getByLabel('Password').fill(password);await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL(/dashboard$/);
  for(const width of [1280,390]){
    await page.setViewportSize({width,height:900});await page.goto(BASE+'/store');await page.getByRole('heading',{name:'Badge store',exact:true}).waitFor();
    for(const b of manifest.badges){const card=page.locator('.badge-card').filter({has:page.getByRole('heading',{name:b.name,exact:true})});await card.scrollIntoViewIfNeeded();await card.locator('img').evaluate(img=>img.decode());check(`${width}px store ${b.name}: real 64px artwork`,await card.locator('img').evaluate(i=>i.naturalWidth===512&&i.getBoundingClientRect().width===64));}
    check(`${width}px store no page overflow`,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`../content/badges/store-${width}.png`,fullPage:true});
  }
  for(const b of manifest.badges.slice(0,3)){
    const card=page.locator('.badge-card').filter({has:page.getByRole('heading',{name:b.name,exact:true})});await card.getByRole('button',{name:'Buy',exact:true}).click();await card.getByRole('button',{name:'Confirm',exact:true}).click();await card.getByText('Owned',{exact:true}).waitFor();check(`${b.name}: real browser purchase confirmed`);
  }
  await page.goto(BASE+'/profile');await page.getByRole('heading',{name:'Your badges',exact:true}).waitFor();
  for(const name of manifest.badges.slice(0,3).map(b=>b.name))await page.locator('.owned-item').filter({hasText:name}).getByRole('checkbox').check();
  await page.getByRole('button',{name:'Save showcase',exact:true}).click();await page.getByText('Showcase saved.',{exact:true}).waitFor();
  for(const width of [1280,390]){
    await page.setViewportSize({width,height:900});await page.reload();await page.locator('.showcase img').last().waitFor();
    check(`${width}px profile: three real showcase images`,await page.locator('.showcase img').evaluateAll(imgs=>imgs.length===3&&imgs.every(i=>i.naturalWidth===512)));
    check(`${width}px profile no overflow`,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`../content/badges/profile-${width}.png`,fullPage:true});
  }
  const after=await student.req('GET','/me/profile');check('Purchases debit approved 45 coins and preserve rank/progress',after.coins===15&&after.topics_passed===before.topics_passed&&JSON.stringify(after.hunter_rank)===JSON.stringify(before.hunter_rank));
  assert.deepEqual(after.showcase.map(b=>b.badge_id),created.slice(0,3));check('Ordered showcase persisted through real API');
  for(const id of created){const audit=await admin.req('GET',`/admin/audit-log?entity_type=badge&entity_id=${id}&per_page=100`);check(`Badge ${id}: real create/approval/sale audit`,audit.entries.some(e=>e.action==='create')&&audit.entries.some(e=>e.after?.operation==='approve_price')&&audit.entries.some(e=>e.action==='publish'));}
  check('No browser script errors',errors.length===0);
  for(const b of manifest.badges){const r=await fetch('http://localhost/hunter-system/assets/'+b.image_ref);check(`${b.name}: Apache PNG served`,r.status===200&&r.headers.get('content-type')?.includes('image/png'));}
  for(const file of ['manifest.json','review.html','sources/first-spark.svg','fixture-baseline.json']){const r=await fetch('http://localhost/hunter-system/content/badges/'+file);check(`Private ${file} blocked`,[403,404].includes(r.status));}
}finally{
  await browser?.close();preview.kill();
  // Leave clearly labelled fixture catalogue OFF SALE; retain ownership and audit.
  for(const id of created){const b=await current(id);if(b.status==='on_sale')await admin.req('POST',`/admin/badges/${id}/status`,{expected_version:b.version,status:'off_sale'});}
  report.checks=checks;report.student=email;writeFileSync('../content/badges/lifecycle-validation.json',JSON.stringify(report,null,2)+'\n');
}
await admin.req('POST','/auth/logout',{});await student.req('POST','/auth/logout',{});console.log(`${checks} checks passed`);
