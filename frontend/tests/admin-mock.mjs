// Explicit simulated contract responses for Chrome UI checks. No database access.
// This is test-only, denied by Apache, and never imported by the application.
export async function installMock(context,state,role) {
  const clone=x=>JSON.parse(JSON.stringify(x));
  const now='2026-10-15T12:00:00.000Z';
  if(!state.topics) {
    state.topics=Array.from({length:18},(_,i)=>({id:i+1,position:i+1,title:`Topic ${i+1}`,slug:`topic-${i+1}`,summary:'Topic summary',skill_group:'G1',status:'available',version:now,lesson:{status:'published',revision:1},lesson_published:true,quiz_pool_size:10,required_pool_size:10,content_complete:true,is_ready:true,students_unlocked:1}));
    state.lesson={topic_id:18,lesson_id:18,title:'[FIXTURE] Lesson',body_markdown:'[FIXTURE] Body',revision:1,status:'published',published_at:now,updated_at:now};
    state.questions=[{id:1,topic_id:1,skill_group:'G1',for_quiz:false,for_assessment:true,difficulty:'easy',stem:'[FIXTURE] Assessment',code_snippet:null,explanation:'Explanation',choices:[{id:1,label:'A',text:'A',is_correct:true},{id:2,label:'B',text:'B',is_correct:false}],status:'active',revision:1,valid:true,used_in_attempts:0}];
    state.badges=[];state.audit=[];state.seq=100;
  }
  let user=null;
  const actor={id:900,display_name:'Browser administrator'};
  const session=()=>({user,csrf_token:'test-csrf'});
  const student={user_id:4,display_name:'Student',email:'student@example.invalid',account_status:'active',last_successful_login_at:now,last_activity_at:now,recently_active:false,hunter_rank:'E',current_topic:'Topic 1',topics_passed:0,topics_total:18};
  const err=(status,code,message,details={})=>({status,error:{code,message,details}});
  const audit=(entity_type,entity_id,action,before,after)=>state.audit.unshift({id:++state.seq,at:now,admin:actor,entity_type,entity_id,action,operation:after?.operation || null,before:clone(before),after:clone(after)});
  const bump=()=>`2026-10-15T12:00:${String(++state.seq%60).padStart(2,'0')}.${String(state.seq).padStart(3,'0')}Z`;
  function handle(method,p,b,s) {
    if(p==='/auth/me')return user?session():err(401,'not_authenticated','Please log in.');
    if(p==='/auth/login'){user={...actor,email:b.email,role, ...(role==='student'?{onboarding_state:'assessment_submitted'}:{})};return session();}
    if(p==='/auth/logout'){user=null;return {};}
    if(!user)return err(401,'not_authenticated','Please log in.');
    if(p==='/me/dashboard')return {display_name:'Student',hunter_rank:{code:'E',name:'E-Rank'},placement_rank:{code:'E',name:'E-Rank'},experience_level:{label:'Beginner'},topics_passed:0,topics_total:18,coins:0,current_topic:null,next_rank:null};
    if(p.startsWith('/admin/')&&role!=='admin')return err(403,'forbidden','Administrator only.');
    if(p==='/admin/students')return {students:[student],page:Number(s.get('page')||1),per_page:Number(s.get('per_page')||20),total:1};
    if(p==='/admin/students/4')return {...student,registered_at:now,onboarding_state:'assessment_submitted',experience_level:{label:'Beginner'},placement:{rank:'E',correct_count:0,recommended_topic:{id:1,title:'Topic 1'},offered_topic:null},topics:[{id:1,position:1,title:'Topic 1',status:'available',unlocked:true,unlock_source:'placement',lesson_visits:1,completed_attempts:0,best_correct:null,first_passed_at:null}],rank_history:[{at:now,reason:'placement',from:null,to:'E',topics_passed:null}]};
    if(p==='/admin/students/4/logins')return {events:[{at:now,outcome:'success'}],events_total:1,sessions_total:3,page:1,per_page:20,sessions:[{started_at:now,last_activity_at:now,ended_at:null,end_reason:null},{started_at:now,last_activity_at:now,ended_at:now,end_reason:'logged_out'},{started_at:now,last_activity_at:now,ended_at:now,end_reason:'expired'}]};
    if(p==='/admin/login-events')return {events:[{id:1,at:now,outcome:'unknown_account',user:null}],page:1,per_page:20,total:1};
    if(p==='/admin/topics')return state.topics;
    if(/^\/admin\/topics\/\d+$/.test(p)) {
      const t=state.topics.find(t=>t.id===Number(p.split('/').pop()));
      if(b.expected_version!==t.version)return err(409,'edit_conflict','Topic changed.');
      const before=clone(t);Object.assign(t,b,{version:bump()});delete t.expected_version;t.is_ready=t.status==='available';audit('topic',t.id,b.status==='available'?'publish':b.status==='draft'?'unpublish':'update',before,t);return t;
    }
    if(p.startsWith('/admin/topics/18/lesson')) {
      if(method==='GET')return state.lesson;
      const l=state.lesson;
      if(b.expected_revision!==l.revision)return err(409,'edit_conflict','This item was changed by someone else.',{current_revision:l.revision});
      const before=clone(l);
      if(method==='PUT')Object.assign(l,b,{revision:l.revision+1});
      else {const act=p.split('/').pop();l.status={publish:'published',unpublish:'draft',retire:'retired'}[act];}
      delete l.expected_revision;
      audit('lesson',18,method==='PUT'?'update':p.split('/').pop(),before,l);return l;
    }
    if(p==='/admin/questions' && method==='GET') {
      const rows=state.questions.filter(q=>(!s.get('status')||q.status===s.get('status'))&&(!s.get('usage')||q[s.get('usage')==='assessment'?'for_assessment':'for_quiz']));
      return {questions:rows,page:Number(s.get('page')||1),per_page:Number(s.get('per_page')||20),total:rows.length};
    }
    if(p==='/admin/questions' && method==='POST') {const q={...b,id:++state.seq,status:'draft',revision:1,valid:true,used_in_attempts:0,skill_group:'G1'};state.questions.push(q);audit('question',q.id,'create',null,q);return {status:201,data:q};}
    if(p.startsWith('/admin/questions/')) {
      const id=Number(p.split('/')[3]);const q=state.questions.find(q=>q.id===id);if(!q)return err(404,'not_found','Not found');if(method==='GET')return q;
      if(b.expected_revision!==q.revision)return err(409,'edit_conflict','Question changed.');const before=clone(q);
      if(method==='PUT'){Object.assign(q,b,{revision:q.revision+1});delete q.expected_revision;}else q.status=p.endsWith('/activate')?'active':'retired';
      audit('question',q.id,method==='PUT'?'update':p.endsWith('/activate')?'publish':'retire',before,q);return q;
    }
    if(p==='/admin/badges' && method==='GET')return state.badges;
    if(p==='/admin/badges' && method==='POST') {const badge={...b,id:++state.seq,version:bump(),status:'draft',price_status:'provisional',price_approval:null,owners:0,image_url:null};state.badges.push(badge);audit('badge',badge.id,'create',null,badge);return {status:201,data:badge};}
    if(p.startsWith('/admin/badges/')) {
      const id=Number(p.split('/')[3]);const badge=state.badges.find(b=>b.id===id);
      if(b.expected_version!==badge.version)return err(409,'edit_conflict','Badge changed.');
      const before=clone(badge);
      if(method==='PUT') {
        if(b.image_ref && !/^badges\/[a-z0-9][a-z0-9-]{0,62}\.(png|webp)$/.test(b.image_ref))return err(400,'validation_failed','Please correct the fields.',{fields:{image_ref:'invalid'}});
        if(b.price!==badge.price){badge.price_status='provisional';badge.price_approval=null;if(badge.status==='on_sale')badge.status='off_sale';}
        Object.assign(badge,b);delete badge.expected_version;
      } else if(p.endsWith('/approve-price')) {badge.price_status='approved';badge.price_approval={approved_by:actor,approved_at:now,price:b.price};}
      else {if(b.status==='on_sale'&&badge.price_status!=='approved')return err(409,'price_not_approved','Approve the current price before putting the badge on sale.');badge.status=b.status;}
      badge.version=bump();audit('badge',id,p.endsWith('/approve-price')?'update':b.status==='on_sale'?'publish':b.status==='retired'?'retire':method==='PUT'?'update':'unpublish',before,badge);return badge;
    }
    if(p==='/admin/audit-log') {const rows=state.audit.filter(e=>(!s.get('entity_type')||s.get('entity_type')===e.entity_type)&&(!s.get('entity_id')||Number(s.get('entity_id'))===e.entity_id));return {entries:rows,page:1,per_page:20,total:rows.length};}
    return err(404,'not_found',`Unhandled simulated endpoint ${p}`);
  }
  await context.route('**/hunter-system/api/v1/**',async route=>{
    const request=route.request();const url=new URL(request.url());const p=url.pathname.split('/api/v1')[1];const body=request.postDataJSON() || {};
    if(state.delayNextPut && request.method()==='PUT') { state.delayNextPut=false; await new Promise(resolve=>setTimeout(resolve,350)); }
    const result=handle(request.method(),p,body,url.searchParams);const status=result?.status && typeof result.status==='number'?result.status:200;
    await route.fulfill({status,json:result?.error?{error:result.error}:{data:clone(result?.data || result)}});
  });
}
