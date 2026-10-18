// Public landing page for logged-out visitors at "/" (docs/frontend-design-spec.md §2).
// Static, approved copy only: no API calls, no invented statistics, no badge names or
// prices. Calls to action are ordinary links to the existing /register and /login routes.
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { AmbientBackground } from '../components/AmbientBackground.jsx';
import { Icon } from '../components/Icon.jsx';
import { RankEmblem } from '../components/hud.jsx';
import { ART, CURRICULUM_PREVIEW, RANKS, RANK_LADDER, SKILL_GROUPS, gateName } from '../config/presentation.js';

const SECTIONS = [['awakening', 'Awakening'], ['missions', 'Missions'], ['ranks', 'Ranks'], ['mana', 'Mana']];

function Brand() {
  return <span className="brand"><span className="brand-mark"><Icon name="code" /></span><span>Hunter System<small>AWAKEN · LEARN · LEVEL UP</small></span></span>;
}

function SectionLinks({ onNavigate }) {
  return <ul>{SECTIONS.map(([id, label]) => <li key={id}><a href={`#${id}`} onClick={onNavigate}>{label}</a></li>)}</ul>;
}

/** Sticky public header; on narrow screens the section links move into a modal menu. */
function LandingHeader() {
  const [open, setOpen] = useState(false);
  const dialogRef = useRef(null), toggleRef = useRef(null);
  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return undefined;
    if (open) {
      dialog.showModal();
      dialog.querySelector('a, button')?.focus();
      const previous = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => { if (dialog.open) dialog.close(); document.body.style.overflow = previous; };
    }
    if (dialog.open) dialog.close();
    return undefined;
  }, [open]);
  useEffect(() => {
    const media = window.matchMedia('(min-width: 761px)');
    const onChange = () => { if (media.matches) setOpen(false); };
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
  }, []);
  const close = (restoreFocus = true) => { setOpen(false); if (restoreFocus) toggleRef.current?.focus(); };
  const trapFocus = (e) => {
    if (e.key !== 'Tab') return;
    const items = [...e.currentTarget.querySelectorAll('a[href], button:not(:disabled)')].filter((n) => n.getClientRects().length > 0);
    const first = items[0], last = items.at(-1);
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
  };
  return (
    <header className="landing-header">
      <div className="landing-header-inner">
        <a className="landing-brand-link" href="#top" aria-label="Hunter System, back to top"><Brand /></a>
        <nav className="landing-nav" aria-label="Page sections"><SectionLinks /></nav>
        <div className="landing-actions">
          <Link className="btn btn-ghost" to="/login">Log in</Link>
          <Link className="btn btn-primary landing-signup" to="/register">Create account</Link>
          <button ref={toggleRef} type="button" className="btn btn-ghost landing-menu-toggle" aria-expanded={open} aria-controls="landing-menu" onClick={() => setOpen(true)}>
            <Icon name="menu" />Menu
          </button>
        </div>
      </div>
      <dialog ref={dialogRef} id="landing-menu" className="mobile-drawer landing-drawer" aria-labelledby="landing-menu-title"
              onKeyDown={trapFocus} onCancel={() => setOpen(false)} onClose={() => setOpen(false)}
              onClick={(e) => { if (e.target === e.currentTarget) close(); }}>
        <div className="drawer-heading">
          <h2 id="landing-menu-title">Hunter System</h2>
          <button type="button" className="btn btn-ghost" onClick={() => close()} aria-label="Close menu"><Icon name="close" /></button>
        </div>
        <nav className="landing-drawer-nav" aria-label="Page sections (menu)"><SectionLinks onNavigate={() => close(false)} /></nav>
        <div className="landing-drawer-actions">
          <Link className="btn btn-primary btn-block" to="/register">Begin Awakening</Link>
          <Link className="btn btn-secondary btn-block" to="/login">Enter Hunter Portal</Link>
        </div>
      </dialog>
    </header>
  );
}

function CallsToAction() {
  return (
    <div className="landing-ctas">
      <Link className="btn btn-primary btn-lg" to="/register">Begin Awakening</Link>
      <Link className="btn btn-secondary btn-lg" to="/login">Enter Hunter Portal</Link>
    </div>
  );
}

function Hero() {
  return (
    <section className="hero" aria-labelledby="hero-title">
      <div className="hero-text">
        <p className="hero-tag"><span className="hero-tag-dot" aria-hidden="true" />System · Awakening available</p>
        <h1 id="hero-title" className="hero-title" tabIndex={-1}>
          <span>Awaken.</span> <span>Learn.</span> <span className="hero-title-accent">Level up.</span>
        </h1>
        <p className="hero-lead">
          Hunter System teaches Python through 18 missions. Take a short placement check, clear missions, and rise from E-Rank to S-Rank.
        </p>
        <CallsToAction />
        <p className="hero-strip" aria-label="Python, 18 missions, 6 ranks"><span>Python</span><span>18 missions</span><span>6 ranks</span></p>
      </div>
      <div className="hero-art" aria-hidden="true">
        <div className="hero-art-glow" />
        <div className="hero-art-frame">
          <span className="hud-corner hud-corner-tl" /><span className="hud-corner hud-corner-tr" />
          <span className="hud-corner hud-corner-bl" /><span className="hud-corner hud-corner-br" />
          <img src={ART.hero} srcSet={`${ART.heroSmall} 400w, ${ART.hero} 560w`} sizes="(max-width: 760px) 92vw, 560px"
               alt="" width="560" height="735" fetchPriority="high" decoding="async" />
        </div>
        <div className="hero-haze" />
      </div>
    </section>
  );
}

const STEPS = [
  ['profile', 'Create your hunter account', 'Register with your email and a display name. Your progress is saved on the server.'],
  ['shield', 'Choose your experience card', "Pick the hunter whose Python experience is closest to yours. It's your own description, not your rank."],
  ['questions', 'Take the placement check', 'Ten questions set your starting Hunter rank and recommend where to begin.'],
  ['sword', 'Clear missions', 'Each mission has a lesson and a 5-question quiz. Three correct answers clear it and unlock the next mission.'],
];

function SectionHeading({ id, eyebrow, title, children }) {
  return (
    <header className="landing-section-head">
      <p className="eyebrow">{eyebrow}</p>
      <h2 id={`${id}-title`} className="landing-section-title">{title}</h2>
      {children && <p className="landing-section-lead">{children}</p>}
    </header>
  );
}

function Awakening() {
  return (
    <section id="awakening" className="landing-section reveal" aria-labelledby="awakening-title">
      <SectionHeading id="awakening" eyebrow="01 · Awakening" title="How awakening works" />
      <ol className="steps">
        {STEPS.map(([icon, title, text], i) => (
          <li key={title} className="step">
            <span className="step-index" aria-hidden="true">{String(i + 1).padStart(2, '0')}</span>
            <span className="step-icon" aria-hidden="true"><Icon name={icon} /></span>
            <h3>{title}</h3>
            <p>{text}</p>
          </li>
        ))}
      </ol>
      <p className="landing-note"><Icon name="info" /> The placement check, not the card you choose, sets your starting Hunter rank.</p>
    </section>
  );
}

function Missions() {
  const gates = Object.keys(SKILL_GROUPS).map((code) => ({ code, topics: CURRICULUM_PREVIEW.filter((t) => t.group === code) }));
  return (
    <section id="missions" className="landing-section reveal" aria-labelledby="missions-title">
      <SectionHeading id="missions" eyebrow="02 · Missions" title="Eighteen missions, six gates">
        From your first print() to classes and objects. Each mission is a short lesson and a 5-question quiz; pass with 3 correct to unlock the next.
      </SectionHeading>
      <ol className="gates">
        {gates.map((g) => (
          <li key={g.code} className="gate">
            <h3 className="gate-title"><Icon name="gate" />{gateName(g.code)}</h3>
            <ol className="gate-missions">
              {g.topics.map((t) => (
                <li key={t.position} className="gate-mission">
                  <span className="gate-mission-pos" aria-hidden="true">{String(t.position).padStart(2, '0')}</span>
                  <span className="sr-only">Mission {t.position}: </span>{t.title}
                  <Icon name="lock" className="gate-mission-seal" />
                </li>
              ))}
            </ol>
          </li>
        ))}
      </ol>
    </section>
  );
}

function Ranks() {
  return (
    <section id="ranks" className="landing-section reveal" aria-labelledby="ranks-title">
      <SectionHeading id="ranks" eyebrow="03 · Ranks" title="Rise from E-Rank to S-Rank">
        Your placement check sets your starting rank. Clearing different missions raises it, and your rank never goes down.
      </SectionHeading>
      <ol className="rank-ladder">
        {RANK_LADDER.map(({ code, missions }) => (
          <li key={code} className={`rank-step rank-${code}`}>
            <RankEmblem rank={code} size={56} />
            <div>
              <p className="rank-step-name">{code}-Rank · {RANKS[code].label}</p>
              {RANKS[code].lore && <p className="rank-step-lore lore">{RANKS[code].lore}</p>}
              <p className="rank-step-req">{missions} missions cleared</p>
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}

function ManaArtifacts() {
  return (
    <section id="mana" className="landing-section landing-mana reveal" aria-labelledby="mana-title">
      <div>
        <SectionHeading id="mana" eyebrow="04 · Mana" title="Earn Mana, collect artifacts" />
        <ul className="mana-points">
          <li><Icon name="mana" /><span>Earn <strong>10 Mana</strong> the first time you complete each mission, pass or fail.</span></li>
          <li><Icon name="badges" /><span>Spend Mana on cosmetic badges and show up to three on your profile.</span></li>
          <li><Icon name="shield" /><span>Badges are for show: they never change your rank or unlock missions.</span></li>
        </ul>
      </div>
      <svg className="artifact-illustration" viewBox="0 0 320 220" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="artifact-frame" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stopColor="#f5d48a" /><stop offset="1" stopColor="#b9862f" /></linearGradient>
          <radialGradient id="artifact-glow" cx=".5" cy=".5" r=".5"><stop offset="0" stopColor="#8b5cf6" stopOpacity=".55" /><stop offset="1" stopColor="#8b5cf6" stopOpacity="0" /></radialGradient>
        </defs>
        {[60, 160, 260].map((x, i) => (
          <g key={x} transform={`translate(${x} ${i === 1 ? 100 : 120})`} opacity={i === 1 ? 1 : 0.7}>
            <circle r="62" fill="url(#artifact-glow)" />
            <polygon points="0,-48 42,-24 42,24 0,48 -42,24 -42,-24" fill="#0e1230" stroke="url(#artifact-frame)" strokeWidth="3" />
            <polygon points="0,-36 31,-18 31,18 0,36 -31,18 -31,-18" fill="none" stroke="#4cc9ff" strokeOpacity=".5" strokeWidth="1.5" />
            <path d="M0 -18 12 -4 0 20 -12 -4z M-12 -4h24" fill="none" stroke="#8be3ff" strokeWidth="2" strokeLinejoin="round" />
          </g>
        ))}
      </svg>
    </section>
  );
}

function FinalCall() {
  return (
    <section id="begin" className="landing-final reveal" aria-labelledby="begin-title">
      <p className="eyebrow">System notification</p>
      <h2 id="begin-title" className="landing-final-title">Your awakening is waiting.</h2>
      <p className="landing-section-lead">Create your account, choose your experience card and take the placement check. Your first mission starts right after.</p>
      <CallsToAction />
    </section>
  );
}

/** Reveal sections once as they scroll into view; content stays visible without JS, IO or motion. */
function useReveal(rootRef) {
  useEffect(() => {
    const root = rootRef.current;
    const motionOk = window.matchMedia('(prefers-reduced-motion: no-preference)').matches;
    if (!root || !motionOk || typeof IntersectionObserver !== 'function') return undefined;
    const items = [...root.querySelectorAll('.reveal')];
    const io = new IntersectionObserver((entries) => {
      for (const entry of entries) if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    root.classList.add('js-reveal');
    items.forEach((el) => io.observe(el));
    return () => { io.disconnect(); root.classList.remove('js-reveal'); };
  }, [rootRef]);
}

export function LandingPage() {
  const rootRef = useRef(null);
  useReveal(rootRef);
  // Arriving via in-app navigation: move focus to the page title (as the app shell does).
  // A direct load keeps the natural order, so the first Tab reaches the skip link and header.
  useEffect(() => { if ((window.history.state?.idx ?? 0) > 0) document.getElementById('hero-title')?.focus({ preventScroll: true }); }, []);
  return (
    <div className="landing" ref={rootRef} id="top">
      <a className="skip-link" href="#main">Skip to content</a>
      <AmbientBackground />
      <LandingHeader />
      <main id="main" tabIndex={-1} className="landing-main">
        <Hero />
        <Awakening />
        <Missions />
        <Ranks />
        <ManaArtifacts />
        <FinalCall />
      </main>
      <footer className="landing-footer">
        <div className="landing-footer-inner">
          <Brand />
          <p>Hunter System, a college project for learning Python.</p>
          <p className="landing-disclaimer">
            Fan-inspired artwork belongs to its respective owners. This project is not affiliated with, endorsed by or sponsored by the creators or publishers of Solo Leveling.
          </p>
          <p className="landing-footer-links"><Link to="/login">Log in</Link><span aria-hidden="true">·</span><Link to="/register">Create account</Link></p>
        </div>
      </footer>
    </div>
  );
}
