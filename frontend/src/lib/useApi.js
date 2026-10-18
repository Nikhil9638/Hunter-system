import { useCallback, useEffect, useRef, useState } from 'react';
import { isAbort } from '../api/client.js';

/**
 * Load data for a screen. Obsolete requests are aborted (route change, unmount,
 * reload), so a slow old response can never overwrite newer data.
 * @returns {{data:any, error:any, loading:boolean, reload:() => void, setData:Function}}
 */
export function useApi(loader, deps) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const [tick, setTick] = useState(0);
  const loaderRef = useRef(loader);
  loaderRef.current = loader;

  useEffect(() => {
    const ctrl = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    loaderRef.current(ctrl.signal)
      .then((data) => { if (!ctrl.signal.aborted) setState({ data, error: null, loading: false }); })
      .catch((error) => { if (!isAbort(error) && !ctrl.signal.aborted) setState({ data: null, error, loading: false }); });
    return () => ctrl.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  const setData = useCallback((fn) => setState((s) => ({ ...s, data: typeof fn === 'function' ? fn(s.data) : fn })), []);
  return { ...state, reload, setData };
}

/** Run a mutation once at a time; `pending` disables the triggering control. */
export function useAction() {
  const [pending, setPending] = useState(false);
  const busy = useRef(false);
  const run = useCallback(async (fn) => {
    if (busy.current) return undefined;            // ignore double clicks while a request is in flight
    busy.current = true;
    setPending(true);
    try {
      return await fn();
    } finally {
      busy.current = false;
      setPending(false);
    }
  }, []);
  return [pending, run];
}
