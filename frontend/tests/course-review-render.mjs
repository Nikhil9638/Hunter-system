// Focused rendering only: actual existing Markdown component, no API simulation.
import { build } from 'vite';
import { chromium } from 'playwright-core';
import { readFileSync,writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import path from 'node:path';
const root=path.resolve('..');
const lessons=Array.from({length:18},(_,i)=>({topic:i+1,body:readFileSync(i<2?`../content/review/topic-0${i+1}-reviewed.md`:`../content/topics/${String(i+1).padStart(2,'0')}/lesson.md`,'utf8').replace(/\r\n?/g,'\n')}));
const result=await build({root:process.cwd(),logLevel:'error',define:{'process.env.NODE_ENV':'"production"'},build:{write:false,minify:false,lib:{entry:path.resolve('tests/course-review-entry.jsx'),name:'ReviewRender',formats:['iife']}}});
const bundle=(Array.isArray(result)?result[0].output:result.output).find(o=>o.type==='chunk').code,css=readFileSync('src/styles.css','utf8');
const browserLabel=process.env.HUNTER_BROWSER_LABEL||'Chrome';
const browser=await chromium.launch({executablePath:process.env.HUNTER_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});let checks=0;
try{
 const page=await browser.newPage();
 for(const width of [1280,390]){
  await page.setViewportSize({width,height:900});
  for(const l of lessons){
   await page.setContent('<style>'+css+'</style><main id="root" style="padding:16px;max-width:100%;min-width:0"></main>');
   await page.addScriptTag({content:bundle});await page.evaluate(body=>window.showLesson(body),l.body);
   const expected=[...l.body.matchAll(/```python\n([\s\S]*?)\n```/g)].map(m=>m[1]);
   await page.locator('pre[aria-label="python example"]').first().waitFor();
   assert.deepEqual(await page.locator('pre[aria-label="python example"] code').allTextContents(),expected);checks++;
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks++;
  }
 }
 await page.setContent('<main id="root"></main>');await page.addScriptTag({content:bundle});
 await page.evaluate(()=>window.showLesson('<img src=x onerror="window.bad=true">'));await page.getByText('<img src=x onerror="window.bad=true">',{exact:true}).waitFor();
 assert.equal(await page.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.bad),undefined);checks++;
 const report={mode:'Existing Markdown component rendering only — not live API evidence',browser:browserLabel,engine:'Chromium',version:await browser.version(),topics:18,widths:[1280,390],checks,failures:0};
 const filename=browserLabel==='Chrome'?'render-validation.json':'render-validation-'+browserLabel.toLowerCase()+'.json';
 writeFileSync('../content/review/'+filename,JSON.stringify(report,null,2)+'\n');console.log(`${browserLabel}: ${checks} focused rendering checks passed`);
}finally{await browser.close();}
