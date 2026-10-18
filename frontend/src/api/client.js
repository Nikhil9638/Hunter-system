// The single API client for the Hunter System PHP API (docs/api-contract.md).
// - Same-origin cookie session (HSSESSID, HttpOnly): nothing about the session is
//   stored by JavaScript; only the CSRF token is kept in memory.
// - The CSRF token is taken from register / login / GET /auth/me responses and
//   sent as X-CSRF-Token on every state-changing request.
// - Errors are normalized to ApiError {status, code, message, details}.
// - Requests are NEVER retried automatically (purchases, submissions and answer
//   saves are not idempotent from the user's point of view).

const BASE = `${import.meta.env.BASE_URL}api/v1`;

let csrfToken = null;
const sessionListeners = new Set();

export class ApiError extends Error {
  constructor(status, code, message, details = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details || {};
  }
}

export const SESSION_LOST_CODES = new Set(['not_authenticated', 'session_expired', 'session_ended']);

/** Subscribe to "the server says this session is gone" (401 on an authenticated call). */
export function onSessionLost(listener) {
  sessionListeners.add(listener);
  return () => sessionListeners.delete(listener);
}

export function setCsrfToken(token) {
  csrfToken = token || null;
}

export function isAbort(err) {
  return err?.name === 'AbortError';
}

/**
 * @param {string} method
 * @param {string} path  path below /api/v1, e.g. "/me/dashboard"
 * @param {{body?: unknown, signal?: AbortSignal, quietSessionLoss?: boolean}} [options]
 * @returns {Promise<any>} the "data" member of the response
 */
export async function api(method, path, { body, signal, quietSessionLoss = false } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken;

  let response;
  try {
    response = await fetch(BASE + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      signal,
    });
  } catch (err) {
    if (isAbort(err)) throw err;
    throw new ApiError(0, 'network_error', 'The server could not be reached. Check your connection and try again.');
  }

  let json = null;
  try {
    json = await response.json();
  } catch {
    json = null;
  }

  if (!response.ok) {
    const e = json?.error ?? {};
    const err = new ApiError(response.status, e.code ?? 'http_' + response.status, e.message ?? 'Something went wrong.', e.details ?? {});
    if (response.status === 401 && SESSION_LOST_CODES.has(err.code) && !quietSessionLoss) {
      csrfToken = null;
      sessionListeners.forEach((l) => l(err));
    }
    throw err;
  }
  if (json && json.data && typeof json.data === 'object' && typeof json.data.csrf_token === 'string') {
    csrfToken = json.data.csrf_token;
  }
  return json ? json.data : null;
}

export const get = (path, opts) => api('GET', path, opts);
export const post = (path, body, opts) => api('POST', path, { ...opts, body });
export const put = (path, body, opts) => api('PUT', path, { ...opts, body });

/** Human-readable field errors from a 400 validation_failed response. */
export function fieldErrors(err) {
  return err instanceof ApiError && err.code === 'validation_failed' ? err.details.fields ?? {} : {};
}
