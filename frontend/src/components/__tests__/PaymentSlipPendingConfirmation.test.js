// Founder D2 (#4121 review): a parent slip never carries an amount that is still 金額待確認.
// The API answers 422; every slip entry shows that reason instead of a slip.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionDocActions from '../TuitionDocActions.vue';
import PaymentSlipModal from '../PaymentSlipModal.vue';

vi.mock('modern-screenshot', () => ({ domToBlob: vi.fn() }));

const MSG = '金額待確認，請先確認應收金額';
const refused = () => ({ ok: false, status: 422, json: async () => ({ message: MSG }) });

describe('payment slip on 金額待確認 contracts', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  it('the queue slip button is disabled with the 金額待確認 reason', () => {
    const w = mount(TuitionDocActions, { props: { row: { id: 1, payable_status: 'invoiced', payment_status: 'pending_reconciliation', amount_pending_confirmation: true }, canView: true } });
    expect(w.get('[data-testid="tc-doc-slip"]').element.disabled).toBe(true);
    expect(w.get('[data-testid="tc-doc-slip-reason"]').text()).toBe(MSG);
  });

  it('course slip (course page, dashboard) shows the API 422 reason and renders no slip', async () => {
    global.fetch.mockResolvedValueOnce(refused());
    const w = mount(PaymentSlipModal, { props: { show: true, studentClassId: 9 } });
    await flushPromises();
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/alerts/tuition-slip/9');
    expect(w.find('.slip-error').text()).toContain(MSG);
    expect(w.text()).not.toContain('尚欠金額');
  });

  it('bill slip flagged amount_pending_confirmation renders no slip, no amount and no share actions', async () => {
    global.fetch.mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
      invoice_id: 1623, student_name: '王小明', status: 'unpaid', amount_pending_confirmation: true,
      total_amount: null, paid_amount: null, remaining: null, items: [{ description: '數學月結費用', amount: null }], sessions: [],
    }) });
    const w = mount(PaymentSlipModal, { props: { show: true, invoiceId: 1623 } });
    await flushPromises();
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/invoices/1623/slip-data');
    expect(w.find('.slip-error').text()).toContain(MSG);
    expect(w.text()).not.toContain('應繳金額');
    expect(w.find('.slip-preview-wrap').exists()).toBe(false);
    expect(w.findAll('button').map((b) => b.text()).join(' ')).not.toMatch(/下載|分享|複製/);
    expect(w.find('.slip-actions').exists()).toBe(false);
  });

  it('a bill slip without the flag (e.g. a paid bill on the same course) still renders its amount', async () => {
    global.fetch.mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
      invoice_id: 1700, student_name: '王小明', status: 'unpaid', amount_pending_confirmation: false,
      total_amount: 1200, paid_amount: 0, remaining: 1200, items: [{ description: '數學', amount: 1200 }], sessions: [],
    }) });
    const w = mount(PaymentSlipModal, { props: { show: true, invoiceId: 1700 } });
    await flushPromises();
    expect(w.find('.slip-error').exists()).toBe(false);
    expect(w.text()).toContain('NT$ 1,200');
  });
});
