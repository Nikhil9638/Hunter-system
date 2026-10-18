import { useState } from 'react';
import { Link } from 'react-router-dom';
import { get } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { LoadError, Loading, Notice, PageHeader, RankBadge, fmtDate } from '../components/ui.jsx';
import { HudPanel, ManaAmount } from '../components/hud.jsx';
import { Icon } from '../components/Icon.jsx';
import { RANKS, mana, rankCode } from '../config/presentation.js';

const PAGE = 20;

/**
 * Rank & Mana history (docs/frontend-design-spec.md §5.2). Rank history and the Mana
 * ledger come from their own endpoints; mission and badge NAMES are resolved from data
 * the app already reads (GET /topics, GET /me/profile owned_badges). If a lookup is
 * unavailable the entry falls back to its id — names are never invented.
 */
export function HistoryPage() {
  const ranks = useApi((signal) => get('/me/rank-history', { signal }), []);
  const coins = useApi((signal) => get(`/me/coins?limit=${PAGE}`, { signal }), []);
  // Optional name lookups: a failure here never blocks or alters the history itself.
  const names = useApi(async (signal) => {
    const [topics, profile] = await Promise.allSettled([get('/topics', { signal }), get('/me/profile', { signal })]);
    return {
      topics: topics.status === 'fulfilled' ? Object.fromEntries(topics.value.map((t) => [t.id, t])) : null,
      badges: profile.status === 'fulfilled' ? Object.fromEntries(profile.value.owned_badges.map((b) => [b.badge_id, b.name])) : null,
    };
  }, []);
  const [more, setMore] = useState([]);
  const [done, setDone] = useState(false);
  const [error, setError] = useState(null);
  const [pending, run] = useAction();

  const entries = [...(coins.data?.entries ?? []), ...more];
  const loadMore = () => run(async () => {
    setError(null);
    try {
      const last = entries[entries.length - 1];
      const page = await get(`/me/coins?limit=${PAGE}&before_id=${last.id}`);
      setMore((m) => [...m, ...page.entries]);
      if (page.entries.length < PAGE) setDone(true);
    } catch (err) {
      setError(err.message);
    }
  });

  const describe = (e) => {
    if (e.type === 'topic_reward') {
      const t = names.data?.topics?.[e.topic_id];
      return { icon: 'gate', kind: 'Mission reward', text: t ? `First completion of mission ${t.position}: ${t.title}` : `First completion of mission #${e.topic_id}` };
    }
    if (e.type === 'badge_purchase') {
      const n = names.data?.badges?.[e.badge_id];
      return { icon: 'badges', kind: 'Badge purchase', text: n ? `Badge: ${n}` : `Badge #${e.badge_id}` };
    }
    return { icon: 'info', kind: e.type, text: e.topic_id ? `Mission #${e.topic_id}` : e.badge_id ? `Badge #${e.badge_id}` : '' };   // unknown type: shown as-is
  };

  return (
    <div className="history-page">
      <PageHeader title="Rank & Mana history">
        <p className="lead">Your rank changes and every Mana movement, as recorded by the server.</p>
      </PageHeader>
      <div className="history-grid">
        <HudPanel eyebrow="Hunter rank" title="Rank history">
          {ranks.loading && <Loading />}
          {ranks.error && <LoadError error={ranks.error} onRetry={ranks.reload} />}
          {ranks.data && (
            ranks.data.length === 0 ? <p className="muted">No rank recorded yet.</p> : (
              <ol className="timeline">
                {ranks.data.map((h, i) => {
                  const code = rankCode(h.to);
                  return (
                    <li key={i} className={`timeline-node rank-${code ?? 'none'}${h.reason === 'promotion' ? ' is-promotion' : ''}`}>
                      <span className="timeline-dot" aria-hidden="true" />
                      <div className="timeline-body">
                        <p className="timeline-rank"><RankBadge rank={h.to} size="sm" />{code && RANKS[code].lore && <span className="lore muted small">{RANKS[code].lore}</span>}</p>
                        <p className="timeline-what">{h.reason === 'placement'
                          ? 'Starting rank from the placement check'
                          : <>Promoted from {h.from}-Rank after clearing {h.topics_passed} different missions</>}</p>
                        <p className="muted small">{fmtDate(h.at)}</p>
                      </div>
                    </li>
                  );
                })}
              </ol>
            )
          )}
          <p className="hint">Your Hunter rank (E–S) never goes down and only changes through placement and promotions. Quiz grades (A–F) belong to single attempts and never change your rank. Mana never changes your rank either.</p>
        </HudPanel>

        <HudPanel eyebrow="Wallet" title="Mana">
          {coins.loading && <Loading />}
          {coins.error && <LoadError error={coins.error} onRetry={coins.reload} />}
          {coins.data && (
            <>
              <p className="wallet-balance"><span className="muted small">Balance</span><ManaAmount amount={coins.data.balance} size="lg" /></p>
              {entries.length === 0 && <p className="muted">No Mana activity yet. Complete your first quiz in a mission to earn 10 Mana.</p>}
              <ul className="ledger">
                {entries.map((e) => {
                  const d = describe(e);
                  const credit = e.direction === 'credit';
                  return (
                    <li key={e.id} className={credit ? 'is-credit' : 'is-debit'}>
                      <span className={credit ? 'credit' : 'debit'}>{credit ? '+' : '−'}{e.amount}</span>
                      <span className="ledger-what"><Icon name={d.icon} /><span><strong>{credit ? 'Mana earned' : 'Mana spent'}</strong> · {d.kind}{d.text && <> · {d.text}</>}</span></span>
                      <span className="muted">balance {mana(e.balance_after)} · {fmtDate(e.at)}</span>
                    </li>
                  );
                })}
              </ul>
              {entries.length >= PAGE && !done && <button type="button" className="btn btn-secondary" onClick={loadMore} disabled={pending}>{pending ? 'Loading…' : 'Show older entries'}</button>}
              {error && <Notice tone="error">{error}</Notice>}
              <p className="hint"><Link to="/store">Spend Mana in the badge store</Link></p>
            </>
          )}
        </HudPanel>
      </div>
    </div>
  );
}
