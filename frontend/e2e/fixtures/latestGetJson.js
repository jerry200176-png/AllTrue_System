// @ts-check

/**
 * Keep the latest successful GET JSON body the page itself received for `pathname`,
 * so a read-only acceptance compares the screen with exactly what the app was sent
 * (no extra request). Call before the action that makes the page load it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} pathname e.g. '/api/v1/bindings'
 * @returns {() => ({ url: URL, body: any } | null)} null until the first response
 */
export function recordLatestGetJson(page, pathname) {
  /** @type {{ url: URL, body: any } | null} */
  let latest = null;
  page.on('response', async (response) => {
    const url = new URL(response.url());
    if (url.pathname !== pathname || response.request().method() !== 'GET' || !response.ok()) return;
    try {
      latest = { url, body: await response.json() };
    } catch { /* body unavailable after navigation */ }
  });
  return () => latest;
}
