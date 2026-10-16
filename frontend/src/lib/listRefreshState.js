/**
 * Keep a refresh interaction stable while a filtered list is being fetched.
 * A late response must not replace the user's newest query, and an existing
 * result should remain visible while that query is in flight.
 */
export function isCurrentListRequest(requestId, latestRequestId) {
  return requestId === latestRequestId;
}

export function shouldShowInitialListSkeleton(loading, rows = []) {
  return Boolean(loading) && (!Array.isArray(rows) || rows.length === 0);
}

/**
 * A list fallback (e.g. a Supabase `{ data, error }` result) that errored or returned no array is a
 * failed load: callers keep the rows already on screen and report the failure instead of replacing
 * them with an empty list. Returns the rows, or null on failure.
 */
export function fallbackListRows({ data, error } = {}) {
  return !error && Array.isArray(data) ? data : null;
}

/**
 * Per-row caches (e.g. session data keyed by row id) describe the rows on screen, so they are cleared
 * only when a request wins and replaces those rows, never when a request starts. A request that fails
 * or is superseded, even while an older one is still pending, leaves the rows and their cached data
 * from the last successful load intact. Returns whether the caches were cleared.
 */
export function clearRowCachesForWinningRequest(requestId, latestRequestId, caches = []) {
  if (!isCurrentListRequest(requestId, latestRequestId)) return false;
  caches.forEach((cache) => { cache.value = {}; });
  return true;
}

/**
 * Page-level outcome of list loads. `succeeded(key)` records which query the rows on screen answer
 * and clears `failedRef`. `failed(key)` sets `failedRef` (one refresh-failure hint for every caller)
 * and returns whether the rows may stay: only when they answer that same query, never e.g. branch
 * A's rows while branch B is selected (Codex r4289977924). `cleared()` forgets the shown rows.
 */
export function createListLoadOutcome(failedRef) {
  let shownKey = null;
  return {
    succeeded(key) { shownKey = key; failedRef.value = false; },
    failed(key) { failedRef.value = true; const keep = key === shownKey; if (!keep) shownKey = null; return keep; },
    cleared() { shownKey = null; failedRef.value = false; },
  };
}
