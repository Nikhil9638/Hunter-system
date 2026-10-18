import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ApiError, get, post } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { BadgeArt, LoadError, Loading, Notice, PageHeader } from '../components/ui.jsx';

/**
 * Badge store. Prices and availability come only from the API; the purchase sends
 * no price. A purchase is never retried automatically: if its outcome is unknown
 * (network failure) the student is asked to refresh, and a repeated purchase of an
 * owned badge is answered by the server with already_owned (no second debit).
 */
export function StorePage() {
  const res = useApi(async (signal) => {
    const [badges, profile] = await Promise.all([get('/badges', { signal }), get('/me/profile', { signal })]);
    return { badges, coins: profile.coins };
  }, []);
  const [confirming, setConfirming] = useState(null);
  const [message, setMessage] = useState(null);
  const [pending, run] = useAction();

  if (res.loading) return <Loading what="Loading the store" />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const { badges, coins } = res.data;

  const buy = (badge) => run(async () => {
    setMessage(null);
    try {
      const r = await post(`/badges/${badge.id}/purchase`);
      setMessage({ tone: 'success', title: 'Badge purchased', text: `${r.name} for ${r.price_paid} coins. Balance: ${r.balance_after}.`, link: true });
    } catch (err) {
      if (!(err instanceof ApiError)) throw err;
      const text = {
        already_owned: `You already own this badge (bought for ${err.details.price_paid} coins). No coins were taken.`,
        insufficient_coins: `Not enough coins: you have ${err.details.balance}, it costs ${err.details.price}.`,
        not_on_sale: 'This badge is no longer for sale. The store has been refreshed.',
        network_error: 'We could not confirm the purchase. Refresh the store and check your badges before trying again.',
      }[err.code] ?? err.message;
      setMessage({ tone: err.code === 'already_owned' ? 'info' : 'error', title: 'Purchase not completed', text });
    } finally {
      setConfirming(null);
      res.reload();                                          // balance and ownership always come from the server
    }
  });

  return (
    <div>
      <PageHeader title="Badge store">
        <p className="lead">Your balance: <strong>{coins}</strong> coins. Badges are for your profile only; they never change your rank.</p>
      </PageHeader>
      {message && (
        <Notice tone={message.tone} title={message.title}>
          <p>{message.text}{message.link && <> <Link to="/profile">Show it on your profile</Link>.</>}</p>
        </Notice>
      )}
      {badges.length === 0 ? (
        <Notice tone="info" title="No badges for sale yet">The badge collection is still being prepared. Earn coins now and spend them when badges arrive.</Notice>
      ) : (
        <ul className="badge-grid">
          {badges.map((b) => (
            <li key={b.id} className="card badge-card">
              <BadgeArt url={b.image_url} name={b.name} />
              <h2 className="badge-name">{b.name}</h2>
              <p className="muted">{b.description}</p>
              <p className="price">{b.price} coins</p>
              {b.owned ? <p className="tag tag-mine">Owned</p>
                : confirming === b.id ? (
                  <div className="confirm" role="group" aria-label={`Confirm buying ${b.name}`}>
                    <p>Buy {b.name} for {b.price} coins?</p>
                    <button type="button" className="btn btn-primary" onClick={() => buy(b)} disabled={pending}>{pending ? 'Buying…' : 'Confirm'}</button>
                    <button type="button" className="btn btn-ghost" onClick={() => setConfirming(null)} disabled={pending}>Cancel</button>
                  </div>
                ) : (
                  <>
                    <button type="button" className="btn btn-secondary" onClick={() => setConfirming(b.id)} disabled={pending || !b.affordable}>Buy</button>
                    {!b.affordable && <p className="hint">You need {b.price - coins} more coins.</p>}
                  </>
                )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
