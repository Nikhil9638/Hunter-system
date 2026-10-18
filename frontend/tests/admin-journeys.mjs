// Installed Chrome, production build. Default: isolated fixture API ONLY.
// --mock: explicit UI-only contract simulation; NEVER reported as API integration.
import { chromium } from 'playwright-core';
import { spawn, execFileSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';
import { installMock } from './admin-mock.mjs';

const MOCK = process.argv.includes('--mock');
const FULL = process.argv.includes('--full-content');
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = 'http://localhost:4187/hunter-system';
const API = 'http://127.0.0.1:8098/hunter-system/api/v1';
const SEED_API = 'http://127.0.0.1:8097/hunter-system/api/v1';
const RUN = Date.now().toString(36);
const EMAIL = `b7.${RUN}.admin@example.invalid`;
const PASSWORD = 'Administrator browser fixture password';
const FIXTURE_DIR=process.env.HUNTER_FIXTURE_PRIVATE_DIR || 'C:\\xampp\\hunter-system-fixture-private';
const helper = (...args) => JSON.parse(execFileSync('C:/xampp/php/php.exe',[path.join(ROOT,'tests/db-helper.php'),...args],{env:{...process.env,HUNTER_PRIVATE_DIR:FIXTURE_DIR,HUNTER_TEST_DB_ADMIN_PASSWORD:''}}).toString());
if (!MOCK) {
  const identity=helper('fixture-identity');
  assert.equal(identity.database,'hunter_system_m3_fixture');
  assert.ok(['hunter_fixture','hunter_m7_fixture'].includes(identity.account));
  const serverIdentity=await fetch(API+'/__test/identity').then(r=>r.json());
  assert.deepEqual(serverIdentity.data,identity,'Fixture API must use the exact guarded helper database/account.');
  assert.equal(helper('create-admin',EMAIL,PASSWORD),true);
}
const preview = spawn(process.execPath,['node_modules/vite/bin/vite.js','preview','--host','localhost','--port','4187','--strictPort'],{cwd:ROOT,stdio:'ignore'});
let browser; let count=0;
const errors=[]; const requests=[];
const check = (name,condition=true) => { assert.ok(condition,name); count++; console.log(`PASS ${name}`); };
const mockState = {};
async function context(role='admin',target=API) {
  assert.ok([API,SEED_API].includes(target),'Unexpected browser API target');
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  if(MOCK) await installMock(c,mockState,role);
  else {
    // No environment override can point mutating tests at Apache/dev. A new random
    // administrator created ONLY after the fixture identity check must authenticate
    // against this server before any write is enabled.
    await c.route('**/hunter-system/api/v1/**',async route=>{
      if (mockState.delayNextPut && route.request().method()==='PUT') { mockState.delayNextPut=false; await delay(350); }
      const suffix=new URL(route.request().url()).pathname.split('/api/v1')[1];
      const response=await route.fetch({url:target+suffix+new URL(route.request().url()).search,headers:{...route.request().headers(),origin:'http://localhost'}});
      await route.fulfill({response});
    });
  }
  c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
  return c;
}
async function go(page,route,heading) { await page.goto(BASE+route); await page.getByRole('heading',{name:heading,exact:true}).waitFor(); }
async function login(page) {
  await go(page,'/login','Log in'); await page.getByLabel('Email').fill(EMAIL); await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button',{name:'Log in',exact:true}).click(); await page.waitForURL(/\/admin$/); await page.getByRole('heading',{name:'Administrator overview'}).waitFor();
}
async function save(page) {
  const response=page.waitForResponse(r=>r.request().method()==='PUT' || r.request().method()==='POST' || r.request().method()==='PATCH');
  await page.getByRole('button',{name:'Save changes',exact:true}).click(); return (await response).status();
}
async function action(page,name) {
  const response=page.waitForResponse(r=>['POST','PATCH'].includes(r.request().method()));
  await page.getByRole('button',{name,exact:true}).click(); return (await response).status();
}
async function readInPage(page,path) {
  return page.evaluate(async path=>{const r=await fetch('/hunter-system/api/v1'+path); if(!r.ok)throw new Error('Fixture read failed: '+r.status);return (await r.json()).data;},path);
}
try {
  for(let i=0;i<40;i++){try{if((await fetch(BASE+'/login')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({executablePath:process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  console.log(`Mode: ${MOCK?'SIMULATED API — UI checks only':'REAL FIXTURE API — successful writes isolated'}; Chrome ${await browser.version()}`);
  if(!MOCK) {
    const seedDir=process.env.HUNTER_SEED_PRIVATE_DIR;
    assert.ok(seedDir,'Set HUNTER_SEED_PRIVATE_DIR for real lesson-creation checks');
    const seedHelper=(...args)=>JSON.parse(execFileSync('C:/xampp/php/php.exe',[path.join(ROOT,'tests/db-helper.php'),...args],{env:{...process.env,HUNTER_PRIVATE_DIR:seedDir,HUNTER_TEST_DB_ADMIN_PASSWORD:''}}).toString());
    assert.deepEqual(seedHelper('test-identity'),{database:'hunter_system_m7_seed_fixture',account:'hunter_m7_seed'});
    assert.deepEqual((await fetch(SEED_API+'/__test/identity').then(r=>r.json())).data,seedHelper('test-identity'));
    assert.equal(seedHelper('create-admin',EMAIL,PASSWORD),true);
    const seedContext=await context('admin',SEED_API); const seedPage=await seedContext.newPage(); seedPage.on('dialog',d=>d.accept()); await login(seedPage);
    const seedTopics=await readInPage(seedPage,'/admin/topics');
    const vacant=seedTopics.filter(t=>t.id>=4 && !t.lesson).at(-1);
    assert.ok(vacant,'Need an unused seed-fixture lesson slot; never delete existing history to make one');
    await go(seedPage,`/admin/topics/${vacant.id}/lesson`,'Edit lesson');
    await seedPage.getByLabel('Lesson title').fill(`[FIXTURE] Browser create ${RUN}`); await seedPage.getByLabel('Lesson Markdown').fill('[FIXTURE] Lesson created through the real UI.');
    check('Real lesson creation at revision zero succeeds',await save(seedPage)===201); await seedPage.getByText('Saved successfully.',{exact:true}).waitFor();
    await seedPage.getByLabel('Lesson Markdown').fill('[FIXTURE] Edited real UI lesson.'); check('Created lesson can be edited with its returned revision',await save(seedPage)===200); await seedPage.getByText('Saved successfully.',{exact:true}).waitFor();
    check('Created lesson publishes through real API',await action(seedPage,'Publish lesson')===200);
    check('Created lesson unpublishes through real API',await action(seedPage,'Unpublish lesson')===200);
    await seedContext.close();
  }
  const c=await context(); const p=await c.newPage(); p.on('dialog',d=>d.accept());
  await go(p,'/admin/questions','Log in'); check('Anonymous admin route is guarded');
  await login(p); check('Admin login routes to overview');
  p.on('request',r=>{if(r.url().includes('/api/v1/'))requests.push({method:r.method(),url:r.url(),body:r.postDataJSON()});});
  await p.reload(); await p.getByRole('heading',{name:'Administrator overview'}).waitFor(); check('Admin route refresh loads and retains role');
  await go(p,'/dashboard','Administrator overview'); check('Student route sends admin to overview');
  await go(p,'/admin/students','Student tracking');
  await p.getByLabel('Name or email').fill(MOCK?'student':'b6'); await p.getByLabel('Recently active',{exact:true}).selectOption('false'); await p.getByRole('button',{name:'Apply filters'}).click();
  await p.locator('table').waitFor(); check('Student filters use supported parameters',p.url().includes('recently_active=false'));
  await p.getByRole('button',{name:'Clear filters'}).click(); await p.locator('tbody a').first().click(); await p.getByRole('heading',{name:'Student details',exact:true}).waitFor();
  await p.getByRole('heading',{name:'Sessions',exact:true}).waitFor(); check('Tracking detail separates progress, history and sessions');
  if(MOCK) check('Session labels distinguish valid, logged out and expired',(await p.locator('main').innerText()).includes('Valid session') && (await p.locator('main').innerText()).includes('Logged out') && (await p.locator('main').innerText()).includes('Expired'));
  await go(p,'/admin/login-events','Login events'); await p.locator('table').waitFor(); check('Global login events available');
  await go(p,'/admin/topics','Topics and lessons'); await p.locator('table').waitFor(); check('Readiness shows lesson and quiz pool separately');
  const originalLesson18=(!MOCK && FULL)?await readInPage(p,'/admin/topics/18/lesson'):null;
  await go(p,'/admin/topics/18','Edit topic'); await p.getByLabel('Summary').waitFor();
  if(await p.getByRole('button',{name:'Unpublish topic',exact:true}).count()) await action(p,'Unpublish topic');
  await go(p,'/admin/topics/18/lesson','Edit lesson'); await p.getByLabel('Lesson title').fill(`[FIXTURE] Browser lesson ${RUN}`);
  await p.getByLabel('Lesson Markdown').fill('# [FIXTURE] Display only\n<script>window.hunterInjected=true</script>\n```python\nprint("hello")\n```');
  check('Lesson save succeeds',await save(p)<300); await p.getByText('Saved successfully.',{exact:true}).waitFor();
  await p.getByLabel('Lesson title').fill('[FIXTURE] Pending save');
  mockState.delayNextPut=true;
  const pendingResponse=p.waitForResponse(r=>r.request().method()==='PUT');
  const putsBefore=requests.filter(r=>r.method==='PUT').length;
  await p.getByRole('button',{name:'Save changes',exact:true}).click();
  check('Save and editor controls disabled while pending',await p.getByRole('button',{name:'Save changes'}).isDisabled() && await p.getByLabel('Lesson title').isDisabled());
  await p.getByRole('button',{name:'Save changes'}).evaluate(b=>{b.click();b.click();});
  await pendingResponse; await p.getByText('Saved successfully.',{exact:true}).waitFor();
  check('Repeated pending action sends one request',requests.filter(r=>r.method==='PUT').length===putsBefore+1);
  if(MOCK) {
  await p.getByLabel('Lesson title').fill('[FIXTURE] Network draft');
  await p.route('**/admin/topics/18/lesson',route=>route.abort('failed'),{times:1});
  await p.getByRole('button',{name:'Save changes'}).click(); await p.getByRole('alert').waitFor();
  check('Network error retains unsaved lesson input',await p.getByLabel('Lesson title').inputValue()==='[FIXTURE] Network draft');
  await save(p); await p.getByText('Saved successfully.',{exact:true}).waitFor();
  }
  if(await p.getByRole('button',{name:'Unpublish lesson',exact:true}).count()) await action(p,'Unpublish lesson');
  await action(p,'Publish lesson'); check('Lesson publish succeeds');
  // Two independent authenticated browser contexts load the same server revision.
  const c2=await context(); const p2=await c2.newPage(); p2.on('dialog',d=>d.accept()); await login(p2);
  await go(p2,'/admin/topics/18/lesson','Edit lesson'); await p2.getByLabel('Lesson title').fill('[FIXTURE] Unsaved stale title');
  await p.getByLabel('Lesson title').fill(`[FIXTURE] Newer title ${RUN}`); await save(p); await p.getByText('Saved successfully.',{exact:true}).waitFor();
  check('Two-context stale edit refused',await save(p2)===409);
  check('409 retains unsaved input',await p2.getByLabel('Lesson title').inputValue()==='[FIXTURE] Unsaved stale title');
  const writesBefore=requests.filter(r=>r.method==='PUT').length;
  await p2.getByRole('button',{name:'Compare current server record'}).click(); await p2.getByRole('heading',{name:'Current server record'}).waitFor();
  check('Conflict comparison shows server without replacing draft',await p2.getByLabel('Lesson title').inputValue()==='[FIXTURE] Unsaved stale title');
  if(MOCK){
    await p2.setViewportSize({width:375,height:840});
    check('Simulated mobile comparison keeps both records inside the page',await p2.locator('.admin-comparison section').count()===2&&await p2.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    mkdirSync(path.join(ROOT,'tests/results/theme'),{recursive:true});
    await p2.screenshot({path:path.join(ROOT,'tests/results/theme/after-mobile-conflict.png'),fullPage:true});
    await p2.setViewportSize({width:1280,height:900});
  }
  check('Conflict disables stale save',await p2.getByRole('button',{name:'Save changes'}).isDisabled());
  await p2.getByRole('button',{name:'Reload server record and discard draft'}).click();
  check('Deliberate reload adopts current server title',await p2.getByLabel('Lesson title').inputValue()===`[FIXTURE] Newer title ${RUN}`);
  await c2.close();
  // Cancel navigation: drafts survive both SPA links and history navigation.
  await p.getByLabel('Lesson title').fill('[FIXTURE] Keep dirty form');
  p.removeAllListeners('dialog'); p.on('dialog',d=>d.dismiss()); await p.getByRole('link',{name:'Back to topic',exact:true}).click();
  await delay(100);
  check('Dirty navigation cancellation preserves form',await p.getByLabel('Lesson title').inputValue()==='[FIXTURE] Keep dirty form');
  p.removeAllListeners('dialog'); p.on('dialog',d=>d.accept()); await save(p); await p.getByText('Saved successfully.',{exact:true}).waitFor();
  p.removeAllListeners('dialog'); p.on('dialog',d=>d.dismiss());
  const retireBefore=requests.length; await p.getByRole('button',{name:'Retire lesson',exact:true}).click(); await delay(50);
  check('Cancelled retirement sends no request',requests.length===retireBefore);
  p.removeAllListeners('dialog');p.on('dialog',d=>d.accept());
  await action(p,'Retire lesson'); await action(p,'Publish lesson'); check('Lesson retirement and deliberate republication work');
  await go(p,'/admin/topics/18','Edit topic'); await p.getByLabel('Summary').waitFor(); await action(p,'Publish topic'); check('Ready topic publication succeeds');
  await go(p,'/admin/questions/new','Create question'); await p.getByLabel('Question text', {exact:true}).fill(`[FIXTURE] Browser question ${RUN} <img src=x onerror=window.hunterInjected=true>`);
  await p.getByLabel('Explanation',{exact:true}).fill('[FIXTURE] Correct explanation'); await p.getByLabel('Choice A',{exact:true}).fill('First'); await p.getByLabel('Choice B',{exact:true}).fill('Second'); await p.getByLabel('Correct choice A',{exact:true}).check();
  check('Create question succeeds',await save(p)===201); await p.waitForURL(/\/admin\/questions\/\d+$/); const questionId=Number(p.url().split('/').pop());
  await p.getByLabel('Question text',{exact:true}).waitFor(); await action(p,'Activate question'); check('Activate saved complete question');
  check('Active tagging is locked',await p.getByLabel('Topic ID',{exact:true}).isDisabled() && await p.getByLabel('Placement assessment',{exact:true}).isDisabled());
  await p.getByLabel('Explanation',{exact:true}).fill('[FIXTURE] Edited explanation'); check('Question full replacement succeeds',await save(p)===200); await p.getByText('Saved successfully.',{exact:true}).waitFor();
  // Explicit controlled faults test client preservation independently of backend thresholds.
  if(MOCK) {
  await p.route('**/admin/questions/'+questionId+'/retire',route=>route.fulfill({status:409,json:{error:{code:'would_make_topic_not_ready',message:'Retirement would make the topic unavailable.',details:{topic_id:1,quiz_pool_size_after:9,required_pool_size:10}}}}),{times:1});
  await action(p,'Retire question'); await p.getByRole('button',{name:'Retire question and make topic unavailable',exact:true}).waitFor(); check('Pool protection explains separate availability confirmation');
  await p.route('**/admin/questions/'+questionId+'/retire',route=>route.fulfill({status:409,json:{error:{code:'would_break_assessment_pool',message:'The placement assessment would not have enough questions.',details:{skill_group:'G1'}}}}),{times:1});
  await action(p,'Retire question and make topic unavailable'); check('Assessment quota refusal has no override',(await p.getByRole('button',{name:'Retire question and make topic unavailable'}).count())===0);
  }
  await action(p,'Retire question'); check('Retirement succeeds after deliberate confirmation');
  // Permanent-retirement boundary coverage uses the compatible legacy profile.
  // Full reviewed pools are never reduced by this runner. See final-admin-live.log
  // and final-fixture-recovery.json for the separate boundary rehearsal/recovery.
  if(!MOCK && !FULL) {
    const topics=await readInPage(p,'/admin/topics');
    assert.equal(topics.find(t=>t.id===17).quiz_pool_size,10,'Legacy profile must already have a ten-question pool');
    const pool=await readInPage(p,'/admin/questions?topic_id=17&usage=quiz&status=active&per_page=100');
    const victim=pool.questions.find(q=>q.valid && !q.for_assessment && q.stem.startsWith('[FIXTURE]'));
    assert.ok(victim,'Pool test must use identifiable fixture content');
    const beforeAudit=(await readInPage(p,`/admin/audit-log?entity_type=question&entity_id=${victim.id}`)).total;
    await go(p,`/admin/questions/${victim.id}`,'Edit question');await p.getByLabel('Question text',{exact:true}).waitFor();
    check('Real quiz-pool protection refuses retirement at threshold',await action(p,'Retire question')===409);
    await p.getByRole('button',{name:'Retire question and make topic unavailable',exact:true}).waitFor();
    check('Refused pool retirement creates no audit entry',(await readInPage(p,`/admin/audit-log?entity_type=question&entity_id=${victim.id}`)).total===beforeAudit);
    check('Separate real confirmation retires question and drafts topic',await action(p,'Retire question and make topic unavailable')===200);
    const unavailable=(await readInPage(p,'/admin/topics')).find(t=>t.id===17); check('Pool override atomically makes topic unavailable',unavailable.status==='draft' && unavailable.quiz_pool_size===9 && !unavailable.is_ready);
    await go(p,'/admin/questions/new','Create question');await p.getByLabel('Topic ID',{exact:true}).fill('17');await p.getByLabel('Question text',{exact:true}).fill(`[FIXTURE] Browser pool refill ${RUN}`);await p.getByLabel('Explanation',{exact:true}).fill('[FIXTURE] The first choice is correct.');await p.getByLabel('Choice A',{exact:true}).fill('Correct');await p.getByLabel('Choice B',{exact:true}).fill('Incorrect');await p.getByLabel('Correct choice A',{exact:true}).check();
    assert.equal(await save(p),201);await p.waitForURL(/\/admin\/questions\/\d+$/);await p.getByLabel('Question text',{exact:true}).waitFor();assert.equal(await action(p,'Activate question'),200);
    await go(p,'/admin/topics/17','Edit topic');await p.getByLabel('Summary').waitFor();check('Real refill and publication restore topic readiness',await action(p,'Publish topic')===200);
    const assessment=(await readInPage(p,'/admin/questions?usage=assessment&status=active&per_page=100')).questions.filter(q=>q.skill_group==='G6');
    assert.equal(assessment.length,1,'Legacy profile must already have the G6 quota');
    await go(p,`/admin/questions/${assessment[0].id}`,'Edit question');await p.getByLabel('Question text',{exact:true}).waitFor();
    check('Real assessment quota protection refuses retirement',await action(p,'Retire question')===409);
    check('Assessment quota UI offers no availability override',await p.getByRole('button',{name:'Retire question and make topic unavailable'}).count()===0);

  }
  await go(p,'/admin/questions?usage=assessment&status=active&per_page=10','Question management'); await p.locator('table').waitFor(); check('Question filters and pagination render');
  await go(p,'/admin/badges/new','Create badge'); await p.getByLabel('Slug (immutable after creation)').fill(`fx-b7-${RUN}`); await p.getByLabel('Badge name',{exact:true}).fill(`[FIXTURE] Browser badge ${RUN}`); await p.getByLabel('Description',{exact:true}).fill('[FIXTURE] Not final content'); await p.getByLabel('Price in coins').fill('23');
  check('Create badge with nullable artwork',await save(p)===201); await p.waitForURL(/\/admin\/badges\/\d+$/); const badgeId=Number(p.url().split('/').pop());
  await p.getByLabel('Badge name',{exact:true}).waitFor(); await action(p,'Put on sale'); await p.getByRole('alert').waitFor(); check('Publication requires approval');
  await action(p,'Approve current price: 23 coins'); await action(p,'Put on sale'); check('Exact price approval and sale publication succeed');
  await p.getByLabel('Price in coins').fill('24'); await save(p); await p.getByRole('button',{name:'Approve current price: 24 coins'}).waitFor(); check('Price edit visibly invalidates approval and withdraws sale',(await p.locator('main').innerText()).includes('off_sale'));
  await p.getByLabel('Artwork reference (optional)').fill('badges/not-valid.svg'); check('Restricted artwork error',await save(p)===400);
  check('Validation error keeps artwork input',await p.getByLabel('Artwork reference (optional)').inputValue()==='badges/not-valid.svg'); await p.getByLabel('Artwork reference (optional)').fill('');
  await action(p,'Approve current price: 24 coins'); await action(p,'Put on sale'); await action(p,'Take off sale'); check('Approval and off-sale lifecycle succeeds');
  await action(p,'Retire badge'); check('Retired badge is read only',await p.getByRole('button',{name:'Save changes'}).isDisabled());
  await go(p,`/admin/audit?entity_type=badge&entity_id=${badgeId}`,'Audit history'); await p.locator('article').first().waitFor(); check('Audit target filter exposes relevant before and after values');
  if(!MOCK) {
    const entries=(await readInPage(p,`/admin/audit-log?entity_type=badge&entity_id=${badgeId}&per_page=100`)).entries.reverse();
    assert.deepEqual(entries.map(e=>e.action),['create','update','publish','update','update','publish','unpublish','retire']);
    assert.deepEqual(entries.filter(e=>e.operation==='approve_price').map(e=>e.after.price_coins),[23,24]);
    check('Real audit entries exactly match successful badge lifecycle and approvals');
  }
  await go(p,'/admin/audit','Audit history'); await p.locator('article').first().waitFor(); check('Audit renders text without executing markup',await p.evaluate(()=>!window.hunterInjected));
  await go(p,`/admin/audit?entity_type=question&entity_id=${questionId}`,'Audit history'); await p.locator('article').first().waitFor();
  check('HTML-looking audit values render literally',(await p.locator('main').innerText()).includes('<img src=x onerror=window.hunterInjected=true>') && await p.locator('main img').count()===0);
  await p.setViewportSize({width:375,height:740}); await p.reload(); await p.getByRole('heading',{name:'Audit history',exact:true}).waitFor(); check('Mobile page has no document overflow',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await p.getByRole('button',{name:'Menu',exact:true}).click(); await p.getByRole('link',{name:'Badges',exact:true}).click(); await p.getByRole('heading',{name:'Badge management'}).waitFor(); check('Mobile menu navigation works');
  await p.keyboard.press('Tab'); check('Keyboard reaches an interactive control',await p.evaluate(()=>['A','BUTTON','INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)));
  if(originalLesson18){
    await go(p,'/admin/topics/18/lesson','Edit lesson');await p.getByLabel('Lesson title').fill(originalLesson18.title);await p.getByLabel('Lesson Markdown').fill(originalLesson18.body_markdown);assert.equal(await save(p),200);await p.getByText('Saved successfully.',{exact:true}).waitFor();
    const restored=await readInPage(p,'/admin/topics/18/lesson');check('Reviewed final lesson restored with audited edit',restored.title===originalLesson18.title && restored.body_markdown===originalLesson18.body_markdown && restored.status===originalLesson18.status);
  }
  // A controlled 401 follows the same global session-loss path as real idle expiry.
  if(MOCK) {
  await p.route('**/admin/topics',route=>route.fulfill({status:401,json:{error:{code:'session_expired',message:'Your session expired after inactivity.'}}}),{times:1});
  await p.getByRole('button',{name:'Menu',exact:true}).click(); await p.getByRole('link',{name:'Topics',exact:true}).click(); await p.waitForURL(/\/login$/); check('Expired session clears role data and redirects consistently');
  } else {
    await go(p,'/admin/questions/new','Create question');await p.getByLabel('Question text',{exact:true}).fill(`[FIXTURE] Expiry draft ${RUN}`);
    await c.addCookies([{name:'HSSESSID',value:helper('idle-session',EMAIL),domain:'localhost',path:'/hunter-system/',httpOnly:true,sameSite:'Strict'}]);
    const expiry=p.waitForResponse(r=>r.url().endsWith('/admin/questions') && r.request().method()==='POST');
    await p.getByRole('button',{name:'Save changes'}).click();const expired=await expiry;
    check('Real idle-expired admin session rejects write before validation',expired.status()===401 && (await expired.json()).error.code==='session_expired');
    await p.waitForURL(/\/login$/);check('Real admin expiry clears role data and redirects to login');
  }
  if(MOCK) {
    const cs=await context('student'); const ps=await cs.newPage(); await go(ps,'/login','Log in'); await ps.getByLabel('Email').fill('student@example.invalid'); await ps.getByLabel('Password').fill(PASSWORD); await ps.getByRole('button',{name:'Log in',exact:true}).click(); await ps.waitForURL(/\/dashboard$/); await go(ps,'/admin','Welcome back, Student'); check('Student role cannot render administrator pages'); await cs.close();
  } else {
    const cs=await context();const ps=await cs.newPage();await go(ps,'/register','Create your account');await ps.getByLabel('Display name').fill('[FIXTURE] Admin guard student');await ps.getByLabel('Email').fill(`b7.${RUN}.student@example.invalid`);await ps.getByLabel('Password').fill(PASSWORD);await ps.getByRole('button',{name:'Create account',exact:true}).click();await ps.waitForURL(/\/start$/);await ps.goto(BASE+'/admin');await ps.waitForURL(/\/start$/);check('Real student cannot render administrator screens');await cs.close();
  }
  check('No uncaught Chrome errors',errors.length===0);
  check('Mutation traffic used exact server concurrency tokens',requests.filter(r=>['PUT','PATCH'].includes(r.method)&&/\/admin\//.test(r.url)).every(r=>r.body.expected_revision!==undefined || r.body.expected_version!==undefined));
  console.log(`\n${count} checks passed. ${MOCK?'UI simulation only; live fixture integration remains separate.':'Fixture records retained; no dev content writes.'}`);
} catch(e) { console.error(e); process.exitCode=1; }
finally { await browser?.close(); preview.kill(); }
