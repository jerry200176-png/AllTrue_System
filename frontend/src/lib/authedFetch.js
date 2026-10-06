import { supabase } from '../supabase';

export async function getAccessToken() {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
}

// Attaches the Supabase session token and returns the raw Response; callers keep
// their own headers (Content-Type/Accept), credentials and error handling.
// Pass `token` when the caller already resolved/checked it. A missing token is
// sent as "Bearer undefined" exactly like the old hand-built headers.
export async function authedFetch(url, init = {}, token) {
  const t = token === undefined ? await getAccessToken() : token;
  // new Headers() keeps every HeadersInit form (object, Headers, tuple array).
  const headers = new Headers(init.headers);
  headers.set('Authorization', `Bearer ${t}`);
  return fetch(url, { ...init, headers });
}

// First dashboard loads can race the session: the caller's token may be stale
// or missing (it was read from localStorage). Always send the live session
// token, and on a 401 retry once with a freshly resolved one (#3681).
export async function authedFetchRetry401(url, init = {}) {
  const res = await authedFetch(url, init);
  if (res.status !== 401) return res;
  return authedFetch(url, init);
}
