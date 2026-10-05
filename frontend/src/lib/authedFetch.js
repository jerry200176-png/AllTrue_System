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
  return fetch(url, { ...init, headers: { ...init.headers, Authorization: `Bearer ${t}` } });
}
