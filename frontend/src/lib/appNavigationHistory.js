export const APP_PAGE_QUERY_KEY = 'app_page';
export const COURSE_FOCUS_QUERY_KEYS = ['student_id', 'course_id'];

function courseLinkId(value) {
  const text = String(value ?? '');
  if (!/^\d+$/.test(text)) return null;
  const id = Number(text);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

/** An identifier is navigation context, never a grant of campus or record access. */
export function parseCourseFocusSearch(search) {
  const params = new URLSearchParams(search);
  if (params.get(APP_PAGE_QUERY_KEY) !== 'course-mgmt'
    || [APP_PAGE_QUERY_KEY, 'branch_id', ...COURSE_FOCUS_QUERY_KEYS].some(key => params.getAll(key).length !== 1)) return null;
  const branchId = courseLinkId(params.get('branch_id'));
  const studentId = courseLinkId(params.get('student_id'));
  const courseId = courseLinkId(params.get('course_id'));
  return branchId && studentId && courseId ? { branchId, studentId, courseId } : null;
}

/** Build a shareable URL without copying names, unrelated query parameters or auth fragments. */
export function buildCourseFocusUrl(base, { branchId, studentId, courseId } = {}) {
  const ids = [branchId, studentId, courseId].map(courseLinkId);
  if (ids.some(id => !id)) return null;
  try {
    const current = new URL(base);
    if (!['http:', 'https:'].includes(current.protocol)) return null;
    const url = new URL(current.origin);
    url.pathname = current.pathname;
    url.search = new URLSearchParams({ app_page: 'course-mgmt', branch_id: String(ids[0]), student_id: String(ids[1]), course_id: String(ids[2]) }).toString();
    return url.href;
  } catch { return null; }
}

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
