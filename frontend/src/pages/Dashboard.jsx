import { Link } from 'react-router-dom';
import { get } from '../api/client.js';
import { useApi } from '../lib/useApi.js';
import { Card, LoadError, Loading, PageHeader, RankBadge, TopicLink } from '../components/ui.jsx';

export function DashboardPage() {
  const res = useApi((signal) => get('/me/dashboard', { signal }), []);
  if (res.loading) return <Loading />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const d = res.data;
  const pct = Math.round((d.topics_passed / Math.max(1, d.topics_total)) * 100);
  return (
    <div>
      <PageHeader title={`Welcome back, ${d.display_name}`} />
      <div className="grid-3">
        <Card title="Hunter rank" className="stat">
          <RankBadge rank={d.hunter_rank} size="lg" />
          {d.next_rank
            ? <p className="muted">{d.next_rank.name} at {d.next_rank.topics_required} topics passed — {d.next_rank.topics_remaining} to go.</p>
            : <p className="muted">Highest rank reached.</p>}
        </Card>
        <Card title="Topics passed" className="stat">
          <p className="big-number">{d.topics_passed}<span className="muted"> / {d.topics_total}</span></p>
          <div className="progress" role="progressbar" aria-valuemin={0} aria-valuemax={d.topics_total} aria-valuenow={d.topics_passed}
               aria-label="Topics passed"><span style={{ width: `${pct}%` }} /></div>
        </Card>
        <Card title="Coins" className="stat">
          <p className="big-number">{d.coins}</p>
          <p className="muted"><Link to="/history">Coin history</Link> · <Link to="/store">Badge store</Link></p>
        </Card>
      </div>
      <div className="grid-2">
        <Card title="Continue learning">
          {d.current_topic ? (
            <>
              <p className="topic-name">{d.current_topic.title}</p>
              {d.current_topic.content_ready
                ? <Link className="btn btn-primary" to={`/topics/${d.current_topic.id}`}>Open topic</Link>
                : <p className="muted">This topic is unlocked, but its lesson and quiz aren't available yet.</p>}
            </>
          ) : <p>You've passed every unlocked topic.</p>}
          <p><Link to="/topics">See all topics</Link></p>
        </Card>
        <Card title="Your profile at a glance">
          <dl className="facts">
            <dt>Experience (your description)</dt><dd>{d.experience_level?.label ?? '—'}</dd>
            <dt>Placement rank (starting point)</dt><dd><RankBadge rank={d.placement_rank} size="sm" /></dd>
            <dt>Recommended start</dt><dd><TopicLink topic={d.recommended_topic} />{d.recommended_topic && !d.recommended_topic.ready ? ' (coming soon)' : ''}</dd>
            {d.offered_topic && <><dt>Available now</dt><dd><TopicLink topic={d.offered_topic} /></dd></>}
          </dl>
          <p><Link to="/assessment/result">Placement result and review</Link></p>
        </Card>
      </div>
    </div>
  );
}
