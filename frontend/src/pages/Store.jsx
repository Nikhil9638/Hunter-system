import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ApiError, get, post } from '../api/client.js';
import { useAction, useApi } from '../lib/useApi.js';
import { BadgeArt, LoadError, Loading, Notice, PageHeader } from '../components/ui.jsx';
import { ConfirmDialog, ManaAmount } from '../components/hud.jsx';
import { Icon } from '../components/Icon.jsx';
import { mana } from '../config/presentation.js';

/**
 * Badge store. Prices and availability come only from the API; the purchase sends
 * no price. A purchase is never retried automatically: if its outcome is unknown
 * (network failure) the student is asked to refresh, and a repeated purchase of an
 * owned badge is answered by the server with already_owned (no second debit).
 * Spec §5.3: collectible cards, owned/affordable flags from GET /badges, and a
 * "Spend N Mana?" confirmation before POST /badges/{id}/purchase.
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
      setMessage({ tone: 'success', title: 'Badge purchased', text: `${r.name} for ${mana(r.price_paid)}. Balance: ${mana(r.balance_after)}.`, link: true });
    } catch (err) {
      if (!(err instanceof ApiError)) throw err;
      const text = {
        already_owned: `You already own this badge (bought for ${mana(err.details.price_paid)}). No Mana was taken.`,
        insufficient_coins: `Not enough Mana: you have ${err.details.balance}, it costs ${err.details.price}.`,
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
    <div className="store-page">
      <PageHeader title="Badge store">
        <p className="eyebrow store-eyebrow">Artifact vault</p>
        <p className="lead">Your balance: <ManaAmount amount={coins} />. Badges are for your profile only; they never change your rank.</p>
      </PageHeader>
      {message && (
        <Notice tone={message.tone} title={message.title}>
          <p>{message.text}{message.link && <> <Link to="/profile">Show it on your profile</Link>.</>}</p>
        </Notice>
      )}
      {badges.length === 0 ? (
        <section className="vault-empty" aria-labelledby="vault-empty-title">
          <svg className="vault-empty-art" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
            <polygon points="60,6 106,32 106,88 60,114 14,88 14,32" fill="none" stroke="currentColor" strokeWidth="3" />
            <path d="M44 56h32v28H44z M50 56v-8a10 10 0 0 1 20 0v8" fill="none" stroke="currentColor" strokeWidth="3" strokeLinejoin="round" />
          </svg>
          <h2 id="vault-empty-title">No badges for sale yet</h2>
          <p>The vault is sealed for now. Earn Mana by completing missions and spend it when badges arrive.</p>
          <p className="muted small">Your balance is kept: <ManaAmount amount={coins} />.</p>
        </section>
      ) : (
        <ul className="badge-grid">
          {badges.map((b) => (
            <li key={b.id} className={`badge-card${b.owned ? ' is-owned' : ''}${!b.owned && !b.affordable ? ' is-locked' : ''}`}>
              <span className="badge-card-frame" aria-hidden="true" />
              <div className="badge-card-art"><BadgeArt url={b.image_url} name={b.name} /></div>
              <h2 className="badge-name">{b.name}</h2>
              <p className="badge-desc">{b.description}</p>
              <p className="price"><ManaAmount amount={b.price} /></p>
              <div className="badge-card-foot">
                {b.owned ? (
                  <>
                    <p className="tag tag-mine badge-owned"><Icon name="seal" /><span>Owned</span></p>
                    <Link className="small" to="/profile">Showcase on your profile</Link>
                  </>
                ) : (
                  <>
                    <button type="button" className="btn btn-primary btn-block" onClick={() => setConfirming(b.id)} disabled={pending || !b.affordable}>Buy</button>
                    {!b.affordable && <p className="hint">Not enough Mana: you need {mana(b.price - coins)} more.</p>}
                  </>
                )}
              </div>
              {/* Rendered inside its card so the confirmation belongs to this badge (native modal dialog). */}
              {!b.owned && (
                <ConfirmDialog open={confirming === b.id} title="Confirm purchase" confirmLabel={`Spend ${mana(b.price)}`} pendingLabel="Spending…"
                               pending={pending} onConfirm={() => buy(b)} onCancel={() => setConfirming(null)}>
                  <p>Spend <strong>{mana(b.price)}</strong> on <strong>{b.name}</strong>?</p>
                  <p className="muted small">Balance now: {mana(coins)}. The server checks the price and your balance when you confirm.</p>
                </ConfirmDialog>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
