// Real Apache/dev Chrome checks. Reuses ONLY the historical b6 test administrator.
// All admin mutations are blocked at the browser network boundary. Fingerprints
// cover dev content and audit before/after, including the preserved IR-1 row.
import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import assert from 'node:assert/strict';
const mysql='C:/xampp/mysql/bin/mysql.exe';
const query=sql=>execFileSync(mysql,['-u','root','-N','hunter_system_dev','-e',sql]).toString().trim();
const email=query("SELECT email FROM users WHERE role='admin' AND email LIKE 'b6.%admin@example.invalid' ORDER BY id DESC LIMIT 1");
assert.match(email,/^b6\..*\.admin@example\.invalid$/,'Requires an existing historical browser-test administrator; creates none.');
const fingerprint=()=>query('CHECKSUM TABLE topics,lessons,questions,question_choices,badges,audit_log');
const before=fingerprint();
let count=0;const errors=[];
const check=(name,ok=true)=>{assert.ok(ok,name);count++;console.log('PASS '+name);};
const base='http://localhost/hunter-system';
const browser=await chromium.launch({executablePath:process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try {
  const c=await browser.newContext({viewport:{width:1280,height:900}});
  await c.route('**/api/v1/admin/**',route=>route.request().method()==='GET'?route.continue():route.abort('blockedbyclient'));
  const p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));
  await p.goto(base+'/admin');await p.getByRole('heading',{name:'Log in',exact:true}).waitFor();check('Anonymous administrator UI redirects to login');
  await p.getByLabel('Email').fill(email);await p.getByLabel('Password').fill('Admin journey password 1');await p.getByRole('button',{name:'Log in',exact:true}).click();
  await p.waitForURL(/\/admin$/);await p.getByRole('heading',{name:'Administrator overview',exact:true}).waitFor();await p.getByText('2 / 18',{exact:true}).waitFor();check('Real administrator login and readiness counts');
  mkdirSync('tests/results',{recursive:true});await p.screenshot({path:'tests/results/admin-overview.png',fullPage:true});
  await p.reload();await p.getByRole('heading',{name:'Administrator overview',exact:true}).waitFor();check('Real authenticated admin route refresh');
  async function go(route,heading,ready){await p.goto(base+route);await p.getByRole('heading',{name:heading,exact:true}).waitFor();await p.locator(ready).first().waitFor();}
  await go('/admin/students','Student tracking','tbody a');await p.getByLabel('Name or email').fill('b6.');await p.getByLabel('Recently active',{exact:true}).selectOption('false');await p.getByRole('button',{name:'Apply filters'}).click();await p.locator('tbody a').first().waitFor();check('Real student name/activity filters return records');
  await p.locator('tbody a').first().click();await p.getByRole('heading',{name:'Student details',exact:true}).waitFor();await p.getByRole('heading',{name:'Sessions',exact:true}).waitFor();check('Real student progress, rank and login/session history');
  await go('/admin/login-events?outcome=success','Login events','tbody td');check('Real filtered global login history');
  await go('/admin/topics','Topics and lessons','tbody a');check('All 18 fixed curriculum topics listed',await p.locator('tbody tr').count()===18);
  await go('/admin/topics/3','Edit topic','textarea');check('Draft topic readiness is explicit',(await p.locator('main').innerText()).includes('0/10'));
  await go('/admin/topics/1/lesson','Edit lesson','textarea');check('Real lesson text and revision load',!!(await p.getByLabel('Lesson title').inputValue()));
  await go('/admin/questions?usage=assessment&status=active&per_page=10','Question management','tbody a');check('Real question filters return ten rows',await p.locator('tbody tr').count()===10);await p.getByRole('button',{name:'Next page'}).click();await p.getByText(/Page 2/).waitFor();check('Real question pagination advances');
  await p.locator('tbody a').first().click();await p.getByRole('heading',{name:'Edit question',exact:true}).waitFor();await p.getByLabel('Question text',{exact:true}).waitFor();check('Actual choices and correct-answer editor load',await p.locator('input[type=radio]:checked').count()===1);
  await go('/admin/badges','Badge management','tbody a');check('Real catalogue retains four draft badges',await p.locator('tbody tr').count()===4);
  await p.locator('tbody a').first().click();await p.getByRole('heading',{name:'Edit badge',exact:true}).waitFor();await p.getByLabel('Badge name',{exact:true}).waitFor();check('Provisional price and nullable artwork load',await p.getByLabel('Artwork reference (optional)').inputValue()==='');
  await go('/admin/audit?entity_type=question&entity_id=106','Audit history','article');check('Preserved IR-1 audit visible',(await p.locator('main').innerText()).includes('question #106'));
  await p.setViewportSize({width:375,height:740});await p.reload();await p.locator('article').waitFor();check('Real admin mobile layout has no document overflow',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await p.screenshot({path:'tests/results/admin-mobile.png',fullPage:true});
  await p.getByRole('button',{name:'Log out',exact:true}).click();await p.waitForURL(/\/login$/);await p.goto(base+'/admin/audit');await p.getByRole('heading',{name:'Log in',exact:true}).waitFor();check('Logout clears administrator UI data');
  // Reuse the account created by the single attempted student regression run.
  const student=query("SELECT email FROM users WHERE role='student' AND email LIKE 'b6.%a@example.invalid' ORDER BY id DESC LIMIT 1");
  await p.getByLabel('Email').fill(student);await p.getByLabel('Password').fill('browser journey password');await p.getByRole('button',{name:'Log in',exact:true}).click();await p.waitForURL(/\/(start|assessment|dashboard)$/);
  await p.goto(base+'/admin');await p.waitForURL(/\/(start|assessment|dashboard)$/);check('Actual student role cannot view administrator UI');
  const response=await c.request.get(base+'/api/v1/admin/questions');check('Actual student admin API request is forbidden',response.status()===403);
  check('No uncaught browser errors',errors.length===0);
}finally {await browser.close();check('Dev content and audit fingerprints unchanged',fingerprint()===before);}
console.log(`${count} real read-only Chrome checks passed. No administrator content mutations permitted.`);
