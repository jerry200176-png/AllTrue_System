// @ts-check
/**
 * Read-only guard for production acceptance specs.
 *
 * Only the app's own origin (the acceptance base URL) is intercepted. Reads
 * pass through, adoption telemetry is answered locally with 204, and every
 * other write to the app origin is aborted and recorded so the spec can fail.
 * Third-party hosts (e.g. Sentry ingest) are never intercepted or recorded.
 * Self-tests: readOnlyAppGuard.test.js (unit) and
 * e2e/read-only-app-guard.spec.js (browser, local servers only).
 */

export const READ_ONLY_METHODS = Object.freeze(['GET', 'HEAD', 'OPTIONS']);
export const ADOPTION_TELEMETRY_PATH = '/api/v1/adoption/events';

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

/**
 * What the guard does with a request:
 * 'ignore' (not the app origin), 'allow' (read), 'stub-telemetry', or 'block'.
 */
export function classifyAppRequest(appOrigin, method, url) {
  if (!isAppOriginUrl(appOrigin, url)) return 'ignore';
  const verb = String(method || '').toUpperCase();
  if (READ_ONLY_METHODS.includes(verb)) return 'allow';
  if (verb === 'POST' && new URL(String(url)).pathname === ADOPTION_TELEMETRY_PATH) {
    return 'stub-telemetry';
  }
  return 'block';
}

/**
 * Install the guard on a browser context (covers popups). Returns the list
 * that collects blocked writes as { method, path }.
 */
export async function installReadOnlyAppGuard(context, appOrigin) {
  const blocked = [];
  await context.route((url) => isAppOriginUrl(appOrigin, url), async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const action = classifyAppRequest(appOrigin, method, request.url());
    if (action === 'block') {
      blocked.push({ method, path: new URL(request.url()).pathname });
      await route.abort('blockedbyclient');
    } else if (action === 'stub-telemetry') {
      await route.fulfill({ status: 204, body: '' });
    } else {
      await route.continue();
    }
  });
  return blocked;
}
