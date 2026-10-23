// @vitest-environment node
import { describe, it, expect } from 'vitest';
import {
  appOriginOf,
  isAppOriginUrl,
  classifyAppRequest,
  installReadOnlyAppGuard,
  isKnownPostRead,
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
});
