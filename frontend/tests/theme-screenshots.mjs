// Real fixture data only; immutable before/after review screenshots.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright-core';
const phase=process.argv[2]; assert.ok(['before','after'].includes(phase));
const API='http://127.0.0.1:8098/hunter-system/api/v1', BASE='http://localhost:4190/hunter-system';
const dir=process.env.HUNTER_FIXTURE_PRIVATE_DIR; assert.ok(dir);
const helper=(...args)=>JSON.parse(execFileSync('C:/xampp/php/php.exe',['frontend/tests/db-helper.php',...args],{env:{...process.env,HUNTER_PRIVATE_DIR:dir}}));
const identity=helper('fixture-identity'); assert.deepEqual(identity,{database:'hunter_system_m3_fixture',account:'hunter_m7_fixture'});
assert.deepEqual((await fetch(API+'/__test/identity').then(r=>r.json())).data,identity);
const folder='frontend/tests/results/theme'; mkdirSync(folder,{recursive:true});
const file=folder+'/identities.json'; let ids;
if(existsSync(file)) ids=JSON.parse(readFileSync(file));
else { ids={student:JSON.parse(readFileSync('frontend/tests/results/final-course-acceptance.json')).student,admin:`theme.${Date.now().toString(36)}.admin@example.invalid`}; assert.equal(helper('create-admin',ids.admin,'Theme isolated administrator password'),true); writeFileSync(file,JSON.stringify(ids,null,2)); }
const preview=spawn(process.execPath,['node_modules/vite/bin/vite.js','preview','--host','localhost','--port','4190','--strictPort'],{cwd:'frontend',stdio:'ignore'});
let browser, count=0;
try {
  for(let i=0;i<50;i++){try{if((await fetch(BASE+'/login')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  async function context(){
    const c=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    await c.route('**/hunter-system/api/v1/**',async route=>{const u=new URL(route.request().url());const response=await route.fetch({url:API+u.pathname.split('/api/v1')[1]+u.search,headers:{...route.request().headers(),origin:'http://localhost'}});await route.fulfill({response});});
    await c.route('**/hunter-system/assets/badges/**',async route=>{const response=await route.fetch({url:'http://localhost'+new URL(route.request().url()).pathname});await route.fulfill({response});});return c;
  }
  async function shot(p,name){await p.locator('h1').first().waitFor();await delay(150);await p.screenshot({path:`${folder}/${phase}-${name}.png`,fullPage:!name.endsWith('navigation')});count++;}
  async function login(p,email,password){await p.goto(BASE+'/login');await p.getByLabel('Email').fill(email);await p.getByLabel('Password').fill(password);await p.getByRole('button',{name:'Log in',exact:true}).click();await p.waitForURL(/\/(dashboard|admin)$/);await p.locator('main .card').first().waitFor();}
  const c=await context(),p=await c.newPage();await p.goto(BASE+'/login');await shot(p,'login');await login(p,ids.student,'Course fixture student password');
  for(const [name,url,ready] of [['dashboard','/dashboard','.status-window'],['lesson','/topics/3/lesson','.code-block'],['store','/store','.badge-card'],['profile','/profile','.owned-item']]){await p.goto(BASE+url);await p.locator(ready).first().waitFor();await shot(p,name);}
  if(!ids.quiz){ids.quiz=await p.evaluate(async()=>{const me=await fetch('/hunter-system/api/v1/auth/me').then(r=>r.json());const r=await fetch('/hunter-system/api/v1/topics/11/quiz-attempts',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':me.data.csrf_token},body:JSON.stringify({started_via:'direct'})});if(!r.ok)throw Error('Fixture quiz start failed');return(await r.json()).data.attempt_id;});writeFileSync(file,JSON.stringify(ids,null,2));}
  await p.goto(BASE+'/quiz/'+ids.quiz);await p.locator('.question').waitFor();await shot(p,'quiz');
  await p.setViewportSize({width:768,height:1024});await p.goto(BASE+'/dashboard');await p.locator('.status-window').first().waitFor();await shot(p,'tablet-dashboard');
  await p.setViewportSize({width:390,height:844});await p.goto(BASE+'/dashboard');await p.locator('.status-window').first().waitFor();await shot(p,'mobile-dashboard');await p.getByRole('button',{name:'Menu',exact:true}).click();await shot(p,'mobile-navigation');await p.keyboard.press('Escape');await c.close();
  const ca=await context(),a=await ca.newPage();await login(a,ids.admin,'Theme isolated administrator password');
  for(const [name,url,ready] of [['admin-overview','/admin','.grid-3'],['admin-table','/admin/questions','.admin-table'],['admin-editor','/admin/topics/18/lesson','textarea'],['admin-audit','/admin/audit','article']]){await a.goto(BASE+url);await a.locator(ready).first().waitFor();await shot(a,name);}
  await a.setViewportSize({width:390,height:844});await a.goto(BASE+'/admin/students');await a.locator('.admin-table').waitFor();await shot(a,'mobile-admin-table');await a.goto(BASE+'/admin/topics/18/lesson');await a.locator('textarea').waitFor();await shot(a,'mobile-admin-editor');await a.getByRole('button',{name:'Menu',exact:true}).click();await shot(a,'mobile-admin-navigation');await ca.close();
  console.log(`${phase}: ${count} real-fixture screenshots captured; no dev writes.`);
} finally {await browser?.close();preview.kill();}
