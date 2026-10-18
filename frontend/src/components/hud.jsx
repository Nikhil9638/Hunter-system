// Phase 2 shared primitives (docs/frontend-design-spec.md §8.2). Presentation only:
// they render values they are given and never fetch, derive or store data.
import { useEffect, useId, useRef } from 'react';
import { Icon } from './Icon.jsx';
import { mana, rankArt, rankCode } from '../config/presentation.js';

/** Framed "System" panel with corner brackets. variant: status | notice | quiet. */
export function HudPanel({ as: Tag = 'section', title, eyebrow, headingLevel = 2, variant = 'status', className = '', children, ...rest }) {
  const H = `h${headingLevel}`;
  const id = useId();
  return (
    <Tag className={`hud-panel hud-panel-${variant}${className ? ` ${className}` : ''}`} aria-labelledby={title ? id : undefined} {...rest}>
      <span className="hud-corner hud-corner-tl" aria-hidden="true" />
      <span className="hud-corner hud-corner-tr" aria-hidden="true" />
      <span className="hud-corner hud-corner-bl" aria-hidden="true" />
      <span className="hud-corner hud-corner-br" aria-hidden="true" />
      {(title || eyebrow) && (
        <div className="hud-panel-head">
          {eyebrow && <span className="eyebrow">{eyebrow}</span>}
          {title && <H id={id} className="hud-panel-title">{title}</H>}
        </div>
      )}
      {children}
    </Tag>
  );
}

/**
 * Native modal <dialog> styled as a System notification. Focus starts on Cancel,
 * Esc cancels (unless pending), focus returns to the opener on close. The caller
 * owns `open` and performs the action in onConfirm (single-flight via useAction).
 */
export function ConfirmDialog({ open, title, children, confirmLabel, pendingLabel = 'Working…', cancelLabel = 'Cancel', onConfirm, onCancel, pending = false, tone = 'info' }) {
  const ref = useRef(null);
  const cancelRef = useRef(null);
  const opener = useRef(null);
  const titleId = useId();
  const bodyId = useId();
  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) {
      opener.current = document.activeElement;
      dialog.showModal();
      cancelRef.current?.focus();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  }, [open]);
  useEffect(() => () => { if (ref.current?.open) ref.current.close(); }, []);
  const restoreFocus = () => {
    const el = opener.current;
    opener.current = null;
    if (el && document.contains(el)) el.focus();
  };
  return (
    <dialog ref={ref} className={`confirm-dialog${tone === 'danger' ? ' confirm-dialog-danger' : ''}`} aria-labelledby={titleId} aria-describedby={bodyId}
            onCancel={(e) => { e.preventDefault(); if (!pending) onCancel?.(); }} onClose={restoreFocus}>
      <div className="confirm-body">
        <div className="confirm-head">
          <span className={`notice-icon notice-${tone === 'danger' ? 'error' : 'info'}`} aria-hidden="true">!</span>
          <h2 id={titleId}>{title}</h2>
        </div>
        <div id={bodyId}>{children}</div>
        <div className="confirm-actions">
          <button ref={cancelRef} type="button" className="btn btn-ghost" onClick={() => onCancel?.()} disabled={pending}>{cancelLabel}</button>
          <button type="button" className={`btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`} onClick={() => onConfirm?.()} disabled={pending}>
            {pending ? pendingLabel : confirmLabel}
          </button>
        </div>
      </div>
    </dialog>
  );
}

/** "N Mana" with a crystal icon. Displays the API's coin amount unchanged. */
export function ManaAmount({ amount, size }) {
  return (
    <span className={`mana${size === 'lg' ? ' mana-lg' : ''}`}>
      <Icon name="mana" />
      <span>{mana(amount)}</span>
    </span>
  );
}

/** Labelled progress bar with a visible text ratio. Values come from the caller (API). */
export function ProgressMeter({ value, max, label, valueText }) {
  const labelId = useId();
  const safeMax = Math.max(0, Number(max) || 0);
  const safeValue = Math.min(Math.max(0, Number(value) || 0), safeMax);
  const pct = safeMax > 0 ? Math.round((safeValue / safeMax) * 100) : 0;
  const text = valueText ?? `${safeValue} / ${safeMax}`;
  return (
    <div className="meter">
      <div className="meter-label"><span id={labelId}>{label}</span><strong>{text}</strong></div>
      <div className="progress" role="progressbar" aria-labelledby={labelId} aria-valuemin={0} aria-valuemax={safeMax} aria-valuenow={safeValue} aria-valuetext={text}>
        <span style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

const EMBLEM_SHAPES = {
  E: <circle cx="50" cy="50" r="44" />,
  D: <rect x="8" y="8" width="84" height="84" rx="10" />,
  C: <polygon points="50,4 96,50 50,96 4,50" />,
  B: <polygon points="50,4 92,27 92,73 50,96 8,73 8,27" />,
  A: <polygon points="30,4 70,4 96,30 96,70 70,96 30,96 4,70 4,30" />,
  S: <polygon points="50,2 61,36 97,36 68,57 79,92 50,71 21,92 32,57 3,36 39,36" />,
};

/** Original SVG rank sigil (same shape language as RankBadge). Decorative unless `title` is given. */
export function RankEmblem({ rank, size = 64, title }) {
  const code = rankCode(rank);
  const a11y = title ? { role: 'img', 'aria-label': title } : { 'aria-hidden': 'true' };
  return (
    <svg className={`rank-emblem rank-${code ?? 'none'}`} viewBox="0 0 100 100" width={size} height={size} focusable="false" {...a11y}>
      <g fill="currentColor">{code ? EMBLEM_SHAPES[code] : <polygon points="50,4 92,27 92,73 50,96 8,73 8,27" fill="none" stroke="currentColor" strokeWidth="4" />}</g>
      {code && <text x="50" y={code === 'S' ? 62 : 52} textAnchor="middle" dominantBaseline="middle" fontSize={code === 'S' ? 26 : 44}>{code}</text>}
    </svg>
  );
}

/**
 * Decorative rank-tier artwork (spec §4.2) with a mandatory visible caption.
 * Never an avatar, never selectable, never saved.
 */
export function CharacterArt({ rank, caption = 'Decorative rank artwork. Not an avatar.' }) {
  const code = rankCode(rank);
  const art = rankArt(code);
  return (
    <figure className={`character-art rank-${code ?? 'none'}`}>
      {art ? (
        <div className="character-frame">
          <img src={art.src} srcSet={art.srcSmall ? `${art.srcSmall} 400w, ${art.src} ${art.width}w` : undefined}
               sizes={art.srcSmall ? '(max-width: 760px) 90vw, 420px' : undefined}
               alt="" width={art.width} height={art.height} loading="lazy" decoding="async" />
        </div>
      ) : <RankEmblem rank={null} size={160} />}
      <figcaption>{caption}</figcaption>
    </figure>
  );
}
