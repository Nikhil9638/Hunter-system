import { useState } from 'react';
import { get } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { Card, LoadError, Loading, Notice, PageHeader, RankBadge, fmtDate } from '../components/ui.jsx';

const PAGE = 20;

export function HistoryPage() {
  const ranks = useApi((signal) => get('/me/rank-history', { signal }), []);
  const coins = useApi((signal) => get(`/me/coins?limit=${PAGE}`, { signal }), []);
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

  return (
    <div>
      <PageHeader title="Rank & coin history" />
      <div className="grid-2">
        <Card title="Hunter rank history">
          {ranks.loading && <Loading />}
          {ranks.error && <LoadError error={ranks.error} onRetry={ranks.reload} />}
          {ranks.data && (
            <ol className="timeline">
              {ranks.data.map((h, i) => (
                <li key={i}>
                  <RankBadge rank={{ code: h.to }} size="sm" />{' '}
                  {h.reason === 'placement'
                    ? <span>Starting rank from the placement check</span>
                    : <span>Promoted from {h.from} after passing {h.topics_passed} different topics</span>}
                  <span className="muted"> · {fmtDate(h.at)}</span>
                </li>
              ))}
            </ol>
          )}
          <p className="hint">Your rank never goes down. Coins never change your rank.</p>
        </Card>
        <Card title="Coins">
          {coins.loading && <Loading />}
          {coins.error && <LoadError error={coins.error} onRetry={coins.reload} />}
          {coins.data && (
            <>
              <p className="big-number">{coins.data.balance} <span className="muted small">coins</span></p>
              {entries.length === 0 && <p className="muted">No coin activity yet. Finish your first quiz in a topic to earn 10 coins.</p>}
              <ul className="ledger">
                {entries.map((e) => (
                  <li key={e.id}>
                    <span className={e.direction === 'credit' ? 'credit' : 'debit'}>{e.direction === 'credit' ? '+' : '−'}{e.amount}</span>
                    <span>{e.type === 'topic_reward' ? `First completion of topic ${e.topic_id}` : e.type === 'badge_purchase' ? `Badge purchase (#${e.badge_id})` : e.type}</span>
                    <span className="muted">balance {e.balance_after} · {fmtDate(e.at)}</span>
                  </li>
                ))}
              </ul>
              {entries.length >= PAGE && !done && <button type="button" className="btn btn-secondary" onClick={loadMore} disabled={pending}>{pending ? 'Loading…' : 'Show older entries'}</button>}
              {error && <Notice tone="error">{error}</Notice>}
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
