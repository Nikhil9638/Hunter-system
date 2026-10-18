import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { get, post } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { LoadError, Loading, Notice, PageHeader, StatusLabel, TableScroll, fmtDate } from '../components/ui.jsx';
import { HudPanel, ProgressMeter } from '../components/hud.jsx';
import { Icon } from '../components/Icon.jsx';
import { gateName } from '../config/presentation.js';

/**
 * Mission state for display. Precedence (docs/frontend-design-spec.md §4.3):
 *  1. The server's `status` is used as-is. PHP derives it (v_student_topic_status) as
 *     passed > in_progress > completed > available > locked; the browser never
 *     re-derives it from attempts, scores or lesson visits.
 *  2. One overlay: an unlocked topic whose status is "available" but whose content is
 *     not ready is shown as "no_content" (availability and readiness are separate facts).
 *  3. Anything else (e.g. an unexpected value) is passed through and shown neutrally.
 */
export const topicStatus = (t) => (t.unlocked && !t.content_ready && t.status === 'available' ? 'no_content' : t.status);

/** The same "current topic" rule the dashboard uses (DashboardService): first unlocked, not passed. */
const activeMissionId = (topics) => topics.find((t) => t.unlocked && t.status !== 'passed')?.id ?? null;

function missionAction(t, st) {
  const label = { available: 'Start mission', in_progress: 'Resume quiz', completed: 'Retry mission', passed: 'Review mission' }[st];
  if (st === 'in_progress' && t.open_attempt_id) return { to: `/quiz/${t.open_attempt_id}`, label };
  if (label) return { to: `/topics/${t.id}`, label };
  if (t.unlocked && !['locked', 'no_content'].includes(st)) return { to: `/topics/${t.id}`, label: 'Open mission' };   // unknown status
  return null;
}

export function TopicsPage() {
  const res = useApi((signal) => get('/topics', { signal }), []);
  if (res.loading) return <Loading />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const topics = res.data;
  const gates = [];
  for (const t of topics) {                            // API order; gates appear in curriculum order
    let g = gates.find((x) => x.code === t.skill_group);
    if (!g) gates.push(g = { code: t.skill_group, topics: [] });
    g.topics.push(t);
  }
  const cleared = topics.filter((t) => t.status === 'passed').length;
  const active = activeMissionId(topics);
  return (
    <div className="missions-page">
      <PageHeader title="Missions">
        <p className="lead">Clear a mission's quiz (3 of 5 correct) to unlock the next one. Unlocks and progress are recorded by the server.</p>
      </PageHeader>
      <div className="missions-summary">
        <ProgressMeter label="Missions cleared" value={cleared} max={topics.length} />
      </div>
      <div className="gates-board">
        {gates.map((g) => {
          const gateCleared = g.topics.filter((t) => t.status === 'passed').length;
          return (
            <section key={g.code} className="gate-panel" aria-labelledby={`gate-${g.code}`}>
              <header className="gate-panel-head">
                <h2 id={`gate-${g.code}`} className="gate-panel-title"><Icon name="gate" />{gateName(g.code)}</h2>
                <span className="gate-panel-count">{gateCleared} of {g.topics.length} cleared</span>
              </header>
              <ol className="missions">
                {g.topics.map((t) => {
                  const st = topicStatus(t);
                  const action = missionAction(t, st);
                  const isActive = t.id === active;
                  return (
                    <li key={t.id}>
                      <article className={`mission mission-${st in STATE_CLASSES ? st : 'unknown'}${isActive ? ' is-active' : ''}`} aria-label={`Mission ${t.position}: ${t.title}`}>
                        <span className="mission-pos" aria-hidden="true">{String(t.position).padStart(2, '0')}</span>
                        <div className="mission-body">
                          <h3 className="mission-title">{t.title}{isActive && <span className="mission-active">Active</span>}</h3>
                          <p className="mission-meta">
                            <StatusLabel status={st} />
                            {t.best_correct !== null && <span className="muted"> · best {t.best_correct}/5</span>}
                            {t.lesson_visited && <span className="muted"> · lesson read</span>}
                          </p>
                          {st === 'locked' && <p className="mission-hint">Clear the previous mission to unlock.</p>}
                        </div>
                        {action && <Link className={`btn ${isActive || st === 'in_progress' ? 'btn-primary' : 'btn-secondary'} btn-small mission-action`} to={action.to}>
                          {action.label}<span className="sr-only">: {t.title}</span></Link>}
                      </article>
                    </li>
                  );
                })}
              </ol>
            </section>
          );
        })}
      </div>
    </div>
  );
}
const STATE_CLASSES = { locked: 1, no_content: 1, available: 1, in_progress: 1, completed: 1, passed: 1 };

/** One topic: choose lesson-first or the quiz directly; resume; attempt history. */
export function TopicPage() {
  const { topicId } = useParams();
  const navigate = useNavigate();
  const res = useApi(async (signal) => {
    const topics = await get('/topics', { signal });
    const topic = topics.find((t) => String(t.id) === topicId) ?? null;
    const attempts = topic?.unlocked ? await get(`/topics/${topicId}/quiz-attempts`, { signal }) : [];
    return { topic, attempts };
  }, [topicId]);
  const [pending, run] = useAction();
  const [error, setError] = useState(null);

  if (res.loading) return <Loading />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const { topic, attempts } = res.data;
  if (!topic) return <Notice tone="error" title="Topic not found" />;

  const start = () => run(async () => {
    setError(null);
    try {
      const a = await post(`/topics/${topic.id}/quiz-attempts`, { started_via: 'direct' });
      navigate(`/quiz/${a.attempt_id}`);
    } catch (err) {
      setError(err.message);
    }
  });
  const st = topicStatus(topic);

  return (
    <div className="mission-briefing">
      <PageHeader title={`${topic.position}. ${topic.title}`}>
        <p className="briefing-meta"><span className="eyebrow">{gateName(topic.skill_group)}</span> <StatusLabel status={st} /></p>
      </PageHeader>
      {!topic.unlocked && <Notice tone="info" title="Locked">Pass the previous topic's quiz to unlock this one.</Notice>}
      {st === 'no_content' && <Notice tone="info" title="Coming soon">This topic is unlocked for you, but its lesson and quiz are not available yet.</Notice>}
      {topic.unlocked && topic.content_ready && (
        <HudPanel variant="notice" eyebrow="Mission briefing" title="How do you want to learn?">
          <div className="choice-row">
            {topic.open_attempt_id
              ? <Link className="btn btn-primary" to={`/quiz/${topic.open_attempt_id}`}>Resume your quiz</Link>
              : <>
                  <Link className="btn btn-primary" to={`/topics/${topic.id}/lesson`}>Read the lesson first</Link>
                  <button type="button" className="btn btn-secondary" onClick={start} disabled={pending}>{pending ? 'Starting…' : 'Take the quiz directly'}</button>
                </>}
          </div>
          <ul className="briefing-rules">
            <li>5 questions. Passing needs 3 correct, and passing unlocks the next mission.</li>
            <li>Your first completed quiz in a mission earns 10 Mana, even if you don't pass.</li>
            {topic.best_correct !== null && <li>Your best so far: {topic.best_correct}/5.</li>}
          </ul>
          {error && <Notice tone="error">{error}</Notice>}
        </HudPanel>
      )}
      {attempts.length > 0 && (
        <HudPanel variant="quiet" title="Your attempts">
          <TableScroll label="Your quiz attempts"><table className="table">
            <thead><tr><th scope="col">#</th><th scope="col">Result</th><th scope="col">Submitted</th><th scope="col"><span className="sr-only">Open</span></th></tr></thead>
            <tbody>
              {attempts.slice().reverse().map((a) => (
                <tr key={a.attempt_id}>
                  <td>{a.attempt_number}</td>
                  <td>{a.status === 'in_progress' ? 'In progress' : `${a.grade} · ${a.correct_count}/5 · ${a.passed ? 'passed' : 'not passed'}`}</td>
                  <td>{fmtDate(a.submitted_at)}</td>
                  <td><Link to={`/quiz/${a.attempt_id}`}>{a.status === 'in_progress' ? 'Resume' : 'Review'}</Link></td>
                </tr>
              ))}
            </tbody>
          </table></TableScroll>
        </HudPanel>
      )}
      <p><Link to="/topics">Back to missions</Link></p>
    </div>
  );
}
