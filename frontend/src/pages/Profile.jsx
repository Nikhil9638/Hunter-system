import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { get, put } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { BadgeArt, LoadError, Loading, Notice, PageHeader, RankBadge, fmtDate } from '../components/ui.jsx';
import { CharacterArt, HudPanel, ManaAmount } from '../components/hud.jsx';
import { RANKS, mana, rankCode } from '../config/presentation.js';

const STATUS_TAG = { retired: 'retired', off_sale: 'off sale' };   // owned badges keep these statuses (API)
const StatusTag = ({ status }) => (STATUS_TAG[status] ? <span className="tag">{STATUS_TAG[status]}</span> : null);

/**
 * Hunter profile (docs/frontend-design-spec.md §5.4): GET /me/profile; showcase saved
 * with PUT /me/showcase {badge_ids}. Ordering, save, undo and error handling unchanged.
 */
export function ProfilePage() {
  const res = useApi((signal) => get('/me/profile', { signal }), []);
  const [draft, setDraft] = useState(null);           // ordered badge ids being edited
  const [message, setMessage] = useState(null);
  const [pending, run] = useAction();

  useEffect(() => {
    if (res.data) setDraft(res.data.showcase.map((s) => s.badge_id));
  }, [res.data]);

  if (res.loading || !draft) return res.error ? <LoadError error={res.error} onRetry={res.reload} /> : <Loading />;
  const p = res.data;
  const limit = p.showcase_limit;
  const saved = p.showcase.map((s) => s.badge_id);
  const byId = Object.fromEntries(p.owned_badges.map((b) => [b.badge_id, b]));
  const changed = JSON.stringify(saved) !== JSON.stringify(draft);
  const code = rankCode(p.hunter_rank);

  const toggle = (id) => setDraft((d) => (d.includes(id) ? d.filter((x) => x !== id) : d.length < limit ? [...d, id] : d));
  const move = (i, delta) => setDraft((d) => {
    const n = [...d];
    [n[i], n[i + delta]] = [n[i + delta], n[i]];
    return n;
  });
  const save = () => run(async () => {
    setMessage(null);
    try {
      const r = await put('/me/showcase', { badge_ids: draft });
      res.setData((old) => ({ ...old, showcase: r.showcase, owned_badges: old.owned_badges.map((b) => ({ ...b, showcased: r.showcase.some((s) => s.badge_id === b.badge_id) })) }));
      setMessage({ tone: 'success', text: 'Showcase saved.' });
    } catch (err) {
      setDraft(saved);                                // keep the previous showcase: nothing changed on the server
      setMessage({ tone: 'error', text: `Showcase not saved: ${err.message} Your previous showcase is unchanged.` });
    }
  });
  const emptySlots = Math.max(0, limit - p.showcase.length);

  return (
    <div className="profile-page">
      <PageHeader title={p.display_name} />
      <div className="profile-top">
        <section className={`profile-art rank-${code ?? 'none'}`} aria-label="Rank artwork">
          <CharacterArt rank={p.hunter_rank} />
        </section>
        <HudPanel eyebrow="Hunter card" title="Profile" className="profile-card">
          <dl className="status-rows">
            <div><dt>Hunter rank</dt><dd><RankBadge rank={p.hunter_rank} size="lg" />{code && RANKS[code].lore && <span className="lore muted small profile-lore">{RANKS[code].lore}</span>}</dd></div>
            <div><dt>Placement rank</dt><dd><RankBadge rank={p.placement_rank} size="sm" /> <span className="muted small">starting point from the placement check</span></dd></div>
            <div><dt>Missions cleared</dt><dd><strong className="status-number">{p.topics_passed} / {p.topics_total}</strong></dd></div>
            <div><dt>Mana</dt><dd><ManaAmount amount={p.coins} /></dd></div>
            <div><dt>Experience</dt><dd>{p.experience_level?.label ?? '—'} <span className="muted small">(self-described)</span></dd></div>
          </dl>
        </HudPanel>
      </div>

      <HudPanel eyebrow={`Up to ${limit} badges`} title="Showcase">
        <div className="showcase-slots">
          {p.showcase.length > 0 && (
            <ol className="showcase">
              {p.showcase.map((s) => (
                <li key={s.badge_id} className="showcase-slot"><BadgeArt url={s.image_url} name={s.name} /><span className="showcase-name">{s.name}</span><StatusTag status={s.status} /></li>
              ))}
            </ol>
          )}
          {emptySlots > 0 && (
            <ul className="showcase-empty" aria-label={`${emptySlots} empty showcase ${emptySlots === 1 ? 'slot' : 'slots'}`}>
              {Array.from({ length: emptySlots }, (_, i) => <li key={i} className="showcase-slot is-empty"><span aria-hidden="true">+</span><span className="small">Empty slot</span></li>)}
            </ul>
          )}
        </div>
        {p.showcase.length === 0 && <p className="muted">No badges showcased.</p>}
      </HudPanel>

      <HudPanel eyebrow="Collection" title="Your badges">
        {p.owned_badges.length === 0 ? (
          <p className="muted">You don't own any badges yet. <Link to="/store">Visit the store</Link>.</p>
        ) : (
          <>
            <p className="hint">Choose up to {limit} badges to showcase, then arrange their order.</p>
            <ul className="owned">
              {p.owned_badges.map((b) => (
                <li key={b.badge_id} className={`owned-item${draft.includes(b.badge_id) ? ' is-picked' : ''}`}>
                  <label>
                    <input type="checkbox" checked={draft.includes(b.badge_id)} onChange={() => toggle(b.badge_id)}
                           disabled={pending || (!draft.includes(b.badge_id) && draft.length >= limit)} />
                    <BadgeArt url={b.image_url} name={b.name} />
                    <span><strong>{b.name}</strong><StatusTag status={b.status} />
                      <span className="muted small owned-meta">paid {mana(b.price_paid)} · {fmtDate(b.purchased_at)}</span></span>
                  </label>
                </li>
              ))}
            </ul>
            {draft.length > 0 && (
              <>
                <h3 className="subhead">Showcase order</h3>
                <ol className="order">
                  {draft.map((id, i) => (
                    <li key={id}>
                      <span>{i + 1}. {byId[id]?.name}</span>
                      <button type="button" className="btn btn-small btn-ghost" onClick={() => move(i, -1)} disabled={pending || i === 0}
                              aria-label={`Move ${byId[id]?.name} up`}>↑</button>
                      <button type="button" className="btn btn-small btn-ghost" onClick={() => move(i, 1)} disabled={pending || i === draft.length - 1}
                              aria-label={`Move ${byId[id]?.name} down`}>↓</button>
                    </li>
                  ))}
                </ol>
              </>
            )}
            <div className="choice-row">
              <button type="button" className="btn btn-primary" onClick={save} disabled={pending || !changed}>{pending ? 'Saving…' : 'Save showcase'}</button>
              {changed && <button type="button" className="btn btn-ghost" onClick={() => setDraft(saved)} disabled={pending}>Undo changes</button>}
            </div>
          </>
        )}
        {message && <Notice tone={message.tone}>{message.text}</Notice>}
      </HudPanel>
    </div>
  );
}
