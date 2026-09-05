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
