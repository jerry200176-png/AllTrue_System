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

  it.each([
    ['course slip (course page, dashboard)', { studentClassId: 9 }, '/api/v1/alerts/tuition-slip/9'],
    ['bill slip', { invoiceId: 1623 }, '/api/v1/invoices/1623/slip-data'],
  ])('%s shows the API 422 reason and renders no slip', async (_, props, url) => {
    global.fetch.mockResolvedValueOnce(refused());
    const w = mount(PaymentSlipModal, { props: { show: true, ...props } });
    await flushPromises();
    expect(global.fetch.mock.calls[0][0]).toBe(url);
    expect(w.find('.slip-error').text()).toContain(MSG);
    expect(w.text()).not.toContain('尚欠金額');
  });
});
