import { Link } from 'react-router-dom';
import { useState } from 'react';
import { Icon } from './Icon.jsx';
import { rankCode, rankDisplay } from '../config/presentation.js';

/**
 * E–S rank indicator: letter + "D-Rank · Apprentice", distinct shape per rank (never
 * colour alone). The label is built from the rank CODE only (config/presentation.js);
 * the API's own name/title (e.g. "Rookie") is not displayed. Accepts {code}, 'D' or
 * 'D-Rank'. variant="theme" is the outlined experience-card look: NOT a real rank.
 */
export function RankBadge({ rank, size = 'md', label, variant }) {
  if (!rank) return <span className="rank rank-none">Not ranked yet</span>;
  const code = rankCode(rank);
  const prefix = `${variant === 'theme' ? 'Theme · ' : ''}${label ? `${label}: ` : ''}`;
  if (!code) return <span className="rank rank-unknown">{prefix}{String(rank.code ?? rank)}</span>;
  const text = rankDisplay(code);
  return (
    <span className={`rank rank-${code} rank-${size}${variant === 'theme' ? ' rank-theme' : ''}`} title={text}>
      <span className="rank-letter" aria-hidden="true">{code}</span>
      <span className="rank-text">{prefix}{text}</span>
    </span>
  );
}

export function Loading({ what = 'Loading' }) {
  return <p className="loading" role="status"><span className="spinner" aria-hidden="true" />{what}…</p>;
}

/** Error panel for a failed screen load, with a retry. */
export function LoadError({ error, onRetry }) {
  return (
    <div className="notice notice-error" role="alert">
      <p><strong>Could not load this page.</strong> {error?.message ?? 'Unknown error.'}</p>
      {onRetry && <button type="button" className="btn btn-secondary" onClick={onRetry}>Try again</button>}
    </div>
  );
}

/** Inline message. tone: info | success | warning | error. Errors are announced immediately. */
export function Notice({ tone = 'info', title, children, action }) {
  const icon = { info: 'ℹ', success: '✓', warning: '!', error: '✕' }[tone];
  return (
    <div className={`notice notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      <span className="notice-icon" aria-hidden="true">{icon}</span>
      <div>
        {title && <p className="notice-title">{title}</p>}
        {children}
        {action}
      </div>
    </div>
  );
}

export function StatusLabel({ status }) {
  const map = {
    locked: [<Icon name="lock" />, 'Locked'], available: ['○', 'Unlocked'], in_progress: ['◐', 'Quiz in progress'],
    completed: ['●', 'Completed, not passed'], passed: ['✓', 'Passed'], no_content: ['…', 'Unlocked: content coming soon'],
  };
  const [icon, text] = map[status] ?? ['?', status];
  return <span className={`status status-${status}`}><span aria-hidden="true">{icon}</span> {text}</span>;
}

/** Cosmetic artwork, with a neutral fallback for absent or failed local images. */
export function BadgeArt({ url, name }) {
  const [failedUrl, setFailedUrl] = useState(null);
  if (url && failedUrl !== url) return <img className="badge-art" src={url} alt="" width="72" height="72" loading="lazy" onError={() => setFailedUrl(url)} />;
  return (
    <span className="badge-art badge-art-placeholder" aria-hidden="true" title="Artwork not available yet">
      <svg viewBox="0 0 48 48" width="40" height="40"><path d="M24 4 41 14v20L24 44 7 34V14z" fill="none" stroke="currentColor" strokeWidth="2.5" /></svg>
    </span>
  );
}

export function TableScroll({ label, children }) {
  return <div className="table-wrap" role="region" aria-label={label} tabIndex={0}>{children}</div>;
}

export function PageHeader({ title, children }) {
  return (
    <header className="page-header">
      <h1 tabIndex={-1} className="page-title">{title}</h1>
      {children}
    </header>
  );
}

export function Card({ title, children, className = '', headingLevel = 2 }) {
  const H = `h${headingLevel}`;
  return (
    <section className={`card ${className}`}>
      {title && <H className="card-title">{title}</H>}
      {children}
    </section>
  );
}

export function TopicLink({ topic }) {
  if (!topic) return null;
  return <Link to={`/topics/${topic.id}`}>{topic.position ? `${topic.position}. ` : ''}{topic.title}</Link>;
}

export const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
