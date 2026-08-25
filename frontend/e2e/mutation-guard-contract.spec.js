// @ts-check
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { installProductionMutationGuard } from './support/productionMutationGuard.js';

function startMockServer() {
  const requests = [];
  const server = http.createServer((request, response) => {
    requests.push({ method: request.method, pathname: request.url });
    response.writeHead(200, { 'content-type': 'text/plain' });
    response.end('ok');
  });

  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolve({
        origin: `http://127.0.0.1:${address.port}`,
        requests,
        close: () => new Promise((done) => server.close(() => done())),
      });
    });
  });
}

test.describe('production mutation guard contract — local mock only', () => {
  test('allows safe methods and login POST, blocks post-login POST/PATCH/DELETE', async ({ page }) => {
    const mock = await startMockServer();
    const guard = await installProductionMutationGuard(page, {
      baseURL: mock.origin,
      loginPaths: ['/api/v1/auth/login'],
      expectedBlockedSideEffects: [
        { method: 'POST', pathname: '/api/v1/adoption/events' },
      ],
    });

    try {
      await page.goto(`${mock.origin}/`);
      await page.evaluate(async (origin) => {
        await fetch(`${origin}/api/v1/read`, { method: 'GET' });
        await fetch(`${origin}/api/v1/options`, { method: 'OPTIONS' });
        await fetch(`${origin}/api/v1/head`, { method: 'HEAD' });
        await fetch(`${origin}/api/v1/auth/login`, { method: 'POST' });
      }, mock.origin);

      expect(guard.phase()).toBe('login');
      expect(guard.allowedNonGetExceptions()).toEqual([
        { method: 'POST', pathname: '/api/v1/auth/login' },
      ]);
      expect(guard.blockedRequests()).toEqual([]);

      guard.markAuthenticated();
      await page.evaluate(async (origin) => {
        await fetch(`${origin}/api/v1/adoption/events`, { method: 'POST' }).catch(() => {});
        await fetch(`${origin}/api/v1/payment-reports`, { method: 'POST' }).catch(() => {});
        await fetch(`${origin}/api/v1/should-block`, { method: 'PATCH' }).catch(() => {});
        await fetch(`${origin}/api/v1/should-block`, { method: 'DELETE' }).catch(() => {});
      }, mock.origin);

      expect(guard.expectedBlockedSideEffects()).toEqual([
        { method: 'POST', pathname: '/api/v1/adoption/events' },
      ]);
      expect(guard.unexpectedMutations()).toEqual([
        { method: 'POST', pathname: '/api/v1/payment-reports' },
        { method: 'PATCH', pathname: '/api/v1/should-block' },
        { method: 'DELETE', pathname: '/api/v1/should-block' },
      ]);
      expect(() => guard.assertNoUnexpectedMutations()).toThrow(
        'POST /api/v1/payment-reports, PATCH /api/v1/should-block, DELETE /api/v1/should-block',
      );

      // The blocked requests never reach the local server; only safe methods
      // and the one narrowly allowed login POST are observed by the server.
      expect(mock.requests.map(({ method, pathname }) => `${method} ${pathname}`)).toEqual([
        'GET /',
        'GET /api/v1/read',
        'OPTIONS /api/v1/options',
        'HEAD /api/v1/head',
        'POST /api/v1/auth/login',
      ]);
    } finally {
      await guard.dispose();
      await mock.close();
    }
  });
});
