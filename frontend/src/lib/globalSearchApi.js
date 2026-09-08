// Keep single-character CJK names searchable while retaining a bounded request.
const MIN_QUERY_LENGTH = 1;
const RESULT_LIMIT = 5;

export { MIN_QUERY_LENGTH, RESULT_LIMIT };

export function createLatestRequestGuard() {
  let latest = 0;
  return {
    next() {
      latest += 1;
      return latest;
    },
    isCurrent(id) {
      return id === latest;
    },
  };
}

export async function fetchGlobalSearch(query, token, { signal } = {}) {
  const normalized = String(query || '').trim();
  if (normalized.length < MIN_QUERY_LENGTH) {
    return { query: normalized, groups: [] };
  }

  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  const params = new URLSearchParams({ q: normalized, limit: String(RESULT_LIMIT) });
  const response = await fetch(`${baseUrl}/v1/global-search?${params.toString()}`, {
    headers: {
      Authorization: `Bearer ${token || ''}`,
      Accept: 'application/json',
    },
    signal,
  });

  if (!response.ok) {
    throw new Error(`Global search failed (${response.status})`);
  }

  const payload = await response.json();
  return {
    query: normalized,
    groups: Array.isArray(payload?.groups) ? payload.groups : [],
  };
}
