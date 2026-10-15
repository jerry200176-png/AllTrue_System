// @ts-check
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { appOriginOf, installReadOnlyAppGuard } from './fixtures/readOnlyAppGuard.js';

/**
 * Browser self-test for the production acceptance read-only guard. It uses two
 * local servers on different ports (different origins) and never touches
 * production: "app" plays the acceptance base URL, "third" plays Sentry.
 */

function startServer() {
  const received = [];
  const server = http.createServer((req, res) => {
    received.push({ method: req.method, path: req.url });
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }
    if (req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><title>guard self-test</title>');
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end('{}');
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = /** @type {import('node:net').AddressInfo} */ (server.address());
      resolve({ server, received, base: `http://127.0.0.1:${port}` });
    });
  });
}

test.describe('read-only acceptance guard self-test', () => {
  test.use({ serviceWorkers: 'block' });

  let app;
  let third;
  test.beforeAll(async () => {
    app = await startServer();
    third = await startServer();
  });
  test.afterAll(async () => {
    app?.server.close();
    third?.server.close();
  });
  test.beforeEach(() => {
    app.received.length = 0;
    third.received.length = 0;
  });

  test('(a) a write to the app origin is blocked and recorded', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);

    const outcome = await page.evaluate(async () => {
      try {
        await fetch('/api/v1/schedules/9', { method: 'PATCH', body: '{}' });
        return 'sent';
      } catch {
        return 'failed';
      }
    });

    expect(outcome).toBe('failed');
    expect(blocked).toEqual([{ method: 'PATCH', path: '/api/v1/schedules/9' }]);
    expect(app.received.filter((r) => r.method !== 'GET')).toEqual([]);
    // The acceptance spec's afterEach makes exactly this assertion, so the run fails.
    expect(() => expect(blocked).toEqual([])).toThrow();
  });

  test('reads pass and adoption telemetry is answered locally', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);

    const statuses = await page.evaluate(async () => [
      (await fetch('/api/v1/schedules')).status,
      (await fetch('/api/v1/adoption/events', { method: 'POST', body: '{}' })).status,
    ]);

    expect(statuses).toEqual([200, 204]);
    expect(blocked).toEqual([]);
    expect(app.received.map((r) => r.path)).not.toContain('/api/v1/adoption/events');
  });

  test('(b) a Sentry-style third-party POST is not intercepted or flagged', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);

    const status = await page.evaluate(async (url) => (
      await fetch(url, { method: 'POST', body: '{}' })
    ).status, `${third.base}/api/4500000/envelope/`);

    expect(status).toBe(200);
    expect(blocked).toEqual([]);
    expect(third.received).toContainEqual({ method: 'POST', path: '/api/4500000/envelope/' });
  });

  test('popup pages are guarded too', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);
    const [popup] = await Promise.all([
      page.waitForEvent('popup'),
      page.evaluate(() => { window.open('/', '_blank'); }),
    ]);
    await popup.waitForLoadState();
    await popup.evaluate(() => fetch('/api/v1/students/1', { method: 'DELETE' }).catch(() => null));

    expect(blocked).toEqual([{ method: 'DELETE', path: '/api/v1/students/1' }]);
    expect(app.received.filter((r) => r.method === 'DELETE')).toEqual([]);
  });
});
