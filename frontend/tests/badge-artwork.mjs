// Original private vector sources -> allowed transparent PNG app assets.
import { chromium } from 'playwright-core';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const project=path.resolve('..'),root=path.join(project,'content/badges');
const manifest=JSON.parse(readFileSync(path.join(root,'manifest.json')));
const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
const report={chrome:await browser.version(),assets:[],review_sizes:[]};
try {
  const page=await browser.newPage({viewport:{width:512,height:512},deviceScaleFactor:1});
  for(const b of manifest.badges){
    const target=path.join(project,'assets',b.image_ref);
    await page.goto(pathToFileURL(path.join(root,b.source)).href);
    await page.screenshot({path:target,omitBackground:true});
    const png=readFileSync(target);
    assert.equal(png.subarray(0,8).toString('hex'),'89504e470d0a1a0a');
    assert.equal(png.readUInt32BE(16),512);assert.equal(png.readUInt32BE(20),512);
    await page.goto('about:blank');
    await page.setContent('<canvas width="512" height="512"></canvas>');
    const stats=await page.evaluate(async encoded=>{
      const img=new Image();img.src='data:image/png;base64,'+encoded;await img.decode();
      const c=document.querySelector('canvas').getContext('2d');c.drawImage(img,0,0);
      const pixels=c.getImageData(0,0,512,512).data;let transparent=0,opaque=0,partial=0;
      for(let i=3;i<pixels.length;i+=4){if(pixels[i]===0)transparent++;else if(pixels[i]===255)opaque++;else partial++;}
      return {transparent,opaque,partial,corners:[pixels[3],pixels[(511)*4+3],pixels[(512*511)*4+3],pixels[(512*512-1)*4+3]]};
    },png.toString('base64'));
    assert.ok(stats.transparent>50000&&stats.opaque>50000&&stats.partial>0);assert.deepEqual(stats.corners,[0,0,0,0]);
    report.assets.push({name:b.name,format:'PNG',width:512,height:512,bytes:png.length,...stats});
  }
  for(const width of [1280,390]) {
    await page.setViewportSize({width,height:900});await page.goto(pathToFileURL(path.join(root,'review.html')).href);
    await page.locator('img').last().waitFor();
    assert.equal(await page.locator('img').count(),18);
    assert.ok(await page.locator('img').evaluateAll(imgs=>imgs.every(i=>i.complete&&i.naturalWidth===512)));
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:path.join(root,`proposal-${width}.png`),fullPage:true});
    report.review_sizes.push({width,images:18,overflow:false});
  }
} finally {await browser.close();}
writeFileSync(path.join(root,'artwork-validation.json'),JSON.stringify(report,null,2)+'\n');
console.log('PASS six 512×512 PNGs, alpha/transparency, 18 previews at desktop/mobile widths.');
