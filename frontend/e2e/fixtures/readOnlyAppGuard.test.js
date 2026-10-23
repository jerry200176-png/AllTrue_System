// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  appOriginOf,
  isAppOriginUrl,
  classifyAppRequest,
  installReadOnlyAppGuard,
  isKnownPostRead,
  describeBlockedWrite,
  blockedWriteAnnotations,
} from './readOnlyAppGuard.js';

const APP = appOriginOf('https://app.example.test/some/path?x=1');

describe('readOnlyAppGuard', () => {
  it('derives the app origin only from http(s) base URLs', () => {
    expect(APP).toBe('https://app.example.test');
    expect(appOriginOf('https://APP.example.test:443/')).toBe('https://app.example.test');
    expect(appOriginOf('')).toBe('');
    expect(appOriginOf(undefined)).toBe('');
    expect(appOriginOf('not a url')).toBe('');
    expect(appOriginOf('about:blank')).toBe('');
  });

  it('matches the exact app origin only', () => {
    expect(isAppOriginUrl(APP, 'https://app.example.test/api/v1/x')).toBe(true);
    expect(isAppOriginUrl(APP, new URL('https://app.example.test/login'))).toBe(true);
    expect(isAppOriginUrl(APP, 'http://app.example.test/api/v1/x')).toBe(false);
    expect(isAppOriginUrl(APP, 'https://app.example.test:8443/api/v1/x')).toBe(false);
    expect(isAppOriginUrl(APP, 'https://evil.app.example.test/api/v1/x')).toBe(false);
    expect(isAppOriginUrl(APP, 'https://app.example.test.evil.com/api/v1/x')).toBe(false);
    expect(isAppOriginUrl('', 'https://app.example.test/api/v1/x')).toBe(false);
  });

  it('(a) blocks writes to the app origin, API or not', () => {
    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'post']) {
      expect(classifyAppRequest(APP, method, 'https://app.example.test/api/v1/schedules/1')).toBe('block');
    }
    expect(classifyAppRequest(APP, 'POST', 'https://app.example.test/login')).toBe('block');
    expect(classifyAppRequest(APP, 'PUT', 'https://app.example.test/api/v1/adoption/events')).toBe('block');
  });

  it('allows reads and stubs adoption telemetry on the app origin', () => {
    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
      expect(classifyAppRequest(APP, method, 'https://app.example.test/api/v1/schedules')).toBe('allow');
    }
    expect(classifyAppRequest(APP, 'POST', 'https://app.example.test/api/v1/adoption/events')).toBe('stub-telemetry');
  });

  it('(b) blocks Sentry and other third-party HTTP writes before redirects', () => {
    expect(classifyAppRequest(APP, 'POST', 'https://o123.ingest.sentry.io/api/456/envelope/')).toBe('block');
    expect(classifyAppRequest(APP, 'POST', 'https://o123.ingest.us.sentry.io/api/456/envelope/')).toBe('block');
    expect(classifyAppRequest(APP, 'POST', 'https://www.google-analytics.com/g/collect')).toBe('block');
    expect(classifyAppRequest(APP, 'DELETE', 'https://cdn.example.com/api/v1/x')).toBe('block');
    expect(classifyAppRequest(APP, 'POST', 'https://other.example.test/api/v1/adoption/events')).toBe('block');
    expect(classifyAppRequest('', 'POST', 'https://other.example.test/api/v1/x')).toBe('block');
    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
      expect(classifyAppRequest(APP, method, 'https://other.example.test/read')).toBe('allow');
    }
    expect(classifyAppRequest(APP, 'POST', 'data:text/plain,synthetic')).toBe('ignore');
    expect(classifyAppRequest(APP, 'POST', 'not a url')).toBe('ignore');
  });

  it('knows a POST read only by exact method, app origin and path', () => {
    const read = { method: 'POST', origin: APP, path: '/api/v1/student-classes/session-dates' };
    expect(isKnownPostRead(APP, read)).toBe(true);
    expect(isKnownPostRead(APP, { ...read, method: 'PUT' })).toBe(false);
    expect(isKnownPostRead(APP, { ...read, origin: 'https://other.example.test' })).toBe(false);
    expect(isKnownPostRead(APP, { ...read, path: '/api/v1/student-classes/session-dates/1' })).toBe(false);
    expect(isKnownPostRead('', read)).toBe(false);
  });

  it('records blocked HTTP writes from every origin', async () => {
    let predicate;
    let handler;
    const context = { route: async (p, h) => { predicate = p; handler = h; } };
    const blocked = await installReadOnlyAppGuard(context, APP);

    expect(predicate(new URL('https://o123.ingest.sentry.io/api/456/envelope/'))).toBe(true);
    expect(predicate(new URL('https://app.example.test/api/v1/schedules'))).toBe(true);
    expect(predicate(new URL('data:text/plain,synthetic'))).toBe(false);

    const calls = [];
    const route = (method, url) => ({
      request: () => ({ method: () => method, url: () => url }),
      abort: async (reason) => calls.push(['abort', reason]),
      fulfill: async (opts) => calls.push(['fulfill', opts.status]),
      continue: async () => calls.push(['continue']),
    });
    await handler(route('GET', 'https://app.example.test/api/v1/schedules'));
    await handler(route('POST', 'https://app.example.test/api/v1/adoption/events'));
    await handler(route('PATCH', 'https://app.example.test/api/v1/schedules/9'));
    await handler(route('POST', 'https://app.example.test/api/v1/student-classes/session-dates?branch_id=1'));

    expect(calls).toEqual([['continue'], ['fulfill', 204], ['abort', 'blockedbyclient'], ['abort', 'blockedbyclient']]);
    expect(blocked).toEqual([
      { method: 'PATCH', origin: APP, path: '/api/v1/schedules/9' },
      { method: 'POST', origin: APP, path: '/api/v1/student-classes/session-dates' },
    ]);
    // A known POST read is still aborted; only a spec that opts in may count it.
    expect(blocked.map((entry) => isKnownPostRead(APP, entry))).toEqual([false, true]);
    // This is the afterEach assertion the acceptance spec uses; a write fails it.
    expect(() => expect(blocked).toEqual([])).toThrow();
  });

  it('names a blocked write as METHOD host /path without ids, query or text', () => {
    const write = (method, origin, pathname) => ({ method, origin, path: pathname });
    expect(describeBlockedWrite(write('POST', APP, '/api/v1/student-classes/123/payments')))
      .toBe('POST app.example.test /api/v1/student-classes/:id/payments');
    expect(describeBlockedWrite(write('PATCH', 'https://o123.ingest.sentry.io', '/api/456/envelope/')))
      .toBe('PATCH o123.ingest.sentry.io /api/:id/envelope');
    expect(describeBlockedWrite(write('POST', 'https://app.example.test:8443', '/')))
      .toBe('POST app.example.test:8443 /');
    // Query and fragment never survive, even if a caller passes a path that still carries them.
    expect(describeBlockedWrite(write('POST', APP, '/api/v1/x?token=abc#frag'))).toBe('POST app.example.test /api/v1/x');
    // UUIDs, hashes, capitals, digits-in-words, percent-encoded text and over-long words are not route words.
    for (const segment of ['3f2a9c1e-5b7d-4e21-9a0c-1d2e3f4a5b6c', 'a1b2c3', 'Wang', '%E7%8E%8B%E5%B0%8F%E6%98%8E', '王小明', 'a'.repeat(33)]) {
      expect(describeBlockedWrite(write('POST', APP, `/api/v1/students/${segment}`)), segment)
        .toBe('POST app.example.test /api/v1/students/:id');
    }
    // A very deep path is cut at 12 segments.
    expect(describeBlockedWrite(write('POST', APP, `/${Array(20).fill('a').join('/')}`)).split(' ')[2]).toBe(`/${Array(12).fill('a').join('/')}`);
    expect(describeBlockedWrite(write('post it', 'not a url', ''))).toBe('UNKNOWN unknown-host /');
  });

  it('annotates each distinct blocked write once, counting repeats', () => {
    const poll = { method: 'POST', origin: APP, path: '/api/v1/presence/12' };
    const annotations = blockedWriteAnnotations([
      poll, { ...poll, path: '/api/v1/presence/13' }, { method: 'PUT', origin: APP, path: '/api/v1/schedules/9' },
    ]);
    expect(annotations).toEqual([
      { type: 'blocked-write', description: 'POST app.example.test /api/v1/presence/:id x2' },
      { type: 'blocked-write', description: 'PUT app.example.test /api/v1/schedules/:id' },
    ]);
    expect(blockedWriteAnnotations([])).toEqual([]);
    // The log stays short: at most ten distinct writes are annotated.
    const many = Array.from({ length: 30 }, (_, index) => ({ method: 'POST', origin: APP, path: `/api/${'abcdefghijklmnopqrstuvwxyz'.repeat(2).slice(index, index + 3)}` }));
    expect(blockedWriteAnnotations(many)).toHaveLength(10);
  });

  it('is printed by the acceptance gate (the gate pattern accepts what the guard emits)', () => {
    const gate = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../.github/scripts/acceptance-report-gate.mjs');
    const writes = [
      { method: 'POST', origin: 'https://daan.example.test', path: '/api/v1/student-classes/12/payments' },
      { method: 'POST', origin: 'https://o1.ingest.sentry.io', path: '/api/456/envelope/' },
      { method: 'DELETE', origin: 'https://daan.example.test', path: '/cdn-cgi/rum' },
      { method: 'POST', origin: 'https://daan.example.test', path: '/api/v1/students/王小明' },
    ];
    const annotations = blockedWriteAnnotations([...writes, writes[1]]);
    const report = { suites: [{ specs: [{
      file: 'calendar-course-consistency.spec.js', line: 1, column: 1, title: 'synthetic',
      tests: [{ projectName: 'chromium', status: 'flaky', annotations: [], results: [
        { status: 'failed', errors: [{ location: { file: '/w/e2e/calendar-course-consistency.spec.js', line: 351 } }], annotations },
        { status: 'passed' },
      ] }],
    }], suites: [] }] };
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'acceptance-gate-'));
    try {
      const reportPath = path.join(dir, 'report.json');
      fs.writeFileSync(reportPath, JSON.stringify(report));
      // The synthetic report is not the expected collection, so the gate exits non-zero after printing its summary.
      const run = spawnSync(process.execPath, [gate], { env: { ...process.env, RESULTS_PATH: reportPath, EFFECTIVE_BRANCH_ID: '16' }, encoding: 'utf8' });
      for (const line of [
        '    blocked-write attempt=1 POST daan.example.test /api/v1/student-classes/:id/payments',
        '    blocked-write attempt=1 POST o1.ingest.sentry.io /api/:id/envelope x2',
        '    blocked-write attempt=1 DELETE daan.example.test /cdn-cgi/rum',
        '    blocked-write attempt=1 POST daan.example.test /api/v1/students/:id',
      ]) expect(run.stdout).toContain(line);
      expect(run.stdout).not.toContain('withheld');
      expect(run.stdout).not.toContain('王小明');
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });
});
