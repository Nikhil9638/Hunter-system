import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';

const NAV = [
  ['/dashboard', 'Dashboard'], ['/topics', 'Topics'], ['/history', 'Rank & coins'], ['/store', 'Badge store'], ['/profile', 'Profile'],
];

/** App shell: skip link, header navigation, main landmark; focuses the page title on route change. */
export function Layout({ minimal = false }) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [menuOpen, setMenuOpen] = useState(false);
  const [logoutPending, setLogoutPending] = useState(false);
  const logoutBusy = useRef(false);
  const mainRef = useRef(null);

  // On navigation, move focus to the new page's title (announced by screen readers).
  // Pages render their h1 after loading, so wait briefly for it; fall back to <main>.
  useEffect(() => {
    setMenuOpen(false);
    let tries = 0;
    let timer;
    const focusTitle = () => {
      const h1 = mainRef.current?.querySelector('h1');
      if (h1 || tries++ > 20) (h1 ?? mainRef.current)?.focus();
      else timer = setTimeout(focusTitle, 50);
    };
    focusTitle();
    return () => clearTimeout(timer);
  }, [location.pathname]);

  const onLogout = async () => {
    if (logoutBusy.current) return;
    if (document.body.dataset.adminDirty === 'true' && !window.confirm('Discard unsaved changes and log out?')) return;
    logoutBusy.current = true;
    setLogoutPending(true);
    try { await logout(); navigate('/login', { replace: true }); }
    finally { logoutBusy.current = false; setLogoutPending(false); }
  };

  return (
    <div className="app">
      <a className="skip-link" href="#main">Skip to content</a>
      <header className="topbar">
        <div className="topbar-inner">
          <span className="brand"><span className="brand-mark" aria-hidden="true">H</span> Hunter System</span>
          {user && !minimal && (
            <>
              <button type="button" className="btn btn-ghost menu-toggle" aria-expanded={menuOpen} aria-controls="main-nav"
                      onClick={() => setMenuOpen((o) => !o)}>Menu</button>
              <nav id="main-nav" aria-label="Main" className={menuOpen ? 'nav open' : 'nav'}>
                <ul>
                  {(user.role === 'admin' ? [['/admin','Overview'],['/admin/students','Students'],['/admin/topics','Topics'],['/admin/questions','Questions'],['/admin/badges','Badges'],['/admin/audit','Audit']] : NAV).map(([to, label]) => (
                    <li key={to}><NavLink to={to} end={to === '/admin'}>{label}</NavLink></li>
                  ))}
                </ul>
              </nav>
            </>
          )}
          {user && (
            <div className="topbar-user">
              <span className="muted user-name">{user.display_name}</span>
              <button type="button" className="btn btn-ghost" disabled={logoutPending} onClick={onLogout}>{logoutPending ? 'Logging out…' : 'Log out'}</button>
            </div>
          )}
        </div>
      </header>
      <main id="main" ref={mainRef} tabIndex={-1} className="main">
        <Outlet />
      </main>
      <footer className="footer muted">Hunter System — learn Python, one topic at a time.</footer>
    </div>
  );
}
