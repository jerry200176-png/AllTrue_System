// @ts-check
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { installReadOnlyAppGuard, isKnownBlockedStatefulRead, isKnownStatefulRead } from './fixtures/readOnlyAppGuard.js';
import { installLocalClassSessionReads } from './fixtures/classSessionReadResponder.js';

/**
 * Browser self-test for the local class-session read answers (#5175). One local server plays
 * the acceptance base URL and records what reaches it; production is never touched.
 */

function startServer() {
  const received = [];
  const server = http.createServer((req, res) => {
    received.push(`${req.method} ${req.url}`);
    if (req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><title>class-session read self-test</title>');
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end('{"from":"server"}');
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = /** @type {import('node:net').AddressInfo} */ (server.address());
      resolve({ server, received, base: `http://127.0.0.1:${port}` });
    });
  });
}

const row = (id, classId, date) => ({ id, student_class_id: classId, session_date: date, start_time: '10:00', status: 'scheduled' });
const PAYLOAD = {
  api_kind: 'projection',
  completeness: 'full',
  total: 2,
  data: [row(1, 11, '2026-08-05'), row(2, 12, '2026-08-06')],
  by_class: { 11: [row(1, 11, '2026-08-05')], 12: [row(2, 12, '2026-08-06')] },
};

test.describe('local class-session read answers self-test', () => {
  test.use({ serviceWorkers: 'block' });

  let app;
  test.beforeAll(async () => { app = await startServer(); });
  test.afterAll(async () => { app?.server.close(); });
  test.beforeEach(() => { app.received.length = 0; });

  const apiRequests = () => app.received.filter((entry) => entry.includes('/api/'));

  test('class-session reads are answered in the browser and never reach the app', async ({ page, context }) => {
    let attempts = 0;
    context.on('request', (request) => {
      if (isKnownStatefulRead(app.base, request.method(), request.url())) attempts += 1;
    });
    const blocked = await installReadOnlyAppGuard(context, app.base);
    const reads = await installLocalClassSessionReads(context, app.base);
    reads.serveProjection({ branchId: 16, start: '2026-08-05', end: '2026-08-07', payload: PAYLOAD });
    await page.goto(`${app.base}/`);

    const seen = await page.evaluate(async () => {
      const read = async (url) => {
        const response = await fetch(url);
        return { status: response.status, body: await response.json() };
      };
      return {
        week: await read('/api/v1/class-sessions/projection?branch_id=16&start=2026-08-03&end=2026-08-09'),
        // The one-day window is the read that makes the real server create class sessions.
        day: await read('/api/v1/class-sessions/projection?branch_id=16&start=2026-08-06&end=2026-08-06'),
        otherCampus: await read('/api/v1/class-sessions/projection?branch_id=2&start=2026-08-03&end=2026-08-09'),
        today: await read('/api/v1/class-sessions?branch_id=16&start=2026-11-10&end=2026-11-10&per_page=500'),
        ordinary: await read('/api/v1/schedules?start=2026-08-05&end=2026-08-07'),
      };
    });

    expect(seen.week.body).toMatchObject({ api_kind: 'projection', completeness: 'full', total: 2 });
    expect(seen.week.body.data.map((item) => item.id)).toEqual([1, 2]);
    expect(seen.day.body.data.map((item) => item.id)).toEqual([2]);
    expect(seen.otherCampus.body.data).toEqual([]);
    expect(seen.today.body).toMatchObject({ data: [], total: 0, per_page: 500 });
    // Other reads are untouched and still come from the app.
    expect(seen.ordinary.body).toEqual({ from: 'server' });
    expect(apiRequests()).toEqual(['GET /api/v1/schedules?start=2026-08-05&end=2026-08-07']);

    expect(blocked).toEqual([]);
    expect(reads.answered.map((read) => `${read.kind} ${read.start}..${read.end} rows=${read.rows}`)).toEqual([
      'projection 2026-08-03..2026-08-09 rows=2',
      'projection 2026-08-06..2026-08-06 rows=1',
      'projection 2026-08-03..2026-08-09 rows=0',
      'list 2026-11-10..2026-11-10 rows=0',
    ]);
    expect(reads.answered.length).toBe(attempts);
  });

  test('without the responder the same reads are blocked by the guard (the #5175 failure)', async ({ page, context }) => {
    const blocked = await installReadOnlyAppGuard(context, app.base);
    await page.goto(`${app.base}/`);
    await page.evaluate(async () => {
      await fetch('/api/v1/class-sessions?branch_id=16&start=2026-11-10&end=2026-11-10').catch(() => null);
      await fetch('/api/v1/class-sessions/projection?branch_id=16&start=2026-08-03&end=2026-08-09').catch(() => null);
    });
    expect(blocked.map((request) => `${request.method} ${request.path}`)).toEqual([
      'GET /api/v1/class-sessions',
      'GET /api/v1/class-sessions/projection',
    ]);
    expect(apiRequests()).toEqual([]);
  });

  test('writes and odd spellings still fall back to the guard and are blocked', async ({ page, context }) => {
    let attempts = 0;
    context.on('request', (request) => {
      if (isKnownStatefulRead(app.base, request.method(), request.url())) attempts += 1;
    });
    const blocked = await installReadOnlyAppGuard(context, app.base);
    const reads = await installLocalClassSessionReads(context, app.base);
    await page.goto(`${app.base}/`);
    await page.evaluate(async () => {
      await fetch('/api/v1/class-sessions', { method: 'POST', body: '{}' }).catch(() => null);
      await fetch('/api/v1/class-sessions/batch', { method: 'POST', body: '{}' }).catch(() => null);
      await fetch('/api/v1/class-sessions/9', { method: 'PATCH', body: '{}' }).catch(() => null);
      await fetch('/api/v1/class-sessions/%70rojection?branch_id=16').catch(() => null);
      await fetch('/api/v1/class-sessions/?branch_id=16').catch(() => null);
    });
    expect(blocked.map((request) => `${request.method} ${request.path}`)).toEqual([
      'POST /api/v1/class-sessions',
      'POST /api/v1/class-sessions/batch',
      'PATCH /api/v1/class-sessions/9',
      'GET /api/v1/class-sessions/%70rojection',
      'GET /api/v1/class-sessions/',
    ]);
    expect(reads.answered).toEqual([]);
    expect(apiRequests()).toEqual([]);
    // The acceptance spec's receipt check: answered + blocked reads equal the reads the browser sent.
    expect(blocked.filter((request) => isKnownBlockedStatefulRead(app.base, request)).length).toBe(attempts);
    expect(attempts).toBe(2);
  });
});
