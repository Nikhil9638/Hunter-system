import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ApiError, get, put } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useAction, useApi } from '../lib/useApi.js';
import { LoadError, Loading, Notice, PageHeader } from '../components/ui.jsx';

export function ExperiencePage() {
  const { setOnboarding, refresh } = useAuth();
  const navigate = useNavigate();
  const levels = useApi((signal) => get('/experience-levels', { signal }), []);
  const [choice, setChoice] = useState(null);
  const [error, setError] = useState(null);
  const [pending, run] = useAction();

  const onSubmit = (e) => {
    e.preventDefault();
    if (!choice) return;
    setError(null);
    run(async () => {
      try {
        await put('/me/experience', { experience_level_id: choice });
        navigate('/assessment', { replace: true });       // navigate first, then update the cached state (same tick)
        setOnboarding('experience_chosen');
      } catch (err) {
        if (err instanceof ApiError && err.code === 'experience_already_chosen') {
          await refresh();                                  // the server already has it: continue from there
        } else {
          setError(err.message);
        }
      }
    });
  };

  return (
    <div className="narrow">
      <PageHeader title="How much Python do you know?">
        <p className="lead">This is your own description. It never changes your score, rank or recommendation. A short 10-question check comes next.</p>
      </PageHeader>
      {levels.loading && <Loading />}
      {levels.error && <LoadError error={levels.error} onRetry={levels.reload} />}
      {levels.data && (
        <form className="card form" onSubmit={onSubmit}>
          <fieldset className="options">
            <legend>Experience level</legend>
            {levels.data.map((l) => (
              <label key={l.id} className={`option${choice === l.id ? ' option-selected' : ''}`}>
                <input type="radio" name="experience" value={l.id} checked={choice === l.id} onChange={() => setChoice(l.id)} />
                <span>{l.label}</span>
              </label>
            ))}
          </fieldset>
          <p className="hint">You can't change this later.</p>
          {error && <Notice tone="error">{error}</Notice>}
          <button type="submit" className="btn btn-primary" disabled={!choice || pending}>{pending ? 'Saving…' : 'Continue to the placement check'}</button>
        </form>
      )}
    </div>
  );
}
