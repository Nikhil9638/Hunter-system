import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError, get, isAbort, post, put } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useApi } from '../lib/useApi.js';
import { QuestionRunner, Review } from '../components/QuestionRunner.jsx';
import { LoadError, Loading, Notice, PageHeader, RankBadge, TopicLink } from '../components/ui.jsx';
import { HudPanel, ProgressMeter } from '../components/hud.jsx';
import { EXPERIENCE_CARDS, RANKS, rankCode } from '../config/presentation.js';

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
  // Every value below comes from the stored server result (GET /assessment/result).
  const card = r.experience_level ? EXPERIENCE_CARDS[r.experience_level.code] : null;
  const placed = rankCode(r.placement_rank);
  return (
    <div className="placement-result">
      <PageHeader title="Your starting point">
        <p className="lead">{r.label}.</p>
      </PageHeader>
      <div className="placement-top">
        <HudPanel variant="notice" eyebrow="System notification" title="Placement complete" className="placement-notice">
          <div className="placement-rank">
            <p className="placement-rank-label">Starting Hunter rank</p>
            <RankBadge rank={r.placement_rank} size="lg" />
            {placed && RANKS[placed].lore && <p className="placement-lore lore">{RANKS[placed].lore}</p>}
          </div>
          <ProgressMeter label="Placement check score" value={r.correct_count} max={r.question_count} valueText={`${r.correct_count} of ${r.question_count} correct`} />
          <p className="placement-note">Your Hunter rank starts here and only goes up as you clear missions.</p>
        </HudPanel>
        <div className="placement-side">
          <HudPanel variant="quiet" eyebrow="Your description" title="Self-reported experience" headingLevel={2}>
            {r.experience_level
              ? <p>You described yourself as: <strong>{r.experience_level.label}</strong>{card && <> (theme card: {card.character})</>}.</p>
              : <p className="muted">No experience description recorded.</p>}
            <p className="hint">This was your own choice. It was not used for your result or your rank.</p>
          </HudPanel>
          <HudPanel variant="quiet" eyebrow="Next" title="Where to start" headingLevel={2}>
            <p>Recommended topic: <TopicLink topic={r.recommended_topic} />{!r.recommended_topic.ready && ' (content coming soon)'}</p>
            {r.offered_topic && <p>Available now: <TopicLink topic={r.offered_topic} /> — the nearest earlier topic with content.</p>}
            <Link className="btn btn-primary" to="/dashboard">Go to your dashboard</Link>
          </HudPanel>
        </div>
      </div>
      <HudPanel variant="quiet" title="Skill groups">
        <p className="hint">Based on one or two questions per group, so this is a hint about where to look, not proof of mastery.</p>
        <ul className="group-meters">
          {r.groups.map((g) => (
            <li key={g.code}>
              <ProgressMeter label={`${g.code} · ${g.name}`} value={g.correct} max={g.asked} valueText={`${g.correct} of ${g.asked}`} />
            </li>
          ))}
        </ul>
      </HudPanel>
      <HudPanel variant="quiet" title="Topic evidence">
        <ul className="evidence">
          {r.topics.map((t) => (
            <li key={t.id} className={t.evidence === 'not_assessed' ? 'is-unchecked' : ''}><span>{t.title}</span>
              <span className="muted">{t.evidence === 'not_assessed' ? 'not checked' : `${t.correct} of ${t.asked} correct`}</span></li>
          ))}
        </ul>
      </HudPanel>
      <HudPanel variant="quiet" title="Review your answers"><Review items={r.review} /></HudPanel>
    </div>
  );
}
