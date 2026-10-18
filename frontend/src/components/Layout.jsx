import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { AmbientBackground } from './AmbientBackground.jsx';
import { Icon } from './Icon.jsx';

const STUDENT_NAV = [['/dashboard','Dashboard','dashboard'],['/topics','Topics','topics'],['/history','Rank & coins','history'],['/store','Badge store','store'],['/profile','Profile','profile']];
const ADMIN_NAV = [['/admin','Overview','dashboard'],['/admin/students','Students','students'],['/admin/topics','Topics','topics'],['/admin/questions','Questions','questions'],['/admin/badges','Badges','badges'],['/admin/audit','Audit','audit']];
function Brand() { return <span className="brand"><span className="brand-mark"><Icon name="code" /></span><span>Hunter System<small>LEARN PYTHON · KEEP GROWING</small></span></span>; }
function Navigation({ items, onNavigate }) { return <nav aria-label="Main" className="nav"><ul>{items.map(([to,label,icon])=><li key={to}><NavLink to={to} end={to==='/admin'} onClick={onNavigate}><Icon name={icon}/>{label}</NavLink></li>)}</ul></nav>; }

/** One shell/background; modal drawer supplies native focus containment/inertness. */
export function Layout({ minimal = false }) {
  const { user, logout } = useAuth();
  const location = useLocation(), navigate = useNavigate();
  const [menuOpen,setMenuOpen] = useState(false), [logoutPending,setLogoutPending] = useState(false);
  const logoutBusy=useRef(false), mainRef=useRef(null), drawerRef=useRef(null), menuRef=useRef(null);
  const hasNavigation=!!user && !minimal;
  const items=user?.role==='admin'?ADMIN_NAV:STUDENT_NAV;
  const section=items.filter(([to])=>location.pathname===to || to!=='/admin' && location.pathname.startsWith(to+'/')).at(-1)?.[1]
    || ({'/login':'Log in','/register':'Create account','/start':'Experience','/assessment':'Placement check','/assessment/result':'Placement result'}[location.pathname]) || 'Python learning';
  useEffect(()=>{
    setMenuOpen(false);
    let tries=0,timer;
    const focusTitle=()=>{const h1=mainRef.current?.querySelector('h1');if(h1||tries++>20)(h1??mainRef.current)?.focus();else timer=setTimeout(focusTitle,50);};
    focusTitle(); return ()=>clearTimeout(timer);
  },[location.pathname]);
  useEffect(()=>{
    const drawer=drawerRef.current;
    if(!drawer)return;
    if(menuOpen){drawer.showModal();drawer.querySelector('button')?.focus();const previous=document.body.style.overflow;document.body.style.overflow='hidden';return()=>{drawer.close();document.body.style.overflow=previous;};}
    if(drawer.open)drawer.close();
  },[menuOpen]);
  useEffect(()=>{
    const media=window.matchMedia('(min-width: 761px)');
    const resize=()=>{if(media.matches)setMenuOpen(false);};media.addEventListener('change',resize);
    return()=>media.removeEventListener('change',resize);
  },[]);
  const closeMenu=()=>{setMenuOpen(false);menuRef.current?.focus();};
  const containDrawerFocus=e=>{
    if(e.key!=='Tab')return;
    const controls=[...e.currentTarget.querySelectorAll('a[href], button:not(:disabled)')].filter(node=>node.getClientRects().length>0);
    const first=controls[0],last=controls.at(-1);
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
  };
  const onLogout=async()=>{
    if(logoutBusy.current)return;
    if(document.body.dataset.adminDirty==='true'&&!window.confirm('Discard unsaved changes and log out?'))return;
    logoutBusy.current=true;setLogoutPending(true);
    try{await logout();navigate('/login',{replace:true});}finally{logoutBusy.current=false;setLogoutPending(false);}
  };
  return <div className={`app${hasNavigation?' app-with-sidebar':' app-minimal'}`}>
    <a className="skip-link" href="#main">Skip to content</a>
    <AmbientBackground/>
    {hasNavigation && <>
      <aside className="desktop-sidebar"><Brand/><p className="nav-label">{user.role==='admin'?'ADMINISTRATION':'YOUR WORKSPACE'}</p><Navigation items={items}/><div className="sidebar-bottom"><span className="sidebar-avatar" aria-hidden="true">{user.display_name.slice(0,1).toUpperCase()}</span><div><strong>{user.display_name}</strong><small>{user.role==='admin'?'Administrator':'Python learner'}</small></div></div></aside>
      <dialog ref={drawerRef} id="navigation-drawer" className="mobile-drawer" aria-labelledby="drawer-title" onKeyDown={containDrawerFocus} onCancel={()=>setMenuOpen(false)} onClose={()=>setMenuOpen(false)} onClick={e=>{if(e.target===e.currentTarget)closeMenu();}}>
        <div className="drawer-heading"><h2 id="drawer-title">{user.role==='admin'?'Administrator navigation':'Student navigation'}</h2><button type="button" className="btn btn-ghost" onClick={closeMenu} aria-label="Close navigation"><Icon name="close"/></button></div>
        <Brand/><Navigation items={items} onNavigate={()=>setMenuOpen(false)}/>
      </dialog>
    </>}
    <div className="workspace">
      <header className="topbar"><div className="topbar-inner">
        {hasNavigation?<><button ref={menuRef} type="button" className="btn btn-ghost menu-toggle" aria-expanded={menuOpen} aria-controls="navigation-drawer" onClick={()=>setMenuOpen(true)}><Icon name="menu"/>Menu</button><div className="breadcrumbs" aria-label="Current section"><span>{user.role==='admin'?'Administration':'Hunter System'}</span><span aria-hidden="true">/</span><strong>{section}</strong></div></>:<Brand/>}
        {user && <div className="topbar-user"><span className="muted user-name">{user.display_name}</span><button type="button" className="btn btn-ghost" disabled={logoutPending} onClick={onLogout}>{logoutPending?'Logging out…':'Log out'}</button></div>}
      </div></header>
      <main id="main" ref={mainRef} tabIndex={-1} className="main"><Outlet/></main>
      <footer className="footer muted">Hunter System — learn Python, one topic at a time.</footer>
    </div>
  </div>;
}
