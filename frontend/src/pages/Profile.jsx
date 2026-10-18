import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { get, put } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { BadgeArt, Card, LoadError, Loading, Notice, PageHeader, RankBadge, fmtDate } from '../components/ui.jsx';

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

  return (
    <div>
      <PageHeader title={p.display_name} />
      <div className="grid-3">
        <Card title="Hunter rank" className="stat"><RankBadge rank={p.hunter_rank} size="lg" /></Card>
        <Card title="Placement rank" className="stat"><RankBadge rank={p.placement_rank} /><p className="muted small">Starting point from the placement check.</p></Card>
        <Card title="Progress" className="stat">
          <p>{p.topics_passed} of {p.topics_total} topics passed</p>
          <p>{p.coins} coins</p>
          <p className="muted small">Experience (self-described): {p.experience_level?.label ?? '—'}</p>
        </Card>
      </div>

      <Card title="Showcase">
        {p.showcase.length === 0 ? <p className="muted">No badges showcased.</p> : (
          <ol className="showcase">
            {p.showcase.map((s) => (
              <li key={s.badge_id}><BadgeArt url={s.image_url} name={s.name} /><span>{s.name}</span>{s.status === 'retired' && <span className="tag">retired</span>}</li>
            ))}
          </ol>
        )}
      </Card>

      <Card title="Your badges">
        {p.owned_badges.length === 0 ? (
          <p className="muted">You don't own any badges yet. <Link to="/store">Visit the store</Link>.</p>
        ) : (
          <>
            <p className="hint">Choose up to {limit} badges to showcase, then arrange their order.</p>
            <ul className="owned">
              {p.owned_badges.map((b) => (
                <li key={b.badge_id} className="owned-item">
                  <label>
                    <input type="checkbox" checked={draft.includes(b.badge_id)} onChange={() => toggle(b.badge_id)}
                           disabled={pending || (!draft.includes(b.badge_id) && draft.length >= limit)} />
                    <BadgeArt url={b.image_url} name={b.name} />
                    <span><strong>{b.name}</strong>{b.status === 'retired' && <span className="tag">retired</span>}
                      <span className="muted small"> · paid {b.price_paid} coins · {fmtDate(b.purchased_at)}</span></span>
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
            <button type="button" className="btn btn-primary" onClick={save} disabled={pending || !changed}>{pending ? 'Saving…' : 'Save showcase'}</button>
            {' '}{changed && <button type="button" className="btn btn-ghost" onClick={() => setDraft(saved)} disabled={pending}>Undo changes</button>}
          </>
        )}
        {message && <Notice tone={message.tone}>{message.text}</Notice>}
      </Card>
    </div>
  );
}
