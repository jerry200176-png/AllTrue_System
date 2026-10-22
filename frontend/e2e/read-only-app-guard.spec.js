// @ts-check
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { appOriginOf, installReadOnlyAppGuard } from './fixtures/readOnlyAppGuard.js';

/**
 * Browser self-test for the production acceptance read-only guard. It uses three
 * local servers on different ports (different origins) and never touches
 * production: "app" plays the acceptance base URL, "third" plays Sentry.
 */

function startServer() {
  const received = [];
  const redirects = new Map();
  const server = http.createServer((req, res) => {
    received.push({ method: req.method, path: req.url });
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }
    if (redirects.has(req.url)) {
      const { status, location } = redirects.get(req.url);
      res.writeHead(status, { Location: location });
      res.end();
      return;
    }
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
      resolve({ server, received, redirects, base: `http://127.0.0.1:${port}` });
    });
  });
}

test.describe('read-only acceptance guard self-test', () => {
  test.use({ serviceWorkers: 'block' });

  let app;
  let third;
  let middle;
  test.beforeAll(async () => {
    app = await startServer();
    third = await startServer();
    middle = await startServer();
  });
  test.afterAll(async () => {
    app?.server.close();
    third?.server.close();
    middle?.server.close();
  });
  test.beforeEach(() => {
    app.received.length = 0;
    third.received.length = 0;
    middle.received.length = 0;
    third.redirects.clear();
    middle.redirects.clear();
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

  test('(b) third-party HTTP writes are blocked before their first hop', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);

    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
      const outcome = await page.evaluate(async ({ url, method }) => {
        try {
          await fetch(url, { method, body: '{}' });
          return 'sent';
        } catch {
          return 'failed';
        }
      }, { url: `${third.base}/api/4500000/envelope/`, method });
      expect(outcome).toBe('failed');
    }

    expect(blocked).toEqual(['POST', 'PUT', 'PATCH', 'DELETE'].map((method) => (
      { method, path: '/api/4500000/envelope/' }
    )));
    expect(third.received.filter((r) => !['GET', 'HEAD', 'OPTIONS'].includes(r.method))).toEqual([]);
    expect(app.received.filter((r) => !['GET', 'HEAD', 'OPTIONS'].includes(r.method))).toEqual([]);
    expect(() => expect(blocked).toEqual([])).toThrow();
  });

  test('the adoption stub cannot exempt another origin', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);
    const outcome = await page.evaluate(async (url) => {
      try {
        await fetch(url, { method: 'POST', body: '{}' });
        return 'sent';
      } catch {
        return 'failed';
      }
    }, `${third.base}/api/v1/adoption/events`);

    expect(outcome).toBe('failed');
    expect(blocked).toEqual([{ method: 'POST', path: '/api/v1/adoption/events' }]);
    expect(third.received.filter((r) => !['GET', 'HEAD', 'OPTIONS'].includes(r.method))).toEqual([]);
  });

  for (const statuses of [[307], [308], [307, 308], [308, 307], [303]]) {
    test(`third-party POST redirect ${statuses.join(' -> ')} cannot send an unsafe first hop`, async ({ page, context }) => {
      const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
      await page.goto(`${app.base}/`);
      if (statuses.length > 1) {
        middle.redirects.set('/redirect', { status: statuses[1], location: `${app.base}/api/v1/schedules/9` });
      }
      third.redirects.set('/redirect', {
        status: statuses[0],
        location: statuses.length > 1 ? `${middle.base}/redirect` : `${app.base}/api/v1/schedules/9`,
      });

      const outcome = await page.evaluate(async (url) => {
        try {
          await fetch(url, { method: 'POST', body: '{}' });
          return 'sent';
        } catch {
          return 'failed';
        }
      }, `${third.base}/redirect`);

      expect(outcome).toBe('failed');
      expect(blocked).toEqual([{ method: 'POST', path: '/redirect' }]);
      for (const server of [app, third, middle]) {
        expect(server.received.filter((r) => !['GET', 'HEAD', 'OPTIONS'].includes(r.method))).toEqual([]);
      }
      expect(() => expect(blocked).toEqual([])).toThrow();
    });
  }

  test('third-party reads and a GET 303 redirect remain native reads', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);
    third.redirects.set('/redirect', { status: 303, location: `${app.base}/read-redirect-target` });

    const statuses = await page.evaluate(async (base) => [
      (await fetch(`${base}/read`)).status,
      (await fetch(`${base}/read`, { method: 'HEAD' })).status,
      (await fetch(`${base}/read`, { method: 'OPTIONS' })).status,
      (await fetch(`${base}/redirect`)).status,
    ], third.base);

    expect(statuses).toEqual([200, 200, 204, 200]);
    expect(blocked).toEqual([]);
    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
      expect(third.received).toContainEqual({ method, path: '/read' });
    }
    expect(app.received).toContainEqual({ method: 'GET', path: '/read-redirect-target' });
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

  test('service workers cannot install a route bypass', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base));
    await page.goto(`${app.base}/`);
    const state = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.register('/guard-sw.js');
      return {
        registered: Boolean(registration),
        active: (await navigator.serviceWorker.getRegistrations()).length,
        controlled: Boolean(navigator.serviceWorker.controller),
      };
    });

    expect(state).toEqual({ registered: false, active: 0, controlled: false });
    expect(app.received.map((r) => r.path)).not.toContain('/guard-sw.js');
    expect(blocked).toEqual([]);
  });
});
