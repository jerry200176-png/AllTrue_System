import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  installFetchRecorder, getRecentApiFailures, resetRecentApiFailuresForTest, fitClientInfo, clearUiBreadcrumbs,
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

  it('keeps the server code and masked message of a refused request, and leaves the body readable', async () => {
    const body = JSON.stringify({ code: 'monthly_amount_stale', message: '11 月應收為 0 元，單號 20261124001' });
    fake.mockResolvedValueOnce(new Response(body, { status: 422, headers: { 'Content-Type': 'application/json' } }));
    const r = await window.fetch('/api/v1/payment-reports/director-record', { method: 'POST' });
    expect((await r.json()).code).toBe('monthly_amount_stale');
    await vi.waitFor(() => expect(getRecentApiFailures()[0].code).toBe('monthly_amount_stale'));
    expect(getRecentApiFailures()[0].message).toBe('11 月應收為 0 元，單號 #');
    fake.mockResolvedValueOnce(new Response(JSON.stringify({ code: 'DUPLICATE_TEACHER_NAME', message: '帳號：someone' }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
    await window.fetch('/api/v1/x');
    await vi.waitFor(() => expect(getRecentApiFailures()[1].code).toBe('DUPLICATE_TEACHER_NAME'));
    expect(getRecentApiFailures()[1].message).toBeUndefined();
    clearUiBreadcrumbs(); // sign-out / campus switch
    expect(getRecentApiFailures()).toEqual([]);
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
