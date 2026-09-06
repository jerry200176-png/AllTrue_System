export const APP_PAGE_QUERY_KEY = 'app_page';

function asPageSet(allowedPages) {
  return allowedPages instanceof Set ? allowedPages : new Set(allowedPages || []);
}
/** Read a top-level page only when the current role has explicitly exposed it. */
export function parseAppPage(search, allowedPages, fallback = null) {
  const requested = new URLSearchParams(typeof search === 'string' ? search : '').get(APP_PAGE_QUERY_KEY);
  return requested && asPageSet(allowedPages).has(requested) ? requested : fallback;
}

/** Preserve unrelated query/hash state while replacing only the SPA page marker. */
export function buildAppPageUrl({ pathname = '/', search = '', hash = '', page = '' } = {}) {
  const params = new URLSearchParams(typeof search === 'string' ? search : '');
  if (page) params.set(APP_PAGE_QUERY_KEY, String(page));
  else params.delete(APP_PAGE_QUERY_KEY);
  const query = params.toString();
  return `${pathname}${query ? `?${query}` : ''}${hash || ''}`;
}
