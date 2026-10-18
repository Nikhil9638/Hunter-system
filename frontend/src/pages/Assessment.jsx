import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError, get, isAbort, post, put } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useApi } from '../lib/useApi.js';
import { QuestionRunner, Review } from '../components/QuestionRunner.jsx';
import { Card, LoadError, Loading, Notice, PageHeader, RankBadge, TableScroll, TopicLink } from '../components/ui.jsx';

/** Start or resume the placement assessment (server-owned; refresh shows saved answers). */
export function AssessmentPage() {
  const navigate = useNavigate();
  const [attempt, setAttempt] = useState(null);
  const [error, setError] = useState(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const ctrl = new AbortController();
    setError(null);
    post('/assessment', undefined, { signal: ctrl.signal })           // 201 new / 200 resumed: same attempt either way
      .then(setAttempt)
      .catch(async (err) => {
        if (isAbort(err)) return;
        if (err instanceof ApiError && err.code === 'placement_already_recorded') {
          navigate('/assessment/result', { replace: true });
          return;
        }
        setError(err);
      });
    return () => ctrl.abort();
  }, [tick, navigate]);

  const saveAnswer = useCallback((position, choiceId) => put(`/assessment/${attempt.attempt_id}/answers/${position}`, { choice_id: choiceId }), [attempt]);
  const submit = useCallback(async () => {
    await post(`/assessment/${attempt.attempt_id}/submit`);
    // Only navigate: the result page confirms the stored placement and then updates the
    // cached onboarding state (updating it here would let this page's guard redirect
    // to the dashboard before the result is shown).
    navigate('/assessment/result', { replace: true });
  }, [attempt, navigate]);
  const onFinalized = useCallback(() => {          // submitted elsewhere (another tab): show the stored result
    navigate('/assessment/result', { replace: true });
  }, [navigate]);

  return (
    <div className="narrow-wide">
      <PageHeader title="Placement check">
        <p className="lead">10 questions across the course. It sets your starting point, not a final judgement. Answers are saved as you go; you can leave and come back.</p>
      </PageHeader>
      {error && <LoadError error={error} onRetry={() => setTick((t) => t + 1)} />}
      {!attempt && !error && <Loading what="Preparing your questions" />}
      {attempt && (
        <>
          {attempt.resumed && <Notice tone="info">Welcome back. Your saved answers are restored ({attempt.answered_count} of {attempt.question_count}).</Notice>}
          <QuestionRunner attempt={attempt} saveAnswer={saveAnswer} submit={submit} submitLabel="Submit placement check" onFinalized={onFinalized} />
        </>
      )}
    </div>
  );
}

export function AssessmentResultPage() {
  const { user, setOnboarding } = useAuth();
  const res = useApi((signal) => get('/assessment/result', { signal }), []);
  useEffect(() => {                                   // the stored result is proof of placement: sync the cached state
    if (res.data && user.onboarding_state !== 'assessment_submitted') setOnboarding('assessment_submitted');
  }, [res.data, user.onboarding_state, setOnboarding]);
  if (res.loading) return <Loading />;
  if (res.error instanceof ApiError && res.error.status === 404) {
    return <Notice tone="info" title="No placement result yet" action={<Link to="/assessment">Go to the placement check</Link>} />;
  }
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const r = res.data;
  return (
    <div>
      <PageHeader title="Your starting point">
        <p className="lead">{r.label}.</p>
      </PageHeader>
      <div className="grid-2">
        <Card title="Placement result">
          <RankBadge rank={r.placement_rank} size="lg" />
          <p>{r.correct_count} of {r.question_count} correct. Your Hunter rank starts here and only goes up as you pass topics.</p>
          {r.experience_level && <p className="muted">You described yourself as: {r.experience_level.label} (not used for the result).</p>}
        </Card>
        <Card title="Where to start">
          <p>Recommended topic: <TopicLink topic={r.recommended_topic} />{!r.recommended_topic.ready && ' (content coming soon)'}</p>
          {r.offered_topic && <p>Available now: <TopicLink topic={r.offered_topic} /> — the nearest earlier topic with content.</p>}
          <Link className="btn btn-primary" to="/dashboard">Go to your dashboard</Link>
        </Card>
      </div>
      <Card title="Skill groups">
        <p className="hint">Based on one or two questions per group, so this is a hint about where to look, not proof of mastery.</p>
        <TableScroll label="Placement skill groups"><table className="table">
          <thead><tr><th scope="col">Group</th><th scope="col">Correct</th></tr></thead>
          <tbody>{r.groups.map((g) => <tr key={g.code}><td>{g.code} · {g.name}</td><td>{g.correct} of {g.asked}</td></tr>)}</tbody>
        </table></TableScroll>
      </Card>
      <Card title="Topic evidence">
        <ul className="evidence">
          {r.topics.map((t) => (
            <li key={t.id}><span>{t.title}</span>
              <span className="muted">{t.evidence === 'not_assessed' ? 'not checked' : `${t.correct} of ${t.asked} correct`}</span></li>
          ))}
        </ul>
      </Card>
      <Card title="Review your answers"><Review items={r.review} /></Card>
    </div>
  );
}
