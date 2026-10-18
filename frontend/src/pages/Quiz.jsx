import { useCallback, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ApiError, get, post, put } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { QuestionRunner, Review } from '../components/QuestionRunner.jsx';
import { LoadError, Loading, Notice, PageHeader, RankBadge } from '../components/ui.jsx';
import { HudPanel, ManaAmount } from '../components/hud.jsx';
import { Icon } from '../components/Icon.jsx';
import { RANKS, mana, rankCode } from '../config/presentation.js';

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

  // Outcome, reward, unlock and promotion are facts of this attempt as returned by the
  // server (QuizResult); each panel appears only when the server provides it.
  const toCode = r.promotion ? rankCode(r.promotion.to) : null;
  return (
    <div className={`quiz-result ${r.passed ? 'is-cleared' : 'is-not-cleared'}`}>
      <PageHeader title={r.passed ? 'Mission cleared' : 'Mission not cleared'}>
        <p className="result-perf">{r.label}: {r.grade} ({r.percent}%)</p>
        <p className="lead">{r.correct_count} of {r.question_count} correct — {r.passed ? 'passed' : 'not passed (3 correct needed)'}.</p>
      </PageHeader>
      {r.already_submitted && <Notice tone="info">This attempt had already been submitted; this is its recorded result.</Notice>}
      <div className="result-grid">
        <HudPanel variant="notice" eyebrow="System notification" title={r.passed ? 'Mission cleared' : 'Mission not cleared'} headingLevel={2} className="result-outcome">
          <div className="result-outcome-body">
            <p className={`grade grade-${r.grade}`}><span aria-hidden="true">{r.grade}</span><span className="sr-only">Grade {r.grade}</span></p>
            <div>
              <p className="result-outcome-line"><Icon name={r.passed ? 'seal' : 'info'} />{r.passed ? 'Passed' : 'Not passed'}</p>
              <p className="muted small">{r.percent}% · {r.correct_count} of {r.question_count} correct</p>
            </div>
          </div>
        </HudPanel>
        <HudPanel variant="quiet" eyebrow="Reward" title="Mana" headingLevel={2}>
          {r.reward
            ? <p><strong className="mana">+{mana(r.reward.coins)}</strong> for your first completed quiz in this mission. Balance after: <ManaAmount amount={r.reward.balance_after} />.</p>
            : <p>No Mana this time: the first-completion reward for this mission has already been paid (it is paid once, pass or fail).</p>}
        </HudPanel>
        <HudPanel variant="quiet" eyebrow="Progress" title="Unlocks" headingLevel={2}>
          {r.unlocked_topic
            ? <p>Unlocked: <Link to={`/topics/${r.unlocked_topic.id}`}>{r.unlocked_topic.title}</Link>{!r.unlocked_topic.content_ready && ' (content coming soon)'}</p>
            : <p className="muted">{r.passed ? 'No new topic unlocked by this attempt.' : 'Pass to unlock the next topic.'}</p>}
        </HudPanel>
      </div>
      {r.promotion && (
        <HudPanel variant="notice" eyebrow="System notification" title="Rank up" headingLevel={2} className="result-promotion">
          <p className="promotion"><RankBadge rank={r.promotion.from} size="sm" /> <span aria-hidden="true">→</span><span className="sr-only">to</span> <RankBadge rank={r.promotion.to} /></p>
          {toCode && RANKS[toCode].lore && <p className="lore muted">{RANKS[toCode].lore}</p>}
          <p className="hint">Promoted after clearing {r.promotion.topics_passed} different missions. Your rank never goes down.</p>
        </HudPanel>
      )}
      <div className="choice-row">
        <button type="button" className="btn btn-primary" onClick={retry} disabled={pending}>{pending ? 'Starting…' : 'Try again'}</button>
        <Link className="btn btn-secondary" to={`/topics/${r.topic_id}/lesson`}>Read the lesson</Link>
        <Link className="btn btn-ghost" to="/dashboard">Dashboard</Link>
      </div>
      {error && <Notice tone="error">{error}</Notice>}
      <HudPanel variant="quiet" title="Review"><Review items={r.review} /></HudPanel>
    </div>
  );
}
