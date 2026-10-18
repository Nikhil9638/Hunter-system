/** Original small line icons; labels carry their meaning. */
export function Icon({ name, className = '' }) {
  const paths = {
    dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    topics: 'M4 4h6a3 3 0 0 1 3 3v14a4 4 0 0 0-4-2H4z M13 7a3 3 0 0 1 3-3h5v15h-4a4 4 0 0 0-4 2',
    history: 'M4 5v5h5 M4 10a8 8 0 1 1 0 5 M12 7v6l4 2',
    store: 'M4 8h16l-1 13H5z M8 8V6a4 4 0 0 1 8 0v2',
    profile: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a8 8 0 0 1 16 0v2',
    students: 'M13 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M3 20v-2a7 7 0 0 1 14 0v2 M17 4a3 3 0 0 1 0 6 M20 14a6 6 0 0 1 1 4v2',
    questions: 'M4 3h16v18H4z M9 8a3 3 0 1 1 5 2c-2 1-2 1-2 3 M12 16h.01',
    badges: 'M12 2 21 7v10l-9 5-9-5V7z M12 7l2 3 3 1-2 3v3l-3-1-3 1v-3l-2-3 3-1z',
    audit: 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h4',
    menu: 'M4 6h16 M4 12h16 M4 18h16', close: 'M6 6l12 12 M18 6 6 18',
    code: 'M8 6 2 12l6 6 M16 6l6 6-6 6 M14 3l-4 18', lock: 'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4',
  };
  return <svg className={`icon ${className}`} aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={paths[name] || paths.code} /></svg>;
}
