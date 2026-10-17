import { beforeEach, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token' }));
  global.fetch = vi.fn();
});
async function attempt(code, amount, extra = {}) {
  global.fetch.mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ code, message: 'synthetic refusal', ...extra }) });
  const w = mount(PaymentEntryModal, { props: { show: false, row: {
    id: 901, student_name: 'Synthetic fixture', subject: 'Synthetic subject',
    charge: 8000, payable_status: 'invoiced', payable_amount: 8000, payable_outstanding: 8000, payable_invoice_id: 902,
  } } });
  await w.setProps({ show: true });
  await w.find('input[type="number"]').setValue(amount);
  await w.find('form').trigger('submit');
  await new Promise((resolve) => setTimeout(resolve, 10));
  await nextTick();
  return w;
}
it('keeps intended receipt 8800 and requires checking the existing report before confirming', async () => {
  const w = await attempt('pending_report_exists', 8800, { report_id: 903 });
  expect(w.text()).toContain('金額不符請先退回');
  expect(w.text()).not.toContain('請直接按');
  expect(w.find('input[type="number"]').element.value).toBe('8800');
  expect(w.emitted('confirmed')).toBeUndefined();
  expect(global.fetch).toHaveBeenCalledTimes(1);
});
it('preserves an over-limit receipt and directs reconciliation rather than changing the amount', async () => {
  const w = await attempt('amount_exceeds_outstanding', 8800, { expected_amount: 8000 });
  expect(w.text()).toContain('不要為了送出而改小實收金額');
  expect(w.text()).toContain('帳單金額有誤');
  expect(w.find('input[type="number"]').element.value).toBe('8800');
  expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toMatchObject({ amount: 8800, invoice_id: 902 });
  expect(w.emitted('confirmed')).toBeUndefined();
});
