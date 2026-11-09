// @ts-check
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { appOriginOf, installReadOnlyAppGuard, isKnownStatefulRead, isKnownBlockedStatefulRead, unexpectedCreateEntryRequests } from './fixtures/readOnlyAppGuard.js';
import { BILL_EXPORT_PATH, installSingleStudentExportGuard } from './fixtures/directorExportGuard.js';

/**
 * Browser self-test for the production acceptance read-only guard. It uses three
 * local servers on different ports (different origins) and never touches
 * production: "app" plays the acceptance base URL, "third" plays Sentry.
 */

function startServer() {
  const received = [];
  const redirects = new Map();
  const responses = new Map();
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
    if (responses.has(req.url)) {
      const { status, headers, body } = responses.get(req.url);
      res.writeHead(status, headers);
      res.end(body);
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
      resolve({ server, received, redirects, responses, base: `http://127.0.0.1:${port}` });
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
    app.redirects.clear();
    app.responses.clear();
    third.redirects.clear();
    middle.redirects.clear();
  });

  for (const createPost of [false, true]) {
    test(`create-entry counts actually blocked reads and rejects create POST=${createPost}`, async ({ page, context }) => {
      let attempts = 0;
      context.on('request', request => { if (isKnownStatefulRead(app.base, request.method(), request.url())) attempts += 1; });
      const blocked = await installReadOnlyAppGuard(context, app.base);
      await page.goto(`${app.base}/`);
      await page.evaluate(async createPost => {
        await fetch('/api/v1/class-sessions?branch_id=1').catch(() => null);
        if (createPost) await fetch('/api/v1/student-classes', { method: 'POST', body: '{}' }).catch(() => null);
      }, createPost);
      const known = blocked.filter(request => isKnownBlockedStatefulRead(app.base, request));
      const unexpected = unexpectedCreateEntryRequests(app.base, blocked);
      expect(app.received.filter(request => request.path.startsWith('/api/'))).toEqual([]);
      expect(known).toHaveLength(1);
      expect(known.length).toBe(attempts);
      console.log(JSON.stringify({ acceptance: 'create-entry-guard', attempted_stateful_reads: attempts,
        blocked_stateful_reads: known.length, unexpected_requests: unexpected.length, server_api_requests: 0 }));
      if (createPost) {
        expect(unexpected).toContainEqual({ method: 'POST', origin: app.base, path: '/api/v1/student-classes' });
        expect(() => expect(unexpected).toEqual([])).toThrow();
      } else expect(unexpected).toEqual([]);
    });
  }

  test('an unguarded known GET cannot count as blocked create-entry evidence', async ({ page, context }) => {
    let attempts = 0;
    context.on('request', request => { if (isKnownStatefulRead(app.base, request.method(), request.url())) attempts += 1; });
    await page.goto(`${app.base}/`);
    await page.evaluate(() => fetch('/api/v1/class-sessions?branch_id=1'));
    const blocked = [];
    const known = blocked.filter(request => isKnownBlockedStatefulRead(app.base, request));
    expect(app.received).toContainEqual({ method: 'GET', path: '/api/v1/class-sessions?branch_id=1' });
    expect(attempts).toBe(1);
    expect(known).toHaveLength(0);
    expect(() => expect(known.length).toBe(attempts)).toThrow();
  });

  for (const method of ['GET', 'HEAD']) {
    test(`known stateful ${method} never reaches the server while learning reads pass (#528)`, async ({ page, context }) => {
      const blocked = await installReadOnlyAppGuard(context, app.base);
      await page.goto(`${app.base}/`);
      const paths = ['/api/v1/class-sessions?branch_id=1', '/api/v1/class-sessions/',
        '/api/v1/class-sessions/projection?start=2026-09-01&end=2026-09-30',
        '/api/v1/class-sessions/projection/', '/api/v1/%63lass-sessions',
        '/api/v1/class%2dsessions/%70rojection%2f', '/api%2Fv1%2Fclass-sessions',
        '/api//v1/class-sessions//projection/', '/api/v1/unused%2f..%2fclass-sessions',
        '/api/v1/%2563lass-sessions'];
      const outcomes = await page.evaluate(async ({ method, paths }) => {
        const results = [];
        for (const path of paths) {
          try { results.push((await fetch(path, { method })).status); }
          catch { results.push('blocked'); }
        }
        return results;
      }, { method, paths });
      // Native Chromium requests against loopback HTTP counters, not route-call mocks.
      expect(app.received.filter(entry => !['/', '/favicon.ico'].includes(entry.path))).toEqual([]);
      expect(outcomes).toEqual(paths.map(() => 'blocked'));
      expect(blocked).toHaveLength(paths.length);
      const status = await page.evaluate(async () => (await fetch('/api/v1/learning-records?branch_id=1')).status);
      expect(status).toBe(200);
      expect(app.received).toContainEqual({ method: 'GET', path: '/api/v1/learning-records?branch_id=1' });
    });
  }

  for (const status of [301, 302, 303, 307, 308]) {
    test(`single student export redirect ${status} cannot reach a business POST`, async ({ page, context }) => {
      app.redirects.set(BILL_EXPORT_PATH, {
        status, location: `${app.base}/api/v1/learning-records/7/rollback-approval`,
      });
      const guard = await installSingleStudentExportGuard(context, app.base, ['c7'], '合成學生');
      await page.goto(`${app.base}/`);
      const outcome = await page.evaluate(async path => {
        try {
          await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ branch_id: 1, filter: 'all', search: '合成學生', rows: [{ id: 7, package_id: null }] }) });
          return 'sent';
        } catch { return 'blocked'; }
      }, BILL_EXPORT_PATH);
      // This checks actual Chromium traffic received by a loopback server, not route-call mocks.
      expect(app.received.filter(entry => entry.method === 'POST')).toEqual([{ method: 'POST', path: BILL_EXPORT_PATH }]);
      expect(outcome).toBe('blocked');
      expect(guard.exportCount()).toBe(0);
      expect(guard.blocked).toEqual([{ method: 'POST', origin: app.base, path: BILL_EXPORT_PATH }]);
    });
  }

  test('single student export rejects a direct HTML response and consumes the one-shot allowance', async ({ page, context }) => {
    app.responses.set(BILL_EXPORT_PATH, { status: 200, headers: { 'Content-Type': 'text/html' }, body: '<html>synthetic login</html>' });
    const guard = await installSingleStudentExportGuard(context, app.base, ['c7'], '合成學生');
    await page.goto(`${app.base}/`);
    const outcomes = await page.evaluate(async path => {
      const send = async () => {
        try {
          await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ branch_id: 1, filter: 'all', search: '合成學生', rows: [{ id: 7, package_id: null }] }) });
          return 'sent';
        } catch { return 'blocked'; }
      };
      return [await send(), await send()];
    }, BILL_EXPORT_PATH);
    expect(outcomes).toEqual(['blocked', 'blocked']);
    expect(app.received.filter(entry => entry.method === 'POST')).toEqual([{ method: 'POST', path: BILL_EXPORT_PATH }]);
    expect(guard.exportCount()).toBe(0);
    expect(guard.blocked).toHaveLength(2);
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
    expect(blocked).toEqual([{ method: 'PATCH', origin: app.base, path: '/api/v1/schedules/9' }]);
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

  test('opted-in Sentry transaction is fulfilled locally while error telemetry stays blocked', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, appOriginOf(app.base), { allowTransactionTelemetry: true });
    await page.goto(`${app.base}/`);
    const result = await page.evaluate(async () => {
      const send = async (body) => {
        try {
          return (await fetch('https://o123.ingest.us.sentry.io/api/456/envelope/', {
            method: 'POST', body,
          })).status;
        } catch { return 'blocked'; }
      };
      return [
        await send('{}\n{"type":"transaction"}\n{"type":"transaction","transaction":"synthetic"}\n'),
        await send('{}\n{"type":"event"}\n{"exception":{"values":[]}}\n'),
      ];
    });
    expect(result).toEqual([204, 'blocked']);
    expect(blocked.telemetryCounts).toMatchObject({ transaction: 1, error: 1 });
    expect(blocked).toEqual([{ method: 'POST', origin: 'https://o123.ingest.us.sentry.io', path: '/api/456/envelope/' }]);
    expect([app, third, middle].flatMap((server) => server.received)
      .filter((entry) => !['GET', 'HEAD', 'OPTIONS'].includes(entry.method))).toEqual([]);
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
      { method, origin: third.base, path: '/api/4500000/envelope/' }
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
    expect(blocked).toEqual([{ method: 'POST', origin: third.base, path: '/api/v1/adoption/events' }]);
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
      expect(blocked).toEqual([{ method: 'POST', origin: third.base, path: '/redirect' }]);
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

    expect(blocked).toEqual([{ method: 'DELETE', origin: app.base, path: '/api/v1/students/1' }]);
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
