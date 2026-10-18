import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { get, post } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { Card, LoadError, Loading, Notice, PageHeader, StatusLabel, TableScroll, fmtDate } from '../components/ui.jsx';

/** Locked vs unlocked-without-content vs ready are separate facts (api-contract §5). */
export const topicStatus = (t) => (t.unlocked && !t.content_ready && t.status === 'available' ? 'no_content' : t.status);

export function TopicsPage() {
  const res = useApi((signal) => get('/topics', { signal }), []);
  if (res.loading) return <Loading />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  return (
    <div>
      <PageHeader title="Topics">
        <p className="lead">Pass a topic's quiz (3 of 5) to unlock the next one.</p>
      </PageHeader>
      <ol className="roadmap">
        {res.data.map((t) => {
          const st = topicStatus(t);
          return (
            <li key={t.id} className={`roadmap-item ri-${st}`}>
              <span className="ri-pos" aria-hidden="true">{t.position}</span>
              <div className="ri-body">
                {t.unlocked
                  ? <Link className="ri-title" to={`/topics/${t.id}`}>{t.title}</Link>
                  : <span className="ri-title">{t.title}</span>}
                <span className="ri-meta"><StatusLabel status={st} />
                  {t.best_correct !== null && <span className="muted"> · best {t.best_correct}/5</span>}
                  {t.lesson_visited && <span className="muted"> · lesson read</span>}</span>
              </div>
            </li>
          );
        })}
      </ol>
    </div>
  );
}

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
    <div>
      <PageHeader title={`${topic.position}. ${topic.title}`}><StatusLabel status={st} /></PageHeader>
      {!topic.unlocked && <Notice tone="info" title="Locked">Pass the previous topic's quiz to unlock this one.</Notice>}
      {st === 'no_content' && <Notice tone="info" title="Coming soon">This topic is unlocked for you, but its lesson and quiz are not available yet.</Notice>}
      {topic.unlocked && topic.content_ready && (
        <Card title="How do you want to learn?">
          <div className="choice-row">
            {topic.open_attempt_id
              ? <Link className="btn btn-primary" to={`/quiz/${topic.open_attempt_id}`}>Resume your quiz</Link>
              : <>
                  <Link className="btn btn-primary" to={`/topics/${topic.id}/lesson`}>Read the lesson first</Link>
                  <button type="button" className="btn btn-secondary" onClick={start} disabled={pending}>{pending ? 'Starting…' : 'Take the quiz directly'}</button>
                </>}
          </div>
          <p className="hint">5 questions. Passing needs 3 correct. Your first completed quiz in a topic earns 10 coins, even if you don't pass.</p>
          {error && <Notice tone="error">{error}</Notice>}
        </Card>
      )}
      {attempts.length > 0 && (
        <Card title="Your attempts">
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
        </Card>
      )}
    </div>
  );
}
