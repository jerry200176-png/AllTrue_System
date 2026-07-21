import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReceiptModal from '../ReceiptModal.vue';
import {
  adaptPaymentReportReceipt,
  paymentReportReceiptUrl,
} from '../../lib/paymentReportReceipt.js';

const mockToken = 'test-token';

const SAMPLE_RECEIPT_API = {
  receipt_no: 'R-000123',
  student_name: '王小明',
  campus_name: '大安分校',
  subject: '英文',
  session_count: 8,
  period_start: '2026/07/01',
  period_end: '2026/12/31',
  attended_dates: ['2026/07/03'],
  session_dates: [
    { date: '2026/07/03', expected: false },
    { date: '2026/07/10', expected: true },
  ],
  payment_date: '2026/07/13',
  payment_method: 'cash',
  amount: 12000,
  confirmed_at: '2026/07/13',
  confirmed_by: '主任A',
  class_type: 'one_on_one',
  schedule_mode: 'count',
  is_backfilled: false,
  backfill_note: null,
};

function mockOk(json) {
  return {
    ok: true,
    status: 200,
    json: () => Promise.resolve(json),
  };
}

function mockFail(status, message) {
  return {
    ok: false,
    status,
    json: () => Promise.resolve(message ? { message } : {}),
  };
}

async function flush() {
  await new Promise((r) => setTimeout(r, 30));
  await nextTick();
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('paymentReportReceipt adapter (#1197 404 hotfix)', () => {
  it('builds canonical payment-reports receipt URL', () => {
    expect(paymentReportReceiptUrl(123)).toBe('/api/v1/payment-reports/123/receipt');
    expect(paymentReportReceiptUrl(123)).not.toMatch(/\/api\/v1\/receipts/);
  });

  it('maps only real PaymentReport receipt fields', () => {
    const view = adaptPaymentReportReceipt(SAMPLE_RECEIPT_API, 123);
    expect(view.receipt_number).toBe('R-000123');
    expect(view.content_snapshot.student_name).toBe('王小明');
    expect(view.content_snapshot.campus_name).toBe('大安分校');
    expect(view.content_snapshot.total_amount).toBe(12000);
    expect(view.content_snapshot.paid_at).toBe('2026/07/13');
    expect(view.content_snapshot.method).toBe('cash');
    expect(view.content_snapshot.school_name).toContain('大安分校');
    expect(view.content_snapshot.items[0].description).toContain('英文');
    // Must not invent legal domain fields
    expect(view.content_snapshot.license_number).toBeUndefined();
    expect(view.content_snapshot.refund_policy).toBeUndefined();
    expect(view.content_snapshot.address).toBeUndefined();
  });

  it('tolerates null/missing optional fields without throwing', () => {
    expect(() => adaptPaymentReportReceipt({}, 1)).not.toThrow();
    expect(() => adaptPaymentReportReceipt(null, 1)).not.toThrow();
    const view = adaptPaymentReportReceipt({ receipt_no: 'R-1' }, 1);
    expect(view.receipt_number).toBe('R-1');
    expect(view.content_snapshot.student_name).toBe('—');
    expect(view.content_snapshot.study_period).toBeNull();
  });
});

describe('ReceiptModal.vue — payment-reports contract', () => {
  it('renders nothing when show is false', () => {
    const wrapper = mount(ReceiptModal, { props: { show: false, reportId: 1 } });
    expect(wrapper.find('.modal-overlay').exists()).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('endpoint contract: only calls GET /api/v1/payment-reports/{id}/receipt', async () => {
    global.fetch.mockResolvedValueOnce(mockOk(SAMPLE_RECEIPT_API));
    mount(ReceiptModal, { props: { show: true, reportId: 456 } });
    await flush();

    expect(global.fetch).toHaveBeenCalledTimes(1);
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe('/api/v1/payment-reports/456/receipt');
    expect(url).not.toMatch(/\/api\/v1\/receipts/);
    expect(opts.headers.Authorization).toBe(`Bearer ${mockToken}`);
  });

  it('never POSTs or GETs /api/v1/receipts* even when report is missing', async () => {
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: null } });
    await flush();
    expect(wrapper.find('.receipt-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('缺少核帳紀錄編號');
    const urls = global.fetch.mock.calls.map((c) => String(c[0]));
    expect(urls.every((u) => !u.includes('/api/v1/receipts'))).toBe(true);
  });

  it('success rendering shows receipt no, student, campus, amount, date, method', async () => {
    global.fetch.mockResolvedValueOnce(mockOk(SAMPLE_RECEIPT_API));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await flush();

    expect(wrapper.find('.receipt-document').exists()).toBe(true);
    expect(wrapper.find('.receipt-doc-title').text()).toBe('正式收據');
    const text = wrapper.text();
    expect(text).toContain('R-000123');
    expect(text).toContain('王小明');
    expect(text).toContain('大安分校');
    expect(text).toContain('NT$ 12,000');
    expect(text).toContain('2026/07/13');
    expect(text).toContain('現金');
    expect(text).toContain('英文');
  });

  it('shows classified error for 403', async () => {
    global.fetch.mockResolvedValueOnce(mockFail(403, 'Forbidden'));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await flush();
    expect(wrapper.find('.receipt-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('Forbidden');
  });

  it('shows classified error for 404 without message body', async () => {
    global.fetch.mockResolvedValueOnce(mockFail(404));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 999 } });
    await flush();
    expect(wrapper.text()).toContain('找不到這筆核帳紀錄');
    expect(wrapper.text()).not.toContain('請求失敗（404）');
  });

  it('shows classified error for 422', async () => {
    global.fetch.mockResolvedValueOnce(mockFail(422, '尚未核帳確認，無法產生收據'));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await flush();
    expect(wrapper.text()).toContain('尚未核帳確認');
  });

  it('shows classified error for 500', async () => {
    global.fetch.mockResolvedValueOnce(mockFail(500, '伺服器錯誤'));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await flush();
    expect(wrapper.text()).toContain('伺服器錯誤');
  });

  it('shows network failure message', async () => {
    global.fetch.mockRejectedValueOnce(new Error('Failed to fetch'));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await flush();
    expect(wrapper.find('.receipt-error').exists()).toBe(true);
    expect(wrapper.text()).toContain('Failed to fetch');
  });

  it('does not expose void / PDF / legal-setup actions (no orphan backend)', async () => {
    global.fetch.mockResolvedValueOnce(mockOk(SAMPLE_RECEIPT_API));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await flush();
    expect(wrapper.find('.receipt-btn-void').exists()).toBe(false);
    expect(wrapper.find('.receipt-legal-setup').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('下載 PDF');
    expect(wrapper.text()).not.toContain('作廢收據');
  });

  it('emits close on close button', async () => {
    global.fetch.mockResolvedValueOnce(mockOk(SAMPLE_RECEIPT_API));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await flush();
    await wrapper.find('.icon-btn').trigger('click');
    expect(wrapper.emitted('close')).toBeTruthy();
  });

  it('entry-path: uses reportId prop as payment report id, not receiptId/paymentId', async () => {
    global.fetch.mockResolvedValueOnce(mockOk(SAMPLE_RECEIPT_API));
    // Intentionally omit non-existent props — only reportId is supported
    const wrapper = mount(ReceiptModal, {
      props: { show: true, reportId: 777, receiptId: 888, paymentId: 999 },
    });
    await flush();
    const [url] = global.fetch.mock.calls[0];
    expect(url).toBe('/api/v1/payment-reports/777/receipt');
    expect(url).not.toContain('888');
    expect(url).not.toContain('999');
    expect(wrapper.find('.receipt-document').exists()).toBe(true);
  });
});
