import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, isAbort } from '../api/client.js';
import { CodeBlock } from '../lib/markdown.jsx';
import { Notice } from './ui.jsx';

/**
 * Answer an attempt one question at a time (placement assessment or topic quiz).
 * - The attempt (questions, saved selections) is owned by the server; this view
 *   starts from what the server returned, so refresh/resume shows saved answers.
 * - Saves go through ONE queue, in the order the student chose, so the last choice
 *   is always the last one stored. Each answer shows saving / saved / not saved.
 * - Moving on is blocked while the current answer failed to save (no hidden
 *   failure); Submit waits for every pending save and refuses if any failed.
 * - Nothing is retried automatically.
 *
 * props: attempt {attempt_id, questions[]}, saveAnswer(position, choiceId) => Promise,
 *        submit() => Promise, submitLabel, onFinalized() (the server says it is already submitted)
 */
export function QuestionRunner({ attempt, saveAnswer, submit, submitLabel = 'Submit answers', onFinalized }) {
  const questions = attempt.questions;
  const [selected, setSelected] = useState(() => Object.fromEntries(questions.map((q) => [q.position, q.selected_choice_id])));
  const [saveState, setSaveState] = useState(() => Object.fromEntries(questions.map((q) => [q.position, q.selected_choice_id ? 'saved' : 'empty'])));
  const [saveError, setSaveError] = useState({});
  const [index, setIndex] = useState(() => Math.max(0, questions.findIndex((q) => !q.selected_choice_id)));
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);
  const [announce, setAnnounce] = useState('');
  const queue = useRef(Promise.resolve());
  const statusRef = useRef({ ...saveState });                 // synchronous mirror for the submit check
  const setStatus = (position, st) => {
    statusRef.current = { ...statusRef.current, [position]: st };
    setSaveState((s) => ({ ...s, [position]: st }));
  };
  const seq = useRef({});
  const mounted = useRef(true);
  useEffect(() => () => { mounted.current = false; }, []);

  const q = questions[index];
  const total = questions.length;

  const choose = useCallback((position, choiceId) => {
    setSelected((s) => ({ ...s, [position]: choiceId }));
    setStatus(position, 'saving');
    setSaveError((e) => ({ ...e, [position]: null }));
    setAnnounce(`Saving answer for question ${position}`);
    const mySeq = (seq.current[position] ?? 0) + 1;
    seq.current[position] = mySeq;
    queue.current = queue.current.then(async () => {
      try {
        await saveAnswer(position, choiceId);
        if (mounted.current && seq.current[position] === mySeq) {
          setStatus(position, 'saved');
          setAnnounce(`Answer for question ${position} saved`);
        }
      } catch (err) {
        if (isAbort(err) || !mounted.current) return;
        if (err instanceof ApiError && err.code === 'attempt_finalized') {
          onFinalized?.();
          return;
        }
        if (seq.current[position] === mySeq) {
          setStatus(position, 'error');
          setSaveError((e) => ({ ...e, [position]: err.message }));
          setAnnounce(`Answer for question ${position} was not saved`);
        }
      }
    });
  }, [saveAnswer, onFinalized]);

  const failed = questions.filter((x) => saveState[x.position] === 'error').map((x) => x.position);
  const unanswered = questions.filter((x) => !selected[x.position]).map((x) => x.position);
  const currentFailed = saveState[q.position] === 'error';

  const onSubmit = async () => {
    if (submitting) return;
    setSubmitError(null);
    setSubmitting(true);
    try {
      await queue.current;                              // wait for every pending answer save
      const stillFailed = questions.filter((x) => statusRef.current[x.position] === 'error').map((x) => x.position);
      if (stillFailed.length) {
        setSubmitError(`Some answers were not saved (question ${stillFailed.join(', ')}). Choose them again before submitting.`);
        return;
      }
      await submit();
    } catch (err) {
      if (err instanceof ApiError && err.code === 'attempt_incomplete') {
        setSubmitError(`Answer every question first. Not answered: ${err.details.unanswered_positions?.join(', ')}.`);
      } else if (err instanceof ApiError && err.status === 0) {
        setSubmitError('We could not confirm the submission. Reload this page to see whether it was recorded before trying again.');
      } else if (!isAbort(err)) {
        setSubmitError(err.message);
      }
    } finally {
      if (mounted.current) setSubmitting(false);
    }
  };

  return (
    <div className="runner">
      <p className="sr-only" aria-live="polite">{announce}</p>
      <nav className="runner-map" aria-label="Questions">
        <ol>
          {questions.map((x, i) => (
            <li key={x.position}>
              <button type="button" className={`map-btn map-${saveState[x.position]}${i === index ? ' map-current' : ''}`}
                      aria-current={i === index ? 'step' : undefined} disabled={currentFailed && i !== index}
                      onClick={() => setIndex(i)}>
                <span>{x.position}</span>
                <span className="sr-only">{{ empty: ', not answered', saving: ', saving', saved: ', answered', error: ', not saved' }[saveState[x.position]]}</span>
              </button>
            </li>
          ))}
        </ol>
      </nav>

      <fieldset className="question" key={q.position}>
        <legend>
          <span className="q-count">Question {index + 1} of {total}</span>
          <span className="q-stem">{q.stem}</span>
        </legend>
        <CodeBlock code={q.code_snippet} />
        <div className="choices">
          {q.choices.map((c) => (
            <label key={c.id} className={`choice${selected[q.position] === c.id ? ' choice-selected' : ''}`}>
              <input type="radio" name={`q-${q.position}`} value={c.id} checked={selected[q.position] === c.id}
                     disabled={submitting} onChange={() => choose(q.position, c.id)} />
              <span className="choice-label">{c.label}</span>
              <span className="choice-text">{c.text}</span>
            </label>
          ))}
        </div>
        <SaveStatus state={saveState[q.position]} error={saveError[q.position]}
                    onRetry={() => choose(q.position, selected[q.position])} />
      </fieldset>

      <div className="runner-actions">
        <button type="button" className="btn btn-secondary" disabled={index === 0 || currentFailed} onClick={() => setIndex(index - 1)}>Previous</button>
        {index < total - 1
          ? <button type="button" className="btn btn-primary" disabled={currentFailed} onClick={() => setIndex(index + 1)}>Next question</button>
          : <button type="button" className="btn btn-primary" disabled={submitting || unanswered.length > 0 || failed.length > 0} onClick={onSubmit}>
              {submitting ? 'Submitting…' : submitLabel}
            </button>}
      </div>
      {currentFailed && <p className="hint">Fix the unsaved answer before moving on.</p>}
      {index === total - 1 && unanswered.length > 0 && (
        <p className="hint">Not answered yet: question {unanswered.join(', ')}.</p>
      )}
      {failed.length > 0 && !currentFailed && (
        <Notice tone="error" title="Some answers were not saved">Question {failed.join(', ')}. Open them and choose again.</Notice>
      )}
      {submitError && <Notice tone="error" title="Not submitted">{submitError}</Notice>}
    </div>
  );
}

function SaveStatus({ state, error, onRetry }) {
  if (state === 'saving') return <p className="save save-saving"><span className="spinner" aria-hidden="true" /> Saving…</p>;
  if (state === 'saved') return <p className="save save-saved"><span aria-hidden="true">✓</span> Saved</p>;
  if (state === 'error') {
    return (
      <div className="save save-error" role="alert">
        <span aria-hidden="true">✕</span> Not saved: {error}{' '}
        <button type="button" className="btn btn-small btn-secondary" onClick={onRetry}>Retry saving</button>
      </div>
    );
  }
  return <p className="save save-empty">Choose one answer.</p>;
}

/** Post-submission review: the student's choice, the correct choice and the explanation. */
export function Review({ items }) {
  return (
    <ol className="review">
      {items.map((it) => (
        <li key={it.position} className={`review-item ${it.is_correct ? 'is-correct' : 'is-wrong'}`}>
          <p className="review-head">
            <span className="review-mark">{it.is_correct ? '✓ Correct' : '✕ Incorrect'}</span>
            <span className="muted"> Question {it.position}{it.topic ? ` · ${it.topic}` : ''}</span>
          </p>
          <p className="review-stem">{it.stem}</p>
          <CodeBlock code={it.code_snippet} />
          <ul className="review-choices">
            {it.choices.map((c) => {
              const mine = c.id === it.selected_choice_id;
              return (
                <li key={c.id} className={`${c.correct ? 'rc-correct' : ''} ${mine ? 'rc-mine' : ''}`}>
                  <span className="choice-label">{c.label}</span> {c.text}
                  {c.correct && <span className="tag tag-correct">correct answer</span>}
                  {mine && <span className="tag tag-mine">your answer</span>}
                </li>
              );
            })}
          </ul>
          {it.explanation && <p className="explanation"><strong>Why: </strong>{it.explanation}</p>}
        </li>
      ))}
    </ol>
  );
}
