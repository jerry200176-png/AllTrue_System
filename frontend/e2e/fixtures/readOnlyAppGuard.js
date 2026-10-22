// @ts-check
/**
 * Read-only guard for production acceptance specs.
 *
 * HTTP reads pass through on every origin. App adoption telemetry is answered
 * locally with 204; every other HTTP write, including third-party telemetry,
 * is aborted and recorded so the spec can fail. Blocking the first write hop
 * prevents redirect chains from carrying a write back to the app: Playwright
 * routes only the first request in an HTTP redirect chain.
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

/**
 * Install the guard on a browser context (covers popups). Returns the list
 * that collects blocked writes as { method, path }.
 */
export async function installReadOnlyAppGuard(context, appOrigin) {
  const blocked = [];
  await context.route(isHttpUrl, async (route) => {
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
