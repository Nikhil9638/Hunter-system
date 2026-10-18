// Focused real fixture concurrency: audited temporary catalogue, no grant changes.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const API='http://127.0.0.1:8098/hunter-system/api/v1',API2='http://127.0.0.1:8099/hunter-system/api/v1';
const dir=process.env.HUNTER_FIXTURE_PRIVATE_DIR;assert.ok(dir,'Explicit protected fixture config required');
const helper=(...args)=>JSON.parse(execFileSync('C:/xampp/php/php.exe',['frontend/tests/db-helper.php',...args.map(String)],{env:{...process.env,HUNTER_PRIVATE_DIR:dir}}));
const identity=helper('fixture-identity');assert.deepEqual(identity,{database:'hunter_system_m3_fixture',account:'hunter_m7_fixture'});
for(const base of [API,API2])assert.deepEqual((await fetch(base+'/__test/identity').then(r=>r.json())).data,identity);
const run=Date.now().toString(36),pw='Concurrent isolated fixture password';let checks=0;
function check(name,ok=true){assert.ok(ok,name);checks++;console.log('PASS '+name);}
function client(){let cookie='',csrf='';return async(method,path,data,base=API)=>{const response=await fetch(base+path,{method,headers:{origin:'http://localhost',cookie,'X-CSRF-Token':csrf,...(data===undefined?{}:{'Content-Type':'application/json'})},body:data===undefined?undefined:JSON.stringify(data)});const set=response.headers.getSetCookie();if(set.length)cookie=set.map(s=>s.split(';')[0]).join('; ');const b=await response.json();if(b.data?.csrf_token)csrf=b.data.csrf_token;return {status:response.status,...b};};}
const admin=client(),email=`finalrace.${run}.admin@example.invalid`;assert.equal(helper('create-admin',email,pw),true);assert.equal((await admin('POST','/auth/login',{email,password:pw})).status,200);
const badgeIds=[];
// Retain and retire only prior labelled records from this same test after a failed harness run.
for(const b of (await admin('GET','/admin/badges')).data.filter(b=>b.name.startsWith('[FIXTURE] Final race ') && b.status!=='retired')){assert.equal((await admin('POST',`/admin/badges/${b.id}/status`,{expected_version:b.version,status:'retired'})).status,200);}
try{
 for(let n=0;n<2;n++){
  let b=await admin('POST','/admin/badges',{slug:`fx-finalrace-${run}-${n}`,name:`[FIXTURE] Final race ${run} ${n}`,description:'Temporary isolated concurrency test cosmetic.',price:10,image_ref:null});assert.equal(b.status,201);badgeIds.push(b.data.id);
  b=await admin('POST',`/admin/badges/${b.data.id}/approve-price`,{expected_version:b.data.version,price:10});assert.equal(b.status,200);
  b=await admin('POST',`/admin/badges/${b.data.id}/status`,{expected_version:b.data.version,status:'on_sale'});assert.equal(b.status,200);
 }
 async function student(tag){
  const c=client();assert.equal((await c('POST','/auth/register',{email:`finalrace.${run}.${tag}@example.invalid`,password:pw,display_name:`[FIXTURE] Final concurrency ${tag}`})).status,201);
  assert.equal((await c('PUT','/me/experience',{experience_level_id:1})).status,200);
  const assessment=(await c('POST','/assessment',{})).data;const ak=helper('answer-key',assessment.attempt_id);
  for(const q of assessment.questions)assert.equal((await c('PUT',`/assessment/${assessment.attempt_id}/answers/${q.position}`,{choice_id:ak[q.position].wrong})).status,200);
  assert.equal((await c('POST',`/assessment/${assessment.attempt_id}/submit`,{})).status,200);
  const quiz=(await c('POST','/topics/1/quiz-attempts',{started_via:'direct'})).data;const qk=helper('answer-key',quiz.attempt_id);
  for(const q of quiz.questions)assert.equal((await c('PUT',`/quiz-attempts/${quiz.attempt_id}/answers/${q.position}`,{choice_id:qk[q.position].wrong})).status,200);
  const results=await Promise.all([c('POST',`/quiz-attempts/${quiz.attempt_id}/submit`,{},API),c('POST',`/quiz-attempts/${quiz.attempt_id}/submit`,{},API2)]);
  check(`${tag}: duplicate concurrent quiz submits return stable completion`,results.every(r=>r.status===200));
  const ledger=(await c('GET','/me/coins')).data;check(`${tag}: exactly one first-completion credit`,ledger.balance===10 && ledger.entries.length===1 && ledger.entries[0].type==='topic_reward');
  return c;
 }
 const a=await student('different');
 const different=await Promise.all([a('POST',`/badges/${badgeIds[0]}/purchase`,{},API),a('POST',`/badges/${badgeIds[1]}/purchase`,{},API2)]);
 check('Concurrent spending: one purchase succeeds and one refuses insufficient coins',different.map(r=>r.status).sort().join(',')==='200,409' && different.some(r=>r.error?.code==='insufficient_coins'));
 const profile=(await a('GET','/me/profile')).data;check('Concurrent spending: balance zero and exactly one ownership',profile.coins===0 && profile.owned_badges.length===1);
 const b=await student('same');const same=await Promise.all([b('POST',`/badges/${badgeIds[0]}/purchase`,{},API),b('POST',`/badges/${badgeIds[0]}/purchase`,{},API2)]);
 check('Concurrent duplicate purchase: one success and one already_owned',same.map(r=>r.status).sort().join(',')==='200,409'&&same.some(r=>r.error?.code==='already_owned'));
 const coins=(await b('GET','/me/coins')).data;check('Duplicate purchase produces exactly one debit',coins.balance===0&&coins.entries.filter(e=>e.type==='badge_purchase').length===1);
} finally{
 for(const id of badgeIds){const b=(await admin('GET','/admin/badges')).data.find(b=>b.id===id);assert.ok(b,'Created fixture badge retained');if(b.status!=='retired'){const r=await admin('POST',`/admin/badges/${id}/status`,{expected_version:b.version,status:'retired'});assert.equal(r.status,200);}}
 const sale=await admin('GET','/admin/badges');check('Temporary race badges retired through audited workflow; six approved remain on sale',sale.data.filter(b=>b.status==='on_sale').length===6);
 await admin('POST','/auth/logout',{});
}
console.log(`${checks} real fixture concurrency checks passed; no dev writes or privilege mutation.`);

