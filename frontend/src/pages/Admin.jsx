import { useEffect, useId, useRef, useState } from 'react';
import { Link, useBlocker, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api, get } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useApi, useAction } from '../lib/useApi.js';
import { BadgeArt, Loading, LoadError, Notice, RankBadge } from '../components/ui.jsx';
import { Icon } from '../components/Icon.jsx';
import { mana } from '../config/presentation.js';

const title = (text) => <header className="page-header admin-header"><p className="eyebrow">Administration</p><h1 tabIndex={-1}>{text}</h1></header>;
// Status chips: text + tone (never colour alone). Unknown values are shown as-is.
const CHIP_TONE = { on_sale:'ok', available:'ok', active:'ok', published:'ok', approved:'ok', success:'ok', passed:'ok', valid:'ok',
  draft:'muted', retired:'muted', locked:'muted', off_sale:'warn', provisional:'warn', wrong_password:'warn', unknown_account:'warn', in_progress:'info', completed:'warn',
  account_deactivated:'bad', deactivated:'bad', invalid:'bad' };
function Chip({ value, label }) { return <span className={`admin-chip chip-${CHIP_TONE[value] ?? 'neutral'}`}>{label ?? (value == null ? '—' : String(value).replaceAll('_', ' '))}</span>; }
/** Admin APIs return ranks as "D-Rank" (list) or "D" (detail); RankBadge maps both, null stays "Unranked". */
const Rank = ({ value }) => (value ? <RankBadge rank={value} size="sm" /> : <span className="muted">Unranked</span>);
/** Readable labelled fields for a record, followed by a complete raw view so no value is ever hidden. */
function Record({ value, fields, empty = 'Not recorded' }) {
  if (value == null) return <p className="muted">{empty}</p>;
  const known = fields.map(([k]) => k), extra = Object.keys(value).filter((k) => !known.includes(k));
  return <><dl className="admin-dl">{fields.map(([k, label, render]) => <div key={k}><dt>{label}</dt><dd>{render ? render(value[k]) : show(value[k])}</dd></div>)}
    {extra.map((k) => <div key={k}><dt>{k.replaceAll('_', ' ')}</dt><dd>{show(value[k])}</dd></div>)}</dl>
    <details className="admin-raw"><summary>Raw record</summary><Values value={value} /></details></>;
}
const topicRef = (t) => (t ? `${t.id}. ${t.title}` : '—');
const OUTCOME = { success: 'Success', wrong_password: 'Wrong password', account_deactivated: 'Account deactivated', unknown_account: 'Unknown account' };
const Outcome = ({ value }) => <Chip value={value} label={OUTCOME[value] ?? value} />;
const show = (v) => v == null ? '—' : typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v);
const qs = (values) => new URLSearchParams(Object.entries(values).filter(([,v]) => v !== '' && v != null)).toString();
function Values({ value }) { return <pre className="admin-values">{show(value)}</pre>; }
function ErrorMessage({ error }) {
  if (!error) return null;
  const explanations = { required:'This field is required.', invalid:'This value is invalid.', too_long:'This value is too long.', needs_two_choices:'Add at least two choices.', needs_one_correct:'Select exactly one correct choice.', more_than_one_correct:'Select only one correct choice.', invalid_text:'Choice text must contain 1–1,000 characters.', needs_one_usage:'Choose topic quiz, placement assessment, or both.', unknown_field:'The server does not accept this field.' };
  return <Notice tone="error" title={`${error.message} (${error.code || 'load_error'})`}>
    {error.details?.fields ? <ul>{Object.entries(error.details.fields).map(([field,code])=><li key={field}><strong>{field.replaceAll('_',' ')}</strong>: {explanations[code] || code.replaceAll('_',' ')}</li>)}</ul> : <Values value={error.details} />}
  </Notice>;
}
function Load({ request, children }) {
  if (request.loading) return <Loading />;
  if (request.error) return <LoadError error={request.error} onRetry={request.reload} />;
  return children(request.data);
}
function Field({ label, value, onChange, type = 'text', options, ...props }) {
  const id = useId();
  return <div className={`admin-field${/Markdown|Code snippet/.test(label) ? ' admin-code-field' : ''}`}><label htmlFor={id}>{label}</label>{options
    ? <select id={id} value={value ?? ''} onChange={e => onChange(e.target.value)} {...props}>{options.map(o => <option key={Array.isArray(o) ? o[0] : o} value={Array.isArray(o) ? o[0] : o}>{Array.isArray(o) ? o[1] : o}</option>)}</select>
    : type === 'textarea' ? <textarea id={id} value={value ?? ''} onChange={e => onChange(e.target.value)} rows={6} {...props} />
    : <input id={id} type={type} value={value ?? ''} onChange={e => onChange(e.target.value)} {...props} />}</div>;
}
function Filters({ fields }) {
  const [search, setSearch] = useSearchParams();
  const [draft, setDraft] = useState(Object.fromEntries(search));
  useEffect(() => setDraft(Object.fromEntries(search)), [search.toString()]);
  return <form className="admin-filters card" onSubmit={e => { e.preventDefault(); setSearch(qs({ ...draft, page: 1 })); }}>
    {fields.map(([key, label, options]) => <Field key={key} label={label} value={draft[key] ?? ''} options={options ? [['', 'All'], ...options] : undefined} onChange={v => setDraft(d => ({ ...d, [key]: v }))} />)}
    <Field label="Rows per page" value={draft.per_page || '20'} options={['10', '20', '50', '100']} onChange={v => setDraft(d => ({ ...d, per_page: v }))} />
    <button className="btn btn-primary">Apply filters</button><button type="button" className="btn btn-secondary" onClick={() => { setDraft({}); setSearch(''); }}>Clear filters</button>
  </form>;
}
function Pages({ data, total = data.total }) {
  const [search, setSearch] = useSearchParams();
  const change = n => { const next = new URLSearchParams(search); next.set('page', n); setSearch(next); };
  return <div className="choice-row"><button className="btn btn-secondary" disabled={data.page <= 1} onClick={() => change(data.page - 1)}>Previous page</button><span>Page {data.page} · {total} records</span><button className="btn btn-secondary" disabled={data.page * data.per_page >= total} onClick={() => change(data.page + 1)}>Next page</button></div>;
}
function Table({ headers, rows }) { return <div className="admin-table-wrap" role="region" aria-label="Records table" tabIndex={0}><table className="admin-table"><thead><tr>{headers.map(h => <th key={h} scope="col">{h}</th>)}</tr></thead><tbody>{rows.length ? rows.map((r,i) => <tr key={i}>{r.map((c,j) => <td key={j}>{c}</td>)}</tr>) : <tr><td colSpan={headers.length}>No records match.</td></tr>}</tbody></table></div>; }

// Every role-specific page is unmounted on identity change. All data is local, never persisted.
export function AdminBoundary({ children }) {
  const { user } = useAuth();
  return <div key={`${user.id}:${user.role}`}>{children}</div>;
}

export function AdminOverview() {
  const r = useApi(signal => Promise.all([get('/admin/topics', {signal}), get('/admin/badges', {signal}), get('/admin/questions?per_page=1', {signal}), get('/admin/students?per_page=1', {signal})]), []);
  return <>{title('Administrator overview')}<Load request={r}>{([topics,badges,questions,students]) => <>
    <p className="lead">Content readiness and student records from the current catalogue.</p><div className="grid-3">
      {[['/admin/topics','Ready topics',`${topics.filter(t => t.is_ready).length} / ${topics.length}`,'topics'],['/admin/topics','Published lessons',topics.filter(t => t.lesson_published).length,'questions'],['/admin/questions','Questions',questions.total,'questions'],['/admin/badges','Badges on sale',badges.filter(b => b.status === 'on_sale').length,'badges'],['/admin/badges','Provisional prices',badges.filter(b => b.price_status === 'provisional' && b.status !== 'retired').length,'badges'],['/admin/students','Students',students.total,'students']].map(([to,label,count,icon]) => <Link className="card admin-tile" key={label} to={to}><h2 className="card-title"><Icon name={icon} />{label}</h2><p className="big-number">{count}</p></Link>)}
    </div><Link to="/admin/audit">View audit history</Link></>}</Load></>;
}

export function AdminStudents() {
  const [search] = useSearchParams();
  const r = useApi(signal => get(`/admin/students?${qs({per_page:20,...Object.fromEntries(search)})}`, {signal}), [search.toString()]);
  return <>{title('Student tracking')}<p>Recently active means authenticated activity within 15 minutes. It does not mean online. Session validity and ended sessions are shown in student details.</p>
    <Filters fields={[[ 'query','Name or email'],['status','Account status',['active','deactivated']],['recently_active','Recently active',[['true','Yes'],['false','No']]]]} />
    <Link to="/admin/login-events">All login events</Link><Load request={r}>{d => <><Table headers={['Student','Rank / progress','Activity','Current topic']} rows={d.students.map(s => [<Link to={`/admin/students/${s.user_id}`}>{s.display_name}<br />{s.email}</Link>,<><Rank value={s.hunter_rank} /><br />{s.topics_passed}/{s.topics_total} passed</>,<><Chip value={s.recently_active ? 'active' : 'draft'} label={s.recently_active ? 'Recently active' : 'Not recently active'} /><br />Last activity: {show(s.last_activity_at)}<br />Last login: {show(s.last_successful_login_at)}</>,s.current_topic || '—'])} /><Pages data={d}/></>}</Load></>;
}
export function AdminStudent() {
  const { id } = useParams(); const [search] = useSearchParams();
  const r = useApi(signal => Promise.all([get(`/admin/students/${id}`,{signal}),get(`/admin/students/${id}/logins?${qs({per_page:20,...Object.fromEntries(search)})}`,{signal})]),[id,search.toString()]);
  return <>{title('Student details')}<Link to="/admin/students">Back to students</Link><Load request={r}>{([s,l]) => <>
    <section className="card admin-student"><h2>{s.display_name}</h2>
      <dl className="admin-dl">
        <div><dt>Email</dt><dd>{s.email}</dd></div>
        <div><dt>Account status</dt><dd><Chip value={s.account_status} /></dd></div>
        <div><dt>Hunter rank</dt><dd><Rank value={s.hunter_rank} /></dd></div>
        <div><dt>Progress</dt><dd>{s.topics_passed}/{s.topics_total} topics passed</dd></div>
        <div><dt>Activity</dt><dd>{s.recently_active ? 'Recently active (15-minute window)' : 'Not recently active'} · Last activity: {show(s.last_activity_at)}</dd></div>
        <div><dt>Registered</dt><dd>{s.registered_at}</dd></div>
        <div><dt>Last successful login</dt><dd>{show(s.last_successful_login_at)}</dd></div>
        <div><dt>Onboarding</dt><dd>{s.onboarding_state}</dd></div>
        <div><dt>Experience</dt><dd>{s.experience_level?.label || 'Not chosen'}</dd></div>
      </dl>
      <h3>Placement</h3>
      <Record value={s.placement} empty="Not placed yet" fields={[['rank','Placement rank',v => <Rank value={v} />],['correct_count','Correct answers'],['submitted_at','Submitted'],['recommended_topic','Recommended topic',topicRef],['offered_topic','Offered topic',topicRef]]} />
    </section>
    <h2>Topic progress</h2><Table headers={['Topic','Status','Lesson visits','Completed quizzes','Best score','First pass']} rows={s.topics.map(t => [`${t.position}. ${t.title}`,<><Chip value={t.status} /> {t.unlocked ? `unlocked (${t.unlock_source})` : 'locked'}</>,t.lesson_visits,t.completed_attempts,t.best_correct == null ? '—' : `${t.best_correct}/5`,show(t.first_passed_at)])}/>
    <h2>Rank history</h2><Table headers={['Time','Reason','From','To','Topics passed']} rows={s.rank_history.map(h => [h.at,<Chip value={h.reason} />,h.from ? <Rank value={h.from} /> : '—',<Rank value={h.to} />,show(h.topics_passed)])}/>
    <h2>Login history</h2><Table headers={['Time','Outcome']} rows={l.events.map(e => [e.at,<Outcome value={e.outcome} />])}/><h2>Sessions</h2><p>A valid session has no recorded end after the server expiry sweep. It is not proof of an online browser. Closing a browser need not record a logout.</p><Table headers={['Started','Last activity','Session state','Ended']} rows={l.sessions.map(x => [x.started_at,x.last_activity_at,x.ended_at ? ({logged_out:'Logged out',expired:'Expired',ended_by_admin:'Ended by administrator'}[x.end_reason] || x.end_reason) : 'Valid session',show(x.ended_at)])}/><Pages data={l} total={Math.max(l.events_total,l.sessions_total)}/>
  </>}</Load></>;
}
export function AdminEvents() {
  const [s] = useSearchParams(); const r = useApi(signal => get(`/admin/login-events?${qs({per_page:20,...Object.fromEntries(s)})}`,{signal}),[s.toString()]);
  return <>{title('Login events')}<Filters fields={[[ 'outcome','Outcome',['success','wrong_password','account_deactivated','unknown_account']],['since','Since (UTC ISO-8601)']]} /><Load request={r}>{d => <><Table headers={['Time','User','Outcome']} rows={d.events.map(e => [e.at,e.user ? `${e.user.display_name} (#${e.user.id}, ${e.user.role})` : 'Unknown account',<Outcome value={e.outcome} />])}/><Pages data={d}/></>}</Load></>;
}
export function AdminTopics() {
  const r = useApi(signal => get('/admin/topics',{signal}),[]);
  return <>{title('Topics and lessons')}<p>Curriculum titles, order and skill groups are fixed. Readiness requires a published lesson and at least ten valid active quiz questions. Topics can be published or set to draft; curriculum topics cannot be retired.</p><Load request={r}>{d => <Table headers={['Topic','Status','Lesson','Quiz pool','Readiness']} rows={d.map(t => [<Link to={`/admin/topics/${t.id}`}>{t.position}. {t.title}</Link>,<Chip value={t.status} />,<Chip value={t.lesson?.status || 'invalid'} label={t.lesson?.status ? undefined : 'Missing'} />,`${t.quiz_pool_size}/${t.required_pool_size}`,t.is_ready ? <Chip value="approved" label="Ready for students" /> : <Chip value="provisional" label={`${!t.lesson_published ? 'Needs published lesson. ' : ''}${t.quiz_pool_size < t.required_pool_size ? 'Needs more valid questions. ' : ''}${t.status !== 'available' ? 'Topic is draft.' : ''}`.trim()} />])}/>}</Load></>;
}

/** Warn for links, browser back/forward, refresh/close and logout (button handled by Layout). */
export function useDirtyWarning(dirty) {
  const { status } = useAuth();
  const blocker = useBlocker(dirty && status === 'authenticated');
  useEffect(() => {
    if (blocker.state === 'blocked') {
      if (window.confirm('Discard unsaved changes and leave this form?')) blocker.proceed();
      else blocker.reset();
    }
  },[blocker.state]);
  useEffect(() => {
    if (!dirty) return;
    const before = e => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload',before);
    document.body.dataset.adminDirty = 'true';
    return () => { window.removeEventListener('beforeunload',before); delete document.body.dataset.adminDirty; };
  },[dirty]);
}

// One editor owns its original record, draft, conflict comparison and action lock.
// Fetching the current server record NEVER changes the draft or its expected token.
function Editor({ initial, loadCurrent, fields, payload, save, actions = [], children, onCreated }) {
  const [base,setBase] = useState(initial); const [draft,setDraft] = useState(initial);
  const [error,setError] = useState(null); const [current,setCurrent] = useState(null); const [message,setMessage] = useState('');
  const [pending,run] = useAction(); const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; },[]);
  useEffect(() => { if (!initial.id && base.id) onCreated?.(base); },[base.id]);
  const dirty = JSON.stringify(payload(draft)) !== JSON.stringify(payload(base)); useDirtyWarning(dirty);
  const change = (k,v) => setDraft(d => ({...d,[k]:v}));
  const perform = (fn, success = 'Saved successfully.') => run(async () => {
    setError(null); setMessage('');
    try { const record = await fn(base,draft); if (!mounted.current) return; setBase(record); setDraft(record); setCurrent(null); setMessage(success); }
    catch(e) { if (mounted.current) setError(e); }
  });
  const compare = () => run(async () => { try { setCurrent(await loadCurrent()); } catch(e) { setError(e); } });
  const locked = pending || !!current || error?.code === 'edit_conflict';
  return <section className="card admin-editor"><p>Status: {base.status || 'New draft'} · Revision/version: {base.revision ?? base.version ?? 'New'}</p>
    {children?.(base,draft)}<ErrorMessage error={error}/>{message && <Notice tone="success">{message}</Notice>}
    {error?.code === 'edit_conflict' && <Notice tone="warning" title="Your unsaved input is preserved"><p>Another administrator changed this record. Compare before deliberately reloading. No stale write will be retried.</p><button type="button" className="btn btn-secondary" disabled={pending} onClick={compare}>Compare current server record</button></Notice>}
    {current && <div className="admin-comparison"><section><h3>Your unsaved input</h3><Values value={payload(draft)}/></section><section><h3>Current server record</h3><Values value={current}/></section><button className="btn btn-secondary" disabled={pending} onClick={() => { if (window.confirm('Discard your unsaved input and load this server record?')) { setBase(current); setDraft(current); setCurrent(null); setError(null); } }}>Reload server record and discard draft</button></div>}
    <form className="form" onSubmit={e => { e.preventDefault(); if (!locked) perform((b,d) => save(b,payload(d))); }}>
      <fieldset disabled={pending || base.status === 'retired' && fields.retiredReadOnly}>
        {fields.render ? fields.render(draft,change,base) : fields.map(([k,label,type,options,max]) => <Field key={k} label={label} value={draft[k]} type={type} options={options} maxLength={max} onChange={v => change(k,v)}/>)}
      </fieldset><p className="hint">{dirty ? 'Unsaved changes' : 'No unsaved changes'}</p><button className="btn btn-primary" disabled={locked || base.status === 'retired' && fields.retiredReadOnly}>Save changes</button>
    </form><div className="choice-row">{actions.filter(a => !a.when || a.when(base)).map(a => <button key={a.label(base)} type="button" className={`btn ${a.label(base).startsWith('Retire') ? 'btn-danger' : 'btn-secondary'}`} disabled={locked || dirty} onClick={() => { if (window.confirm(a.confirm(base))) perform(b => a.run(b), 'Action completed.'); }}>{a.label(base)}</button>)}</div>
    {dirty && actions.length > 0 && <p className="hint">Save or discard edits before changing availability or approval.</p>}
    {error?.code === 'would_make_topic_not_ready' && <Notice tone="warning" title="Retirement would make the topic unavailable"><p>This requires a separate confirmation to set the topic to draft. Existing attempts and progress remain.</p><button type="button" className="btn btn-secondary" disabled={pending || dirty} onClick={() => { if (window.confirm('Retire this question AND set its topic to draft, hiding new lessons and quizzes?')) perform(b => api('POST',`/admin/questions/${b.id}/retire`,{body:{expected_revision:b.revision,confirm:true}})); }}>Retire question and make topic unavailable</button></Notice>}
  </section>;
}

export function AdminTopic() {
  const {id} = useParams(); const r = useApi(signal => get('/admin/topics',{signal}).then(ts => { const t = ts.find(t => String(t.id) === id); if (!t) throw new Error('Topic not found'); return t; }),[id]);
  return <>{title('Edit topic')}<Link to="/admin/topics">Back to topics</Link><Load request={r}>{t => <><h2>{t.position}. {t.title}</h2><p>Lesson: {t.lesson?.status || 'missing'} · Valid quiz questions: {t.quiz_pool_size}/{t.required_pool_size} · {t.students_unlocked} students unlocked</p><Link to={`/admin/topics/${id}/lesson`}>Edit lesson</Link> · <Link to={`/admin/questions?topic_id=${id}`}>Manage topic questions</Link>
    <Editor key={id} initial={t} loadCurrent={() => get('/admin/topics').then(ts => ts.find(x => x.id === t.id))} fields={[[ 'summary','Summary','textarea',null,255]]} payload={d => ({summary:d.summary})} save={(b,p) => api('PATCH',`/admin/topics/${id}`,{body:{...p,expected_version:b.version}})} actions={['available','draft'].map(status => ({label:()=>status === 'available' ? 'Publish topic' : 'Unpublish topic',when:b=>b.status !== status,confirm:()=>status === 'available' ? 'Publish this topic for eligible students?' : 'Set topic to draft and hide new lessons and quizzes?',run:b=>api('PATCH',`/admin/topics/${id}`,{body:{expected_version:b.version,status}})}))}/></>}</Load></>;
}
export function AdminLesson() {
  const {id} = useParams();
  const fetchLesson = signal => get(`/admin/topics/${id}/lesson`,{signal}).catch(e => { if(e.status === 404) return {topic_id:Number(id),title:'',body_markdown:'',revision:0,status:'draft'}; throw e; });
  const r = useApi(fetchLesson,[id]);
  return <>{title('Edit lesson')}<Link to={`/admin/topics/${id}`}>Back to topic</Link><p>Publishing requires a saved lesson. To unpublish or retire a lesson in an available topic, first set that topic to draft. Editing a published lesson changes what students read next.</p><Load request={r}>{d => <Editor key={id} initial={d} loadCurrent={fetchLesson} fields={[[ 'title','Lesson title','text',null,120],['body_markdown','Lesson Markdown','textarea',null,100000]]} payload={d=>({title:d.title,body_markdown:d.body_markdown})} save={(b,p)=>api('PUT',`/admin/topics/${id}/lesson`,{body:{...p,expected_revision:b.revision}})} actions={['publish','unpublish','retire'].map(action=>({label:()=>`${action[0].toUpperCase()+action.slice(1)} lesson`,when:b=>b.revision > 0 && (action === 'publish' ? b.status !== 'published' : action === 'unpublish' ? b.status === 'published' : b.status !== 'retired'),confirm:()=>`${action} this lesson and affect its availability?`,run:b=>api('POST',`/admin/topics/${id}/lesson/${action}`,{body:{expected_revision:b.revision}})}))}/>}</Load></>;
}

export function AdminQuestions() {
  const [s] = useSearchParams(); const r = useApi(signal => get(`/admin/questions?${qs({per_page:20,...Object.fromEntries(s)})}`,{signal}),[s.toString()]);
  return <>{title('Question management')}<Link className="btn btn-primary" to="/admin/questions/new">Create question</Link><p>Assessment questions contribute to placement group quotas; topic quiz questions contribute to topic readiness. A question may serve both.</p><Filters fields={[[ 'topic_id','Topic ID'],['usage','Usage',['quiz','assessment']],['status','Status',['draft','active','retired']]]}/><Load request={r}>{d=><><Table headers={['Question','Context','Status','Validity']} rows={d.questions.map(q=>[<Link to={`/admin/questions/${q.id}`}>#{q.id} {q.stem}</Link>,`Topic ${q.topic_id} · ${q.skill_group} · ${[q.for_quiz && 'Topic quiz',q.for_assessment && 'Assessment'].filter(Boolean).join(' + ')}`,<Chip value={q.status} />,<Chip value={q.valid ? 'valid' : 'invalid'} label={q.valid ? 'Valid choices' : 'Incomplete choices'} />])}/><Pages data={d}/></>}</Load></>;
}
const questionPayload = d => ({topic_id:Number(d.topic_id),for_quiz:d.for_quiz,for_assessment:d.for_assessment,difficulty:d.difficulty,stem:d.stem,code_snippet:d.code_snippet || null,explanation:d.explanation || null,choices:d.choices.map((c,i)=>({label:String.fromCharCode(65+i),text:c.text,is_correct:c.is_correct}))});
const questionFields = {retiredReadOnly:true, render:(d,change,b) => <>
  <Field label="Topic ID" type="number" min="1" max="18" value={d.topic_id} onChange={v=>change('topic_id',v)} disabled={b.status === 'active'}/>
  <p>Usage and topic become fixed when activated. Skill group is derived from the topic.</p>{['for_quiz','for_assessment'].map(k=><label key={k} className="choice-row"><input type="checkbox" checked={d[k]} disabled={b.status === 'active'} onChange={e=>change(k,e.target.checked)}/>{k === 'for_quiz' ? 'Topic quiz' : 'Placement assessment'}</label>)}
  <Field label="Difficulty" value={d.difficulty} options={['easy','medium','hard']} onChange={v=>change('difficulty',v)}/>
  {[['stem','Question text'],['code_snippet','Python code (display only)'],['explanation','Explanation']].map(([k,l])=><Field key={k} label={l} value={d[k]} type="textarea" maxLength={5000} onChange={v=>change(k,v)}/>)}
  <h3>Choices</h3><p>Two to six choices and exactly one correct choice, plus an explanation, are required to activate. Drafts may be incomplete.</p>
  {d.choices.map((c,i)=><div className="card" key={i}><Field label={`Choice ${String.fromCharCode(65+i)}`} value={c.text} maxLength={1000} onChange={v=>change('choices',d.choices.map((x,j)=>j === i ? {...x,text:v}:x))}/><label><input type="radio" name="correct-choice" checked={c.is_correct} onChange={()=>change('choices',d.choices.map((x,j)=>({...x,is_correct:j === i})))}/> Correct choice {String.fromCharCode(65+i)}</label></div>)}
  <div className="choice-row"><button type="button" className="btn btn-secondary" disabled={d.choices.length >= 6} onClick={()=>change('choices',[...d.choices,{text:'',is_correct:false}])}>Add choice</button><button type="button" className="btn btn-secondary" disabled={!d.choices.length} onClick={()=>change('choices',d.choices.slice(0,-1))}>Remove last choice</button><button type="button" className="btn btn-secondary" onClick={()=>change('choices',d.choices.map(x=>({...x,is_correct:false})))}>Clear correct choice</button></div>
</>};
export function AdminQuestion() {
  const {id} = useParams(); const navigate = useNavigate(); const isNew = id === 'new';
  const fetchQuestion = signal => isNew ? Promise.resolve({topic_id:1,for_quiz:true,for_assessment:false,difficulty:'easy',stem:'',code_snippet:null,explanation:null,choices:[{text:'',is_correct:false},{text:'',is_correct:false}]}) : get(`/admin/questions/${id}`,{signal});
  const r = useApi(fetchQuestion,[id]);
  return <>{title(isNew ? 'Create question' : 'Edit question')}<Link to="/admin/questions">Back to questions</Link><Load request={r}>{d=><Editor key={id} initial={d} loadCurrent={fetchQuestion} fields={questionFields} payload={questionPayload} save={(b,p)=>api(isNew?'POST':'PUT',isNew?'/admin/questions':`/admin/questions/${id}`,{body:isNew?p:{...p,expected_revision:b.revision}})} onCreated={q=>navigate(`/admin/questions/${q.id}`,{replace:true})} actions={isNew ? [] : ['activate','retire'].map(action=>({label:()=>`${action === 'activate' ? 'Activate' : 'Retire'} question`,when:b=>action === 'activate' ? b.status === 'draft' : b.status !== 'retired',confirm:()=>action === 'retire' ? 'Permanently retire this question? Existing attempt snapshots stay unchanged.' : 'Activate this question for its selected usage?',run:b=>api('POST',`/admin/questions/${id}/${action}`,{body:{expected_revision:b.revision}})}))}/>}</Load></>;
}
export function AdminBadges() {
  const r=useApi(signal=>get('/admin/badges',{signal}),[]);
  return <>{title('Badge management')}<Link className="btn btn-primary" to="/admin/badges/new">Create badge</Link><p>Changing a price revokes approval and takes an on-sale badge off sale. Approve the exact saved price before publishing it.</p><Load request={r}>{d=><Table headers={['Badge','Price','Approval','Status','Owners']} rows={d.map(b=>[<Link to={`/admin/badges/${b.id}`}>{b.name}</Link>,mana(b.price),<Chip value={b.price_status} />,<Chip value={b.status} />,b.owners])}/>}</Load></>;
}
const badgePayload=d=>({name:d.name,description:d.description,image_ref:d.image_ref || null,price:Number(d.price)});
export function AdminBadge() {
  const {id}=useParams(); const navigate=useNavigate(); const isNew=id === 'new';
  const fetchBadge=signal=>isNew?Promise.resolve({slug:'',name:'',description:'',image_ref:null,price:1}):get('/admin/badges',{signal}).then(bs=>{const b=bs.find(x=>String(x.id)===id);if(!b)throw new Error('Badge not found');return b;});
  const r=useApi(fetchBadge,[id]);
  const fields={retiredReadOnly:true,render:(d,change)=><>{isNew && <Field label="Slug (immutable after creation)" value={d.slug} maxLength={60} onChange={v=>change('slug',v)}/>}<Field label="Badge name" value={d.name} maxLength={80} onChange={v=>change('name',v)}/><Field label="Description" value={d.description} type="textarea" maxLength={255} onChange={v=>change('description',v)}/><Field label="Price in Mana" type="number" min="1" max="1000000" step="1" value={d.price} onChange={v=>change('price',v)}/><Field label="Artwork reference (optional)" value={d.image_ref} onChange={v=>change('image_ref',v)}/><p className="hint">Leave empty for no artwork. Format: badges/name.png or badges/name.webp; name is 1–63 lowercase letters, digits or hyphens, starting with a letter or digit. No uploads.</p></>};
  const actions=[{label:b=>`Approve current price: ${mana(b.price)}`,when:b=>b.status!=='retired' && b.price_status!=='approved',confirm:b=>`Explicitly approve the current price of ${mana(b.price)} for ${b.name}?`,run:b=>api('POST',`/admin/badges/${id}/approve-price`,{body:{expected_version:b.version,price:b.price}})},...['on_sale','off_sale','retired'].map(status=>({label:()=>({on_sale:'Put on sale',off_sale:'Take off sale',retired:'Retire badge'}[status]),when:b=>b.status!=='retired' && b.status!==status,confirm:b=>status==='on_sale'?`Publish ${b.name} for sale at ${mana(b.price)}?`:status==='retired'?'Permanently retire this badge? Owners retain it and may showcase it.':'Take this badge off sale?',run:b=>api('POST',`/admin/badges/${id}/status`,{body:{expected_version:b.version,status}})}))];
  return <>{title(isNew?'Create badge':'Edit badge')}<Link to="/admin/badges">Back to badges</Link><p>A price change invalidates approval and withdraws an on-sale badge. Saved approval and publication are separate explicit actions.</p><Load request={r}>{d=><Editor key={id} initial={d} loadCurrent={fetchBadge} fields={fields} payload={d=>({...badgePayload(d),...(isNew?{slug:d.slug}:{})})} save={(b,p)=>api(isNew?'POST':'PUT',isNew?'/admin/badges':`/admin/badges/${id}`,{body:isNew?p:{...p,expected_version:b.version}})} onCreated={b=>navigate(`/admin/badges/${b.id}`,{replace:true})} actions={isNew?[]:actions}>{b=><div className="admin-badge-summary">{b.image_url && <BadgeArt url={b.image_url} name={b.name} />}<div><p>Price: {mana(b.price)} · {b.price_status || 'provisional'} · Slug: {b.slug}</p>{b.price_approval && <p>Approved by {b.price_approval.approved_by.display_name} (#{b.price_approval.approved_by.id}) at {b.price_approval.approved_at} for {mana(b.price_approval.price)}.</p>}</div></div>}</Editor>}</Load></>;
}
export function AdminAudit() {
  const [s]=useSearchParams(); const r=useApi(signal=>get(`/admin/audit-log?${qs({per_page:20,...Object.fromEntries(s)})}`,{signal}),[s.toString()]);
  return <>{title('Audit history')}<Filters fields={[[ 'entity_type','Target type',['topic','lesson','question','badge']],['entity_id','Target ID'],['admin_id','Administrator ID'],['action','Action',['create','update','publish','unpublish','retire']],['since','Since (UTC ISO-8601)']]}/><Load request={r}>{d=><>{d.entries.length===0 && <p>No audit entries match.</p>}{d.entries.map(e=><article className="card admin-audit" key={e.id}><h2>{e.action}{e.operation ? ` · ${e.operation}`:''} · {e.entity_type} #{e.entity_id}</h2><p>#{e.id} · {e.at} · {e.admin.display_name} (administrator #{e.admin.id})</p><div className="grid-2"><section><h3>Before</h3><Values value={e.before}/></section><section><h3>After</h3><Values value={e.after}/></section></div></article>)}<Pages data={d}/></>}</Load></>;
}
