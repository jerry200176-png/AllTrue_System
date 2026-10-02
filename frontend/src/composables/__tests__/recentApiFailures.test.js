import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  installFetchRecorder, getRecentApiFailures, resetRecentApiFailuresForTest, fitClientInfo,
} from '../../lib/recentApiFailures';

const res = (status, id) => ({ ok: status < 400, status, headers: new Headers(id ? { 'X-Request-Id': id } : {}) });

describe('recentApiFailures', () => {
  const fake = vi.fn();
  beforeEach(() => { resetRecentApiFailuresForTest(); fake.mockReset(); });
  window.fetch = fake;
  installFetchRecorder();
  const wrapped = window.fetch;

  it('is idempotent', () => {
    installFetchRecorder();
    expect(window.fetch).toBe(wrapped);
  });

  it('records only non-ok /api calls, strips query, keeps last 5', async () => {
    fake.mockResolvedValueOnce(res(200)).mockResolvedValueOnce(res(500, 'req-abcdef12'))
      .mockResolvedValueOnce(res(404));
    await window.fetch('/api/v1/ok?x=1');
    await window.fetch('/api/v1/students?name=secret', { method: 'post' });
    await window.fetch('/assets/missing.js');
    expect(getRecentApiFailures()).toMatchObject([
      { method: 'POST', path: '/api/v1/students', status: 500, requestId: 'req-abcdef12' },
    ]);
    fake.mockResolvedValue(res(500));
    for (let i = 0; i < 7; i++) await window.fetch(`/api/v1/n${i}`);
    const list = getRecentApiFailures();
    expect(list).toHaveLength(5);
    expect(list[4].path).toBe('/api/v1/n6');
  });

  it('records network errors and rethrows', async () => {
    fake.mockRejectedValueOnce(new Error('offline'));
    await expect(window.fetch('/api/v1/x')).rejects.toThrow('offline');
    expect(getRecentApiFailures()[0].status).toBe(0);
  });

  it('fitClientInfo stays valid JSON under the limit with oversized input', () => {
    const big = Array.from({ length: 5 }, (_, i) => ({ path: `/api/${'p'.repeat(900)}${i}` }));
    const s = fitClientInfo({ a: 1, relatedReference: 'r'.repeat(5000), recentApiFailures: big });
    expect(s.length).toBeLessThanOrEqual(3800);
    const o = JSON.parse(s);
    expect(o.relatedReference).toBeNull();
    expect(o.recentApiFailures.length).toBeLessThan(5);
  });
});
