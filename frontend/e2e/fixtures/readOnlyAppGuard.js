// @ts-check
/**
 * Read-only guard for production acceptance specs.
 *
 * HTTP reads pass through on every origin. App adoption telemetry is answered
 * locally with 204; every other HTTP write, including third-party telemetry,
 * is aborted and recorded so the spec can fail. Blocking the first write hop
 * prevents redirect chains from carrying a write back to the app: Playwright
 * routes only the first request in an HTTP redirect chain.
 * describeBlockedWrite / blockedWriteAnnotations name a blocked write for the
 * public CI log (METHOD host /path, ids dropped; acceptance-report-gate.mjs prints them).
 * Self-tests: readOnlyAppGuard.test.js (unit) and
 * e2e/read-only-app-guard.spec.js (browser, local servers only).
 */

export const READ_ONLY_METHODS = Object.freeze(['GET', 'HEAD', 'OPTIONS']);
export const ADOPTION_TELEMETRY_PATH = '/api/v1/adoption/events';
/**
 * Reads the app sends as POST, with the query in the body; the server writes
 * nothing (StudentClassController::sessionDates also serves the GET route).
 * The guard still aborts them like any write. A spec may count such a blocked
 * read (isKnownPostRead) instead of failing on it.
 */
export const KNOWN_POST_READ_PATHS = Object.freeze(['/api/v1/student-classes/session-dates']);

/** Origin of an http(s) base URL, or '' when it is missing or not http(s). */
export function appOriginOf(baseUrl) {
  try {
    const url = new URL(String(baseUrl || ''));
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.origin : '';
  } catch {
    return '';
  }
}

/** True when `url` (URL or string) belongs to the app origin. */
export function isAppOriginUrl(appOrigin, url) {
  if (!appOrigin) return false;
  try {
    return new URL(String(url)).origin === appOrigin;
  } catch {
    return false;
  }
}

function isHttpUrl(url) {
  try {
    const protocol = new URL(String(url)).protocol;
    return protocol === 'http:' || protocol === 'https:';
  } catch {
    return false;
  }
}

/**
 * What the guard does with a request:
 * 'ignore' (not HTTP), 'allow' (read), 'stub-telemetry', or 'block'.
 */
export function classifyAppRequest(appOrigin, method, url) {
  if (!isHttpUrl(url)) return 'ignore';
  const verb = String(method || '').toUpperCase();
  if (READ_ONLY_METHODS.includes(verb)) return 'allow';
  if (verb === 'POST' && isAppOriginUrl(appOrigin, url)
      && new URL(String(url)).pathname === ADOPTION_TELEMETRY_PATH) {
    return 'stub-telemetry';
  }
  return 'block';
}

/** True when a blocked request { method, origin, path } is a known POST read to the app origin. */
export function isKnownPostRead(appOrigin, blocked) {
  return Boolean(appOrigin) && blocked?.method === 'POST' && blocked.origin === appOrigin
    && KNOWN_POST_READ_PATHS.includes(blocked.path);
}

export const BLOCKED_WRITE_ANNOTATION = 'blocked-write';
const MAX_ANNOTATED_WRITES = 10;
const MAX_PATH_SEGMENTS = 12;
// Only plain route words and API versions survive; ids, tokens, hashes and anything with digits,
// capitals or non-ASCII text become `:id`. The acceptance gate re-checks this shape before it prints.
const ROUTE_WORD = /^(?:v\d{1,3}|[a-z]+(?:[-_][a-z]+)*)$/;

/**
 * Public-log-safe name of a blocked write, `METHOD host /path`: the host, no query string or
 * fragment, and every path segment that is not a plain route word replaced by `:id`.
 * The repository is public, so no body, header or id may reach a job log.
 */
export function describeBlockedWrite(write) {
  const method = /^[A-Z]{3,10}$/.test(String(write?.method)) ? write.method : 'UNKNOWN';
  let host = 'unknown-host';
  try { host = new URL(String(write?.origin)).host || host; } catch { /* keep the placeholder */ }
  const segments = String(write?.path || '').split('?')[0].split('#')[0].split('/').filter(Boolean)
    .slice(0, MAX_PATH_SEGMENTS)
    .map((segment) => (segment.length <= 32 && ROUTE_WORD.test(segment) ? segment : ':id'));
  return `${method} ${host} /${segments.join('/')}`;
}

/**
 * One `blocked-write` test annotation per distinct sanitized write (repeats become ` xN`),
 * for the spec to push on testInfo before its read-only assertion fails.
 */
export function blockedWriteAnnotations(writes) {
  const counts = new Map();
  for (const write of writes) {
    const label = describeBlockedWrite(write);
    counts.set(label, (counts.get(label) || 0) + 1);
  }
  return [...counts].slice(0, MAX_ANNOTATED_WRITES).map(([label, count]) => ({
    type: BLOCKED_WRITE_ANNOTATION,
    description: count > 1 ? `${label} x${count}` : label,
  }));
}

/**
 * Install the guard on a browser context (covers popups). Returns the list
 * that collects blocked writes as { method, origin, path }.
 */
export async function installReadOnlyAppGuard(context, appOrigin) {
  const blocked = [];
  await context.route(isHttpUrl, async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const action = classifyAppRequest(appOrigin, method, request.url());
    if (action === 'block') {
      const url = new URL(request.url());
      blocked.push({ method, origin: url.origin, path: url.pathname });
      await route.abort('blockedbyclient');
    } else if (action === 'stub-telemetry') {
      await route.fulfill({ status: 204, body: '' });
    } else {
      await route.continue();
    }
  });
  return blocked;
}
