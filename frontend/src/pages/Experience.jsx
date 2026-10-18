import { useId, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ApiError, get, put } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useAction, useApi } from '../lib/useApi.js';
import { LoadError, Loading, Notice, PageHeader } from '../components/ui.jsx';
import { Icon } from '../components/Icon.jsx';
import { EXPERIENCE_CARDS } from '../config/presentation.js';

/**
 * Self-described experience (docs/frontend-design-spec.md §3). The options, their
 * order and the submitted id come ONLY from GET /experience-levels; the themed card
 * (character, theme rank, art) is matched by the API `code` and is decoration only.
 * Request and error handling are unchanged: PUT /me/experience {experience_level_id}.
 */
export function ExperiencePage() {
  const { setOnboarding, refresh } = useAuth();
  const navigate = useNavigate();
  const levels = useApi((signal) => get('/experience-levels', { signal }), []);
  const [choice, setChoice] = useState(null);
  const [error, setError] = useState(null);
  const [pending, run] = useAction();
  const disclosureId = useId();

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

  const selected = levels.data?.find((l) => l.id === choice) ?? null;
  const selectedCard = selected ? EXPERIENCE_CARDS[selected.code] : null;

  return (
    <div className="experience-page">
      <PageHeader title="Choose your hunter">
        <p className="lead">Pick the hunter whose Python experience is closest to yours. A short 10-question placement check comes next.</p>
      </PageHeader>
      <div id={disclosureId}>
        <Notice tone="info" title="This is your own description, not your Hunter rank.">
          <p>The card's rank is only a theme. Your real starting rank comes from the placement check, and this choice never changes your score, rank or recommendation.</p>
        </Notice>
      </div>
      {levels.loading && <Loading />}
      {levels.error && <LoadError error={levels.error} onRetry={levels.reload} />}
      {levels.data && (
        <form className="experience-form" onSubmit={onSubmit}>
          <fieldset className="xp-grid">
            <legend className="xp-legend">Experience level</legend>
            {levels.data.map((level) => (
              <ExperienceCard key={level.id} level={level} card={EXPERIENCE_CARDS[level.code] ?? null}
                              selected={choice === level.id} onSelect={() => setChoice(level.id)} disabled={pending} disclosureId={disclosureId} />
            ))}
          </fieldset>
          <div className="xp-submit">
            <p className="xp-selection" aria-live="polite">
              {selected
                ? <>Selected: <strong>{selectedCard ? `${selectedCard.character} · ` : ''}{selected.label}</strong></>
                : 'No card selected yet.'}
            </p>
            <p className="hint">You can't change this later.</p>
            {error && <Notice tone="error">{error}</Notice>}
            <button type="submit" className="btn btn-primary btn-lg" disabled={!choice || pending}>{pending ? 'Saving…' : 'Confirm and begin placement check'}</button>
          </div>
        </form>
      )}
    </div>
  );
}

/**
 * One option. A visually hidden native radio carries the semantics (name, checked,
 * arrow keys, Space); Enter also selects instead of submitting. Flipping (hover,
 * focus, or the Details button on touch screens) never changes the selection.
 */
function ExperienceCard({ level, card, selected, onSelect, disabled, disclosureId }) {
  const [revealed, setRevealed] = useState(false);
  const inputId = useId(), backId = useId();
  const name = card ? `${card.character}, theme ${card.themeRank}-Rank: ${level.label}` : level.label;
  return (
    <div className={`xp-card${selected ? ' is-selected' : ''}${revealed ? ' is-revealed' : ''}${card ? '' : ' xp-card-plain'}${card ? ` rank-${card.themeRank}` : ''}`}>
      <input id={inputId} className="xp-radio" type="radio" name="experience" value={level.id} checked={selected} disabled={disabled}
             aria-label={name} aria-describedby={`${backId} ${disclosureId}`}
             onChange={onSelect}
             onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); onSelect(); } }} />
      <label htmlFor={inputId} className="xp-hit">
        <span className="xp-inner" aria-hidden="true">
          <span className="xp-face xp-front">
            {card && <span className="xp-art"><img src={card.art} alt="" width="480" height="640" decoding="async" /></span>}
            <span className="xp-front-text">
              {card && (
                <span className={`rank rank-sm rank-theme rank-${card.themeRank}`}>
                  <span className="rank-letter">{card.themeRank}</span>
                  <span className="rank-text">Theme · {card.themeRank}-Rank</span>
                </span>
              )}
              <span className="xp-name">{card ? card.character : level.label}</span>
              <span className="xp-level">{card ? level.label : 'Your own description'}</span>
            </span>
            <span className="xp-ribbon">✓ Selected</span>
          </span>
          <span className="xp-face xp-back">
            <span className="xp-back-title">{level.label}</span>
            <span id={backId} className="xp-back-text">{card ? card.blurb : 'Choose this if it describes your Python experience.'}</span>
            <span className="xp-back-note">{card ? 'Theme only. Not your Hunter rank.' : 'Not your Hunter rank.'}</span>
            <span className="xp-back-hint">{selected ? 'Selected ✓' : 'Select this card'}</span>
            <span className="xp-ribbon">✓ Selected</span>
          </span>
        </span>
      </label>
      {card && (
        <button type="button" className="btn btn-ghost btn-small xp-details" aria-expanded={revealed} aria-controls={backId}
                aria-label={`${revealed ? 'Hide details' : 'Details'}: ${card.character}`}
                onClick={(e) => { e.stopPropagation(); setRevealed((r) => !r); }}>
          <Icon name="info" />{revealed ? 'Hide details' : 'Details'}
        </button>
      )}
    </div>
  );
}
