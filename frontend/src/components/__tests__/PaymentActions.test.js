// Architecture review #2: every billing money write goes through one module.
import { describe, it, expect, vi, beforeEach } from 'vitest';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a) }));
import * as pa from '../../lib/paymentActions.js';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
beforeEach(() => authedFetch.mockReset());

describe('paymentActions', () => {
  it.each([
    ['confirmReport', [7], '/api/v1/payment-reports/7/confirm', 'PUT', {}],
    ['rejectReport', [7, '金額不對'], '/api/v1/payment-reports/7/reject', 'PUT', { rejection_note: '金額不對' }],
    ['voidReport', [7, '重複'], '/api/v1/payment-reports/7/void', 'PUT', { void_reason: '重複' }],
    ['voidInvoice', [9, '開錯了'], '/api/v1/invoices/9/void', 'POST', { reason: '開錯了' }],
    ['recordPayment', [{ amount: 1 }], '/api/v1/payment-reports/director-record', 'POST', { amount: 1 }],
  ])('%s calls the right endpoint', async (fn, args, url, method, body) => {
    authedFetch.mockResolvedValueOnce(respond({ ok: 1 }));
    const res = await pa[fn](...args);
    expect(res).toMatchObject({ ok: true, data: { ok: 1 } });
    expect(authedFetch.mock.calls[0][0]).toBe(url);
    expect(authedFetch.mock.calls[0][1].method).toBe(method);
    expect(JSON.parse(authedFetch.mock.calls[0][1].body)).toEqual(body);
  });

  it('exception void uses the exception endpoint', async () => {
    authedFetch.mockResolvedValueOnce(respond({}));
    await pa.voidInvoice(9, '更正', { exception: true });
    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/invoices/9/exception-void');
  });

  it('returns code + a human message on failure and never throws', async () => {
    authedFetch.mockResolvedValueOnce(respond({ code: 'pending_report_exists', message: '已有待對帳' }, 422));
    const res = await pa.recordPayment({});
    expect(res.ok).toBe(false);
    expect(res.code).toBe('pending_report_exists');
    expect(res.message).toBeTruthy();
    authedFetch.mockRejectedValueOnce(new Error('Failed to fetch'));
    expect((await pa.confirmReport(1)).code).toBe('network');
  });
});
