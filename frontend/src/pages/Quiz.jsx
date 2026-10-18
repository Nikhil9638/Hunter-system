import { useCallback, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ApiError, get, post, put } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { QuestionRunner, Review } from '../components/QuestionRunner.jsx';
import { Card, LoadError, Loading, Notice, PageHeader, RankBadge } from '../components/ui.jsx';

/** /quiz/:attemptId shows the attempt while in progress and the stored result once submitted. */
export function QuizPage() {
  const { attemptId } = useParams();
  const res = useApi((signal) => get(`/quiz-attempts/${attemptId}`, { signal }), [attemptId]);

  const saveAnswer = useCallback((position, choiceId) => put(`/quiz-attempts/${attemptId}/answers/${position}`, { choice_id: choiceId }), [attemptId]);
  const submit = useCallback(async () => {
    const result = await post(`/quiz-attempts/${attemptId}/submit`);
    res.setData(result);
  }, [attemptId, res]);

  if (res.loading) return <Loading what="Loading the quiz" />;
  if (res.error) {
    if (res.error instanceof ApiError && res.error.status === 404) return <Notice tone="error" title="Quiz not found" action={<Link to="/topics">Back to topics</Link>} />;
    return <LoadError error={res.error} onRetry={res.reload} />;
  }
  const a = res.data;
  if (a.status === 'in_progress') {
    return (
      <div className="narrow-wide">
        <PageHeader title={`Topic quiz · attempt ${a.attempt_number}`}>
          <p className="lead">Answers are saved as you choose them. You can leave and resume later.</p>
        </PageHeader>
        <QuestionRunner attempt={a} saveAnswer={saveAnswer} submit={submit} submitLabel="Submit quiz" onFinalized={res.reload} />
      </div>
    );
  }
  return <QuizResult r={a} />;
}

function QuizResult({ r }) {
  const navigate = useNavigate();
  const [pending, run] = useAction();
  const [error, setError] = useState(null);
  const retry = () => run(async () => {
    setError(null);
    try {
      const a = await post(`/topics/${r.topic_id}/quiz-attempts`, { started_via: 'direct' });
      navigate(`/quiz/${a.attempt_id}`);
    } catch (err) {
      setError(err.message);
    }
  });

  return (
    <div>
      <PageHeader title={`${r.label}: ${r.grade} (${r.percent}%)`}>
        <p className="lead">{r.correct_count} of {r.question_count} correct — {r.passed ? 'passed' : 'not passed (3 correct needed)'}.</p>
      </PageHeader>
      {r.already_submitted && <Notice tone="info">This attempt had already been submitted; this is its recorded result.</Notice>}
      <div className="grid-3">
        <Card title="Quiz grade" className="stat">
          <p className={`grade grade-${r.grade}`}><span aria-hidden="true">{r.grade}</span><span className="sr-only">Grade {r.grade}</span></p>
          <p className="muted">{r.passed ? '✓ Passed' : '✕ Not passed'}</p>
        </Card>
        <Card title="Coins" className="stat">
          {r.reward
            ? <p><strong>+{r.reward.coins} coins</strong> for your first completed quiz in this topic. Balance after: {r.reward.balance_after}.</p>
            : <p>No coins this time: the first-completion reward for this topic has already been paid (it is paid once, pass or fail).</p>}
        </Card>
        <Card title="Progress" className="stat">
          {r.unlocked_topic
            ? <p>Unlocked: <Link to={`/topics/${r.unlocked_topic.id}`}>{r.unlocked_topic.title}</Link>{!r.unlocked_topic.content_ready && ' (content coming soon)'}</p>
            : <p className="muted">{r.passed ? 'No new topic unlocked by this attempt.' : 'Pass to unlock the next topic.'}</p>}
          {r.promotion && (
            <p className="promotion">Rank up! <RankBadge rank={{ code: r.promotion.from }} size="sm" /> → <RankBadge rank={{ code: r.promotion.to }} size="sm" /></p>
          )}
        </Card>
      </div>
      <div className="choice-row">
        <button type="button" className="btn btn-primary" onClick={retry} disabled={pending}>{pending ? 'Starting…' : 'Try again'}</button>
        <Link className="btn btn-secondary" to={`/topics/${r.topic_id}/lesson`}>Read the lesson</Link>
        <Link className="btn btn-ghost" to="/dashboard">Dashboard</Link>
      </div>
      {error && <Notice tone="error">{error}</Notice>}
      <Card title="Review"><Review items={r.review} /></Card>
    </div>
  );
}
