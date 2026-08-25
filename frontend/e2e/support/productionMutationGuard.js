/**
 * Production browser acceptance safety guard.
 *
 * This is test infrastructure only. It does not change application behavior.
 * Install it before the UI login, then call markAuthenticated() immediately
 * after the normal login assertion succeeds.
 */

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const DEFAULT_LOGIN_PATHS = ['/api/v1/auth/login'];

function normalizeLoginPaths(loginPaths) {
  return new Set((loginPaths || DEFAULT_LOGIN_PATHS).map((path) => String(path)));
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ baseURL: string, loginPaths?: string[] }} options
 */
export async function installProductionMutationGuard(page, options) {
  const applicationOrigin = new URL(options.baseURL).origin;
  const loginPaths = normalizeLoginPaths(options.loginPaths);
  let phase = 'login';
  const blocked = [];
  const allowedNonGet = [];

  const handler = async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const url = new URL(request.url());

    // The acceptance guard covers only the production application origin.
    // Browser services such as fonts or analytics are outside this contract.
    if (url.origin !== applicationOrigin || SAFE_METHODS.has(method)) {
      await route.continue();
      return;
    }

    if (phase === 'login' && method === 'POST' && loginPaths.has(url.pathname)) {
      allowedNonGet.push({ method, pathname: url.pathname });
      await route.continue();
      return;
    }

    const entry = { method, pathname: url.pathname };
    blocked.push(entry);
    // Deliberately do not log request headers or body: they may contain
    // credentials, bearer tokens, or student/payment PII.
    await route.abort('blockedbyclient');
  };

  await page.route('**/*', handler);

  return {
    markAuthenticated() {
      phase = 'authenticated';
    },
    phase() {
      return phase;
    },
    blockedRequests() {
      return [...blocked];
    },
    allowedNonGetExceptions() {
      return [...allowedNonGet];
    },
    assertNoUnexpectedMutations() {
      if (!blocked.length) return;
      const summary = blocked.map(({ method, pathname }) => `${method} ${pathname}`).join(', ');
      throw new Error(`Unexpected production mutation request(s) blocked: ${summary}`);
    },
    async dispose() {
      await page.unroute('**/*', handler);
    },
  };
}
