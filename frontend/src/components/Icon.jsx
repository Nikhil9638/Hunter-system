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
    mana: 'M12 2 19 9 12 22 5 9z M5 9h14 M9 9l3 13 3-13 M9 9l3-7 3 7',
    gate: 'M4 21V9a8 8 0 0 1 16 0v12 M4 21h16 M8 21v-9a4 4 0 0 1 8 0v9 M12 5v3',
    seal: 'M12 2l2.4 2.4 3.3-.6.6 3.3L21 9.5l-1.6 3 1.6 3-2.7 2.4-.6 3.3-3.3-.6L12 23l-2.4-2.4-3.3.6-.6-3.3L3 15.5l1.6-3L3 9.5l2.7-2.4.6-3.3 3.3.6z M8.5 12.5l2.5 2.5 4.5-5',
    hourglass: 'M6 2h12 M6 22h12 M7 2c0 5 10 6 10 10S7 17 7 22 M17 2c0 5-10 6-10 10s10 5 10 10',
    sword: 'M14.5 3H21v6.5L10 20.5 3.5 14z M6 17l-3 3 M3 20l1 1 M8 12l4 4',
    shield: 'M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z',
    info: 'M12 22a10 10 0 1 1 0-20 10 10 0 0 1 0 20z M12 11v6 M12 7h.01',
  };
  return <svg className={`icon ${className}`} aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={paths[name] || paths.code} /></svg>;
}
