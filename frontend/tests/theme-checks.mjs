// Real fixture records + explicitly labelled transport/canvas/visibility fault injection.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright-core';
const API='http://127.0.0.1:8098/hunter-system/api/v1', BASE='http://localhost:4191/hunter-system';
assert.deepEqual((await fetch(API+'/__test/identity').then(r=>r.json())).data,{database:'hunter_system_m3_fixture',account:'hunter_m7_fixture'});
const ids=JSON.parse(readFileSync('frontend/tests/results/theme/identities.json'));
const edge=process.argv.includes('--edge'), checks=[], errors=[], external=[];
const check=(name,ok=true)=>{assert.ok(ok,name);checks.push(name);console.log('PASS '+name);};
const preview=spawn(process.execPath,['node_modules/vite/bin/vite.js','preview','--host','localhost','--port','4191','--strictPort'],{cwd:'frontend',stdio:'ignore'});
let browser;
try {
  for(let i=0;i<50;i++){try{if((await fetch(BASE+'/login')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({executablePath:edge?'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe':'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  async function context(options={}) {
    const c=await browser.newContext({viewport:{width:1440,height:1000},...options});
    c.themeFaults={delayDashboard:false,abortDashboard:false,image:null};
    await c.addInitScript(()=>{
      const pending=new Set(), resize=new Set(), visibility=new Set();
      const raf=window.requestAnimationFrame.bind(window),cancel=window.cancelAnimationFrame.bind(window);
      window.requestAnimationFrame=callback=>{const id=raf(time=>{pending.delete(id);callback(time);});pending.add(id);return id;};
      window.cancelAnimationFrame=id=>{pending.delete(id);cancel(id);};
      const add=EventTarget.prototype.addEventListener,remove=EventTarget.prototype.removeEventListener;
      EventTarget.prototype.addEventListener=function(type,callback,...rest){if(this===window&&type==='resize')resize.add(callback);if(this===document&&type==='visibilitychange')visibility.add(callback);return add.call(this,type,callback,...rest);};
      EventTarget.prototype.removeEventListener=function(type,callback,...rest){if(this===window&&type==='resize')resize.delete(callback);if(this===document&&type==='visibilitychange')visibility.delete(callback);return remove.call(this,type,callback,...rest);};
      window.__themeMetrics=()=>({pending:pending.size,resize:resize.size,visibility:visibility.size});
    });
    await c.route('**/hunter-system/api/v1/**',async route=>{
      try {const u=new URL(route.request().url());
        if(u.pathname.endsWith('/me/dashboard')&&c.themeFaults.delayDashboard){c.themeFaults.delayDashboard=false;await delay(600);}
        if(u.pathname.endsWith('/me/dashboard')&&c.themeFaults.abortDashboard){c.themeFaults.abortDashboard=false;await route.abort('failed');return;}
        const response=await route.fetch({url:API+u.pathname.split('/api/v1')[1]+u.search,headers:{...route.request().headers(),origin:'http://localhost'}});await route.fulfill({response});}
      catch(error){if(!/Route is already handled|Target page, context or browser has been closed|Request context disposed/.test(error.message))throw new Error(`Fixture proxy failed for ${new URL(route.request().url()).pathname} (${error.name}); request headers omitted.`);}
    });
    await c.route('**/hunter-system/assets/badges/**',async route=>{const path=new URL(route.request().url()).pathname;if(path===c.themeFaults.image){c.themeFaults.image=null;await route.abort('failed');return;}const response=await route.fetch({url:'http://localhost'+path});await route.fulfill({response});});
    c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(!['localhost','127.0.0.1'].includes(new URL(r.url()).hostname))external.push(r.url());});});
    return c;
  }
  async function go(p,url,ready){await p.goto(BASE+url);await p.locator(ready).first().waitFor();}
  async function login(p,email,password){await go(p,'/login','input[type=email]');await p.getByLabel('Email').fill(email);await p.getByLabel('Password').fill(password);await p.getByRole('button',{name:'Log in',exact:true}).click();await p.waitForURL(/\/(dashboard|admin)$/);await p.locator('main .card').first().waitFor();}
  const c=await context(),p=await c.newPage();await go(p,'/login','input[type=email]');
  const actual=await p.evaluate(()=>{
    const css=getComputedStyle(document.documentElement);const primary=getComputedStyle(document.querySelector('.btn-primary'));
    return {tokens:['--bg','--surface','--surface-raised','--border','--accent-primary','--accent-secondary','--text','--text-muted','--success','--danger'].map(k=>css.getPropertyValue(k).trim()),primary:[primary.color,primary.backgroundColor],radius:getComputedStyle(document.querySelector('.card')).borderRadius};
  });
  check('Shared exact theme tokens and16px cards',actual.tokens.join(',')==='#07090e,#0d111b,#111827,#222d3d,#ffd43b,#387eb8,#f4f7fb,#94a1b5,#59d98e,#ff707c'&&actual.radius==='16px');
  check('Yellow primary uses dark text',actual.primary.join('|')==='rgb(23, 23, 23)|rgb(255, 212, 59)');
  check('One decorative aria-hidden pointer-free background',await p.locator('.ambient-background[aria-hidden=true]').count()===1&&await p.locator('.ambient-background').evaluate(e=>getComputedStyle(e).pointerEvents==='none'));
  for(const width of [1440,900,768,390,320]){
    await p.setViewportSize({width,height:900});await go(p,'/register','input[type=email]');check(`Registration ${width}px: no document overflow`,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  }
  await p.setViewportSize({width:1440,height:1000});await login(p,ids.student,'Course fixture student password');
  const routes=[['/dashboard','.stat'],['/topics','.roadmap-item'],['/topics/18','h1'],['/topics/18/lesson','.prose'],['/assessment/result','.review-item'],['/quiz/'+ids.quiz,'.question'],['/history','.ledger'],['/store','.badge-card'],['/profile','.owned-item']];
  for(const width of [1440,900,768,390,320]){
    await p.setViewportSize({width,height:900});
    for(const [url,ready] of routes){await go(p,url,ready);check(`Student ${url} ${width}px contains page overflow`,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));}
  }
  await go(p,'/dashboard','.stat');
  await p.getByRole('button',{name:'Menu',exact:true}).click();await p.locator('dialog[open]').waitFor();
  check('Student drawer contains only student destinations',await p.locator('dialog nav a').count()===5&&await p.locator('dialog a[href*="/admin"]').count()===0);
  check('Drawer opens with focus inside',await p.evaluate(()=>document.querySelector('dialog').contains(document.activeElement)));
  const close=p.getByRole('button',{name:'Close navigation'});await close.focus();await p.keyboard.press('Shift+Tab');
  check('Native drawer traps reverse Tab at last destination',await p.evaluate(()=>document.activeElement.textContent==='Profile'));
  await p.keyboard.press('Tab');check('Drawer wraps Tab to close',await close.evaluate(e=>e===document.activeElement));
  await p.keyboard.press('Escape');await p.locator('dialog[open]').waitFor({state:'hidden'});
  check('Escape closes and returns focus to Menu',await p.getByRole('button',{name:'Menu',exact:true}).evaluate(e=>e===document.activeElement));
  check('Drawer restores body scrolling',await p.evaluate(()=>document.body.style.overflow===''));
  await p.getByRole('button',{name:'Menu',exact:true}).click();await p.locator('dialog nav').getByRole('link',{name:'Topics',exact:true}).click();await p.waitForURL(/\/topics$/);
  await p.waitForFunction(()=>document.querySelector('h1')===document.activeElement,{},{timeout:3000});
  check('Drawer destination closes overlay and focuses next page title',await p.locator('dialog[open]').count()===0&&await p.locator('h1').evaluate(e=>e===document.activeElement));
  await p.reload();await p.locator('.roadmap-item').first().waitFor();check('Authenticated deep-link refresh retains themed shell',await p.locator('.ambient-background').count()===1);
  await p.setViewportSize({width:1440,height:900});await go(p,'/dashboard','.stat');
  check('Desktop sidebar245px; active yellow indicator',await p.locator('.desktop-sidebar').evaluate(e=>getComputedStyle(e).width==='245px')&&await p.locator('.desktop-sidebar a.active').evaluate(e=>getComputedStyle(e).color==='rgb(255, 212, 59)'));
  await p.setViewportSize({width:900,height:900});check('Tablet sidebar narrows210px',await p.locator('.desktop-sidebar').evaluate(e=>getComputedStyle(e).width==='210px'));
  await p.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'));});await delay(100);
  check('Controlled hidden-document event cancels animation frames',(await p.evaluate(()=>window.__themeMetrics())).pending===0);
  await p.evaluate(()=>{delete document.hidden;document.dispatchEvent(new Event('visibilitychange'));});await delay(100);
  check('Visibility resume owns one animation loop',(await p.evaluate(()=>window.__themeMetrics())).pending===1);
  await p.emulateMedia({reducedMotion:'reduce'});await delay(100);const pixels=await p.locator('canvas').evaluate(e=>e.toDataURL());await delay(150);
  check('Reduced motion is static with no pending frames',pixels===await p.locator('canvas').evaluate(e=>e.toDataURL())&&(await p.evaluate(()=>window.__themeMetrics())).pending===0);
  const size=await p.locator('canvas').evaluate(e=>e.width*e.height);check('Canvas backing store stays below1.8M pixels',size<=1804000);
  const before=await p.evaluate(()=>window.__themeMetrics());await p.locator('.desktop-sidebar').getByRole('link',{name:'Topics',exact:true}).click();await p.waitForURL(/\/topics$/);
  check('SPA navigation keeps one background and listener set',await p.locator('.ambient-background').count()===1&&JSON.stringify(await p.evaluate(()=>window.__themeMetrics()))===JSON.stringify(before));
  c.themeFaults.delayDashboard=true;await p.locator('.desktop-sidebar').getByRole('link',{name:'Dashboard',exact:true}).click();await p.getByRole('status').waitFor();check('Real delayed response displays themed loading state',await p.locator('.spinner').isVisible());await p.locator('.stat').first().waitFor();
  await go(p,'/topics','.roadmap-item');c.themeFaults.abortDashboard=true;await p.locator('.desktop-sidebar').getByRole('link',{name:'Dashboard',exact:true}).click();await p.getByRole('alert').waitFor();check('Controlled transport failure has readable error/retry',await p.getByRole('button',{name:'Try again'}).isVisible());await p.getByRole('button',{name:'Try again'}).click();await p.locator('.stat').first().waitFor();
  await go(p,'/store','.badge-card');await p.locator('.badge-art').first().scrollIntoViewIfNeeded();await delay(150);
  check('Approved artwork renders real local PNGs',await p.locator('.badge-card img').evaluateAll(images=>images.length===6&&images.every(i=>i.complete&&i.naturalWidth===512)));
  const image=await p.locator('.badge-card img').first().getAttribute('src');c.themeFaults.image=image;await p.reload();await p.locator('.badge-art-placeholder').waitFor();check('Failed artwork falls back without broken image',await p.locator('.badge-art-placeholder').count()===1);
  await p.getByRole('button',{name:'Log out',exact:true}).click();await p.waitForURL(/\/login$/);await p.locator('input[type=email]').waitFor();
  check('Shell transition cleans old canvas/listeners',await p.locator('canvas').count()===1&&(await p.evaluate(()=>window.__themeMetrics())).resize===1&&(await p.evaluate(()=>window.__themeMetrics())).visibility===1);await c.close();

  const ca=await context({reducedMotion:'reduce'}),a=await ca.newPage();await login(a,ids.admin,'Theme isolated administrator password');
  const student=await a.evaluate(async email=>(await fetch('/hunter-system/api/v1/admin/students?query='+encodeURIComponent(email)).then(r=>r.json())).data.students[0].user_id,ids.student);
  const q=Object.values(JSON.parse(readFileSync('content/import-fixture.json')).records['3'].new_questions)[0].id;
  const badge=await a.evaluate(async()=> (await fetch('/hunter-system/api/v1/admin/badges').then(r=>r.json())).data.find(b=>b.name==='Shadow Recruit').id);
  for(const width of [1440,900,768,390,320]){
    await a.setViewportSize({width,height:900});
    for(const [url,ready] of [['/admin','.grid-3'],['/admin/students','.admin-table'],['/admin/students/'+student,'.admin-table'],['/admin/login-events','.admin-table'],['/admin/topics','.admin-table'],['/admin/topics/18/lesson','textarea'],['/admin/questions','.admin-table'],['/admin/questions/'+q,'textarea'],['/admin/badges','.admin-table'],['/admin/badges/'+badge,'input[type=number]'],['/admin/audit','article']]){await go(a,url,ready);check(`Admin ${url} ${width}px contains page overflow`,await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));}
  }
  await go(a,'/admin/students?query=theme-no-matching-student-7654321','.admin-table');check('Real empty table is readable',await a.getByText('No records match.',{exact:true}).isVisible());
  await go(a,'/admin/topics/18/lesson','textarea');await a.getByLabel('Lesson title').fill('[FIXTURE] Unsaved theme draft');await a.getByRole('button',{name:'Menu',exact:true}).click();
  check('Admin drawer keeps its six destinations separate',await a.locator('dialog nav a').count()===6&&await a.locator('dialog a[href*="/profile"]').count()===0);
  a.once('dialog',d=>d.dismiss());await a.locator('dialog nav').getByRole('link',{name:'Questions',exact:true}).click();await delay(100);
  check('Native dirty-form confirmation stacks over drawer; Cancel preserves input',await a.getByLabel('Lesson title').inputValue()==='[FIXTURE] Unsaved theme draft'&&await a.locator('dialog[open]').count()===0);
  await a.getByRole('button',{name:'Menu',exact:true}).click();a.once('dialog',d=>d.accept());await a.locator('dialog nav').getByRole('link',{name:'Questions',exact:true}).click();await a.waitForURL(/\/admin\/questions$/);
  check('Deliberate discard navigates without writing draft',await a.evaluate(()=>document.body.dataset.adminDirty===undefined));await ca.close();
  const cf=await context({reducedMotion:'reduce'});await cf.addInitScript(()=>{HTMLCanvasElement.prototype.getContext=()=>null;});const f=await cf.newPage();await go(f,'/login','input[type=email]');check('Unavailable canvas retains static decoration and usable form',await f.locator('.ambient-static').isVisible()&&await f.getByRole('button',{name:'Log in',exact:true}).isEnabled());await cf.close();
  const cr=await context({deviceScaleFactor:3,reducedMotion:'reduce'}),r=await cr.newPage();await go(r,'/login','input[type=email]');
  check('Emulated DPR3 desktop is pixel-budget capped',await r.locator('canvas').evaluate(e=>e.width*e.height<=1804000&&e.width<innerWidth*1.5));
  await r.setViewportSize({width:390,height:844});await delay(100);check('Emulated DPR3 mobile is capped at DPR1',await r.locator('canvas').evaluate(e=>e.width===390&&e.height===844));await cr.close();
  check('No uncaught browser errors',errors.length===0);check('No external fonts or required network dependencies',external.length===0);
  writeFileSync(`frontend/tests/results/theme-${edge?'edge':'chrome'}-checks.json`,JSON.stringify({browser:await browser.version(),engine:'Chromium',real_device:false,mode:'Real isolated fixture API; labelled transport/canvas/visibility fault injection',checks:checks.length,passed:checks,errors,external},null,2));
  console.log(`${checks.length} focused themed checks passed (${edge?'Edge':'Chrome'}, Chromium, emulated viewports).`);
} catch(error) {
  const page=browser?.contexts()[0]?.pages()[0];
  if(page){await page.screenshot({path:'frontend/tests/results/theme/focused-failure.png',fullPage:true});console.log('Overflow diagnostics',await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth,elements:[...document.querySelectorAll('main *')].filter(e=>e.getBoundingClientRect().right>innerWidth).slice(0,12).map(e=>({tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right}))})));}
  throw error;
} finally {await browser?.close();preview.kill();}
