import { Link } from 'react-router-dom';
import { get } from '../api/client.js';
import { useApi } from '../lib/useApi.js';
import { LoadError, Loading, PageHeader, RankBadge, TopicLink } from '../components/ui.jsx';
import { CharacterArt, HudPanel, ManaAmount, ProgressMeter } from '../components/hud.jsx';
import { RANKS, rankCode, rankDisplay } from '../config/presentation.js';

/**
 * Hunter status screen (docs/frontend-design-spec.md §4.1–4.2): one request,
 * GET /me/dashboard. Every value shown is an API field or a plain ratio of two API
 * fields; nothing is invented (no RPG stats, avatars, streaks or activity feed).
 */
export function DashboardPage() {
  const res = useApi((signal) => get('/me/dashboard', { signal }), []);
  if (res.loading) return <Loading />;
  if (res.error) return <LoadError error={res.error} onRetry={res.reload} />;
  const d = res.data;
  const code = rankCode(d.hunter_rank);
  const next = d.next_rank;
  return (
    <div className="dashboard">
      <PageHeader title={`Welcome back, ${d.display_name}`}>
        <p className="lead">Your hunter status, straight from the System.</p>
      </PageHeader>

      <div className="dash-layout">
        <section className={`dash-character rank-${code ?? 'none'}`} aria-label="Rank artwork">
          <CharacterArt rank={d.hunter_rank} />
          <div className="rank-plaque">
            <RankBadge rank={d.hunter_rank} size="lg" />
            {code && RANKS[code].lore && <p className="rank-plaque-lore lore">{RANKS[code].lore}</p>}
          </div>
        </section>

        <HudPanel eyebrow="System" title="Status" className="status-window">
          <dl className="status-rows">
            <div><dt>Name</dt><dd>{d.display_name}</dd></div>
            <div><dt>Hunter rank</dt><dd><RankBadge rank={d.hunter_rank} size="sm" /></dd></div>
            <div><dt>Placement rank</dt><dd><RankBadge rank={d.placement_rank} size="sm" /> <span className="muted small">starting point</span></dd></div>
            <div><dt>Missions cleared</dt><dd><strong className="status-number">{d.topics_passed} / {d.topics_total}</strong></dd></div>
            <div><dt>Next rank</dt><dd>{next
              ? <>{rankDisplay(next.code)} <span className="muted small">at {next.topics_required} missions cleared · {next.topics_remaining} to go</span></>
              : <span>Highest rank reached</span>}</dd></div>
            <div><dt>Mana</dt><dd><ManaAmount amount={d.coins} /></dd></div>
            <div><dt>Experience</dt><dd>{d.experience_level?.label ?? '—'} <span className="muted small">(self-described)</span></dd></div>
          </dl>
          <div className="status-meters">
            <ProgressMeter label="Missions cleared" value={d.topics_passed} max={d.topics_total} />
            {next && <ProgressMeter label={`Progress toward ${rankDisplay(next.code)}`} value={d.topics_passed} max={next.topics_required}
                                    valueText={`${d.topics_passed} of ${next.topics_required} missions`} />}
          </div>
        </HudPanel>
      </div>

      <div className="dash-next">
        <HudPanel variant="notice" eyebrow="Next" title="Current mission" className="dash-current">
          {d.current_topic ? (
            <>
              <p className="dash-current-title">{d.current_topic.title}</p>
              {d.current_topic.content_ready
                ? <Link className="btn btn-primary" to={`/topics/${d.current_topic.id}`}>Continue mission</Link>
                : <p className="muted">This mission is unlocked, but its lesson and quiz aren't available yet.</p>}
            </>
          ) : <p>You've cleared every unlocked mission.</p>}
          <p className="dash-links"><Link to="/topics">See all missions</Link></p>
        </HudPanel>
        <HudPanel variant="quiet" eyebrow="Placement" title="Recommended start">
          {d.recommended_topic
            ? <p><TopicLink topic={d.recommended_topic} />{!d.recommended_topic.ready && ' (coming soon)'}</p>
            : <p className="muted">No recommendation recorded.</p>}
          <p className="hint"><Link to="/assessment/result">Placement result and review</Link></p>
        </HudPanel>
        {d.offered_topic && (
          <HudPanel variant="quiet" eyebrow="Placement" title="Available now">
            <p><TopicLink topic={d.offered_topic} /></p>
            <p className="hint">The nearest earlier mission with content.</p>
          </HudPanel>
        )}
      </div>

      <nav className="dash-quick" aria-label="Quick links">
        <Link to="/topics">Missions</Link><Link to="/history">Rank &amp; Mana</Link><Link to="/store">Badge store</Link>
      </nav>
    </div>
  );
}
