import { beforeEach, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

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
  expect(w.find('.pe-error').attributes('role')).toBe('alert');
  expect(w.text()).not.toContain('請直接按');
  expect(w.find('input[type="number"]').element.value).toBe('8800');
  expect(w.emitted('confirmed')).toBeUndefined();
  expect(global.fetch).toHaveBeenCalledTimes(1);
});
it('preserves an over-limit receipt and directs reconciliation rather than changing the amount', async () => {
  const w = await attempt('amount_exceeds_outstanding', 8800, { expected_amount: 7600 });
  expect(w.text()).toContain('NT$7,600');
  expect(w.text()).toContain('不要為了送出而改小實收金額');
  expect(w.text()).toContain('帳單金額有誤');
  expect(w.find('input[type="number"]').element.value).toBe('8800');
  expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toMatchObject({ amount: 8800, invoice_id: 902 });
  expect(w.emitted('confirmed')).toBeUndefined();
});
it('shows an authoritative zero balance without changing the entered receipt', async () => {
  const w = await attempt('amount_exceeds_outstanding', 8800, { expected_amount: 0 });
  expect(w.text()).toContain('NT$0');
  expect(w.find('input[type="number"]').element.value).toBe('8800');
});
// PR4217: real child + each navigation consumer.
it.each(['collection page', 'accounting ledger'])('keeps mismatched pending-report guidance and the entered amount in the %s', async (owner) => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
  const row = { id: 901, student_id: 11, student_name: 'Synthetic fixture', subject: 'Synthetic subject',
    schedule_mode: 'count', payment_status: 'unpaid', alert_type: 'unpaid', charge: 8800, outstanding: 8800,
    paid_amount: 0, paid: false, stop: false, remaining_sessions: 8,
    payable_status: 'invoiced', payable_amount: 8800, payable_outstanding: 8800, payable_invoice_id: 902 };
  const payload = { summary: {}, student: { name: row.student_name }, scope: { student_class_id: 901 },
    courses: [row], receipts: [], anomalies: [], invoices: [{ id: 902, student_class_id: 901,
      total_amount: 8800, outstanding_amount: 8800, calculated_applied_amount: 0, status: 'unpaid', payments: [] }] };
  let refused = false;
  global.fetch = vi.fn(async (url) => {
    const path = String(url);
    if (path.includes('/payment-reports/director-record')) {
      refused = true;
      return { ok: false, status: 422, json: async () => ({ code: 'pending_report_exists', report_id: 903, reported_amount: 8000 }) };
    }
    const currentRow = refused ? { ...row, payment_status: 'pending_report', latest_payment_report_id: 903 } : row;
    const currentLedger = refused ? { ...payload, courses: [currentRow], receipts: [
      { report_id: 903, student_class_id: 901, status: 'pending', amount: 8000, payment_date: '2026-10-01' },
    ] } : payload;
    return { ok: true, status: 200, json: async () => path.includes('/accounting/ledger') ? currentLedger : path.includes('/alerts/tuition') ? [currentRow] : [] };
  });
  const stubs = { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true,
    AccountingLedgerModal: true, StudentBillingList: true, OperationsQuickStart: true, teleport: true };
  const w = owner === 'collection page'
    ? mount(TuitionCollectionPage, { props: { branchId: 1, initialTab: 'all' }, global: { stubs } })
    : mount(AccountingLedgerModal, { props: { show: true, studentClassId: 901, branchId: 1 }, global: { stubs: { Transition: false } } });
  try {
    await flushPromises();
    await w.find(owner === 'collection page' ? 'button[title="登記已回報"]' : '[data-testid="contract-record"]').trigger('click');
    const entry = w.findComponent(PaymentEntryModal);
    await entry.find('input[type="number"]').setValue(8800);
    await entry.find('form').trigger('submit');
    await flushPromises();
    expect(entry.props('show')).toBe(true);
    expect(entry.text()).toContain('金額不符請先退回');
    expect(entry.find('input[type="number"]').element.value).toBe('8800');
    expect(w.text()).not.toContain('請按確認入帳，不要重複送出');
    expect(global.fetch.mock.calls.filter(([url]) => String(url).includes('/director-record'))).toHaveLength(1);
    expect(global.fetch.mock.calls.some(([url]) => /payment-reports\/\d+\/confirm/.test(String(url)))).toBe(false);
    const readPath = owner === 'collection page' ? '/alerts/tuition' : '/accounting/ledger';
    expect(global.fetch.mock.calls.filter(([url]) => String(url).includes(readPath))).toHaveLength(2);
    await entry.find('button.ghost').trigger('click');
    await flushPromises();
    expect(w.find(owner === 'collection page' ? 'button[title="確認入帳"]' : '[data-testid="contract-confirm"]').exists()).toBe(true);
  } finally { w.unmount(); }
});
