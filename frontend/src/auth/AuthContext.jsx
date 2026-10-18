import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { ApiError, get, onSessionLost, post, setCsrfToken } from '../api/client.js';

// status: 'loading' | 'anonymous' | 'authenticated'
// lostReason: why the last session ended (session_expired / session_ended / not_authenticated), shown on the login page.
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', user: null, lostReason: null });

  const refresh = useCallback(async () => {
    try {
      const data = await get('/auth/me', { quietSessionLoss: true });
      setState({ status: 'authenticated', user: data.user, lostReason: null });
      return data.user;
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        setCsrfToken(null);
        setState((s) => ({ status: 'anonymous', user: null, lostReason: s.status === 'authenticated' ? err.code : s.lostReason }));
        return null;
      }
      throw err;
    }
  }, []);

  useEffect(() => {
    refresh().catch(() => setState({ status: 'anonymous', user: null, lostReason: 'network_error' }));
  }, [refresh]);

  // Any authenticated request answered with 401: the server ended the session (idle
  // expiry, logout elsewhere). Whatever the server already saved stays saved.
  useEffect(() => onSessionLost((err) => {
    setState((s) => (s.status === 'authenticated' ? { status: 'anonymous', user: null, lostReason: err.code } : s));
  }), []);

  const login = useCallback(async (email, password) => {
    const data = await post('/auth/login', { email, password });
    setState({ status: 'authenticated', user: data.user, lostReason: null });
    return data.user;
  }, []);

  const register = useCallback(async (email, displayName, password) => {
    const data = await post('/auth/register', { email, display_name: displayName, password });
    setState({ status: 'authenticated', user: data.user, lostReason: null });
    return data.user;
  }, []);

  const logout = useCallback(async () => {
    try {
      await post('/auth/logout', undefined, { quietSessionLoss: true });
    } catch {
      // the session may already be gone; the local state is cleared either way
    }
    setCsrfToken(null);
    setState({ status: 'anonymous', user: null, lostReason: null });
  }, []);

  /** Update the cached onboarding state after a step that changes it. */
  const setOnboarding = useCallback((onboardingState) => {
    setState((s) => (s.user ? { ...s, user: { ...s.user, onboarding_state: onboardingState } } : s));
  }, []);

  const value = useMemo(() => ({ ...state, refresh, login, register, logout, setOnboarding }),
    [state, refresh, login, register, logout, setOnboarding]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth outside AuthProvider');
  return ctx;
}

/** Where a signed-in user belongs right now (server-owned onboarding state). */
export function homeFor(user) {
  if (!user) return '/login';
  if (user.role !== 'student') return '/admin';
  if (user.onboarding_state === 'not_started') return '/start';
  if (user.onboarding_state === 'experience_chosen') return '/assessment';
  return '/dashboard';
}
