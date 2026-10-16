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
