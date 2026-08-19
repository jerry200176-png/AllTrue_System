import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReceiptModal from '../ReceiptModal.vue';
import {
  adaptPaymentReportReceipt,
  paymentReportReceiptUrl,
  parsePositiveReportId,
} from '../../lib/paymentReportReceipt.js';

const mockToken = 'test-token';

const SAMPLE = {
  receipt_no: 'R-000123',
  student_name: '王小明',
  campus_name: '大安分校',
  subject: '英文',
  session_count: 8,
  period_start: '2026/07/01',
  period_end: '2026/12/31',
  attended_dates: ['2026/07/03'],
  session_dates: [{ date: '2026/07/03', expected: false }],
  payment_date: '2026/07/13',
  payment_method: 'cash',
  amount: 12000,
  confirmed_at: '2026/07/13',
  confirmed_by: '主任A',
  schedule_mode: 'count',
  is_backfilled: false,
};

function ok(json) {
  return { ok: true, status: 200, json: () => Promise.resolve(json) };
}
function fail(status, message) {
  return { ok: false, status, json: () => Promise.resolve(message ? { message } : {}) };
}
async function tick() {
  await new Promise((r) => setTimeout(r, 30));
  await nextTick();
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('paymentReportReceipt helpers (#1197 closeout)', () => {
  it('parsePositiveReportId rejects null/empty/NaN/non-positive', () => {
    expect(parsePositiveReportId(null)).toBeNull();
    expect(parsePositiveReportId(undefined)).toBeNull();
    expect(parsePositiveReportId('')).toBeNull();
    expect(parsePositiveReportId('abc')).toBeNull();
    expect(parsePositiveReportId(NaN)).toBeNull();
    expect(parsePositiveReportId(0)).toBeNull();
    expect(parsePositiveReportId(-3)).toBeNull();
    expect(parsePositiveReportId(1.5)).toBeNull();
    expect(parsePositiveReportId(777)).toBe(777);
    expect(parsePositiveReportId('42')).toBe(42);
  });

  it('URL helper never builds /receipts or NaN path', () => {
    expect(paymentReportReceiptUrl(123)).toBe('/api/v1/payment-reports/123/receipt');
    expect(() => paymentReportReceiptUrl(null)).toThrow(/invalid_report_id/);
    expect(() => paymentReportReceiptUrl('x')).toThrow(/invalid_report_id/);
    expect(() => paymentReportReceiptUrl(NaN)).toThrow(/invalid_report_id/);
  });

  it('adapter maps real fields only', () => {
    const view = adaptPaymentReportReceipt(SAMPLE, 123);
    expect(view.receipt_number).toBe('R-000123');
    expect(view.content_snapshot.student_name).toBe('王小明');
    expect(view.content_snapshot.campus_name).toBe('大安分校');
    expect(view.content_snapshot.total_amount).toBe(12000);
    expect(view.content_snapshot.license_number).toBeUndefined();
    expect(view.content_snapshot.items[0].description).toBe('英文 · 8 堂 · 堂數制');
  });

  it('receipt line names trial and tutoring', () => {
    const trial = adaptPaymentReportReceipt({ ...SAMPLE, class_type: 'trial', session_count: 1, amount: 0 }, 1);
    expect(trial.content_snapshot.items[0].description).toContain('試聽');
    const tutor = adaptPaymentReportReceipt({ ...SAMPLE, class_type: 'tutoring', amount: 0 }, 2);
    expect(tutor.content_snapshot.items[0].description).toContain('輔導');
  });
});

describe('ReceiptModal payment-reports contract', () => {
  it('renders nothing when show is false', () => {
    mount(ReceiptModal, { props: { show: false, reportId: 1 } });
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('loads only GET /api/v1/payment-reports/{id}/receipt', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    mount(ReceiptModal, { props: { show: true, reportId: 456 } });
    await tick();
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/payment-reports/456/receipt');
  });

  it('invalid reportId fail-fast: no fetch, no NaN URL', async () => {
    for (const bad of [null, undefined, 0, -1, NaN]) {
      global.fetch.mockClear();
      const wrapper = mount(ReceiptModal, { props: { show: true, reportId: bad } });
      await tick();
      expect(global.fetch).not.toHaveBeenCalled();
      expect(wrapper.text()).toContain('缺少核帳紀錄編號');
      expect(wrapper.text()).not.toContain('NaN');
      wrapper.unmount();
    }
  });

  it('success rendering shows key fields and 電子收據 title', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    const text = wrapper.text();
    expect(wrapper.find('.receipt-doc-title').text()).toBe('電子收據');
    expect(text).toContain('R-000123');
    expect(text).toContain('王小明');
    expect(text).toContain('大安分校');
    expect(text).toContain('NT$ 12,000');
    expect(text).toContain('2026/07/13');
    expect(text).toContain('現金');
  });

  it('classifies 403/404/422 without generic 請求失敗', async () => {
    global.fetch.mockResolvedValueOnce(fail(404));
    let w = mount(ReceiptModal, { props: { show: true, reportId: 9 } });
    await tick();
    expect(w.text()).toContain('找不到這筆核帳紀錄');
    expect(w.text()).not.toContain('請求失敗（404）');
    w.unmount();

    global.fetch.mockResolvedValueOnce(fail(403, 'Forbidden'));
    w = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await tick();
    expect(w.text()).toContain('Forbidden');
    w.unmount();

    global.fetch.mockResolvedValueOnce(fail(422, '尚未核帳確認，無法產生收據'));
    w = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await tick();
    expect(w.text()).toContain('尚未核帳確認');
  });

  it('uses reportId even if extraneous receiptId/paymentId props present', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    mount(ReceiptModal, { props: { show: true, reportId: 777, receiptId: 888, paymentId: 999 } });
    await tick();
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/payment-reports/777/receipt');
  });

  it('switching reportId clears previous receipt before loading next', async () => {
    const second = { ...SAMPLE, receipt_no: 'R-000999', student_name: '另一位' };
    global.fetch
      .mockResolvedValueOnce(ok(SAMPLE))
      .mockResolvedValueOnce(ok(second));

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    expect(wrapper.text()).toContain('王小明');
    expect(wrapper.text()).toContain('R-000123');

    await wrapper.setProps({ reportId: 999 });
    await tick();
    expect(wrapper.text()).toContain('另一位');
    expect(wrapper.text()).toContain('R-000999');
    expect(wrapper.text()).not.toContain('王小明');
    expect(global.fetch.mock.calls.map((c) => c[0])).toEqual([
      '/api/v1/payment-reports/123/receipt',
      '/api/v1/payment-reports/999/receipt',
    ]);
  });
});
