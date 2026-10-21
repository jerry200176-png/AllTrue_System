// Synthetic characterization: current course price is distinct from issued invoice obligation.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import { getCourseTotalFee } from '../../lib/coursePricing.js';
import { issuedBillRateNote } from '../../lib/issuedBillRateNote.js';
const respond = (body) => ({ ok: true, status: 200, json: async () => body });
async function ledger({ invoices = [], course = {} }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic' }));
  const row = { id: 7, subject: 'synthetic', schedule_mode: 'count', current_contract_charge: 8800,
    charge: 8800, payment_type: 'session', rate_per_30min: 1100, sessions_purchased: 8, paid: false, ...course };
  const payload = { student: { id: 1 }, summary: {}, scope: { student_class_id: 7 }, courses: [row], invoices, receipts: [], anomalies: [] };
  vi.stubGlobal('fetch', vi.fn(async (url) => {
    if (String(url).includes('/accounting/ledger')) return respond(payload);
    if (String(url).includes('monthly-drafts')) return respond({ data: [] });
    return respond({ student_class_id: 7, sessions: [], unscheduled_count: 0, schedule_mode: row.schedule_mode });
  }));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 7 }, global: { stubs: { Transition: false } } });
  await flushPromises();
  return { w, card: w.find('[data-testid="contract-card-7"]'), course: row };
}
const invoice = (extra = {}) => ({ id: 31, student_class_id: 7, status: 'partial', total_amount: 8800, stored_total_amount: 8800,
  calculated_applied_amount: 8000, outstanding_amount: 800, paid_amount: 8000, amount_source: 'invoice_total',
  payments: [{ id: 1, amount: 8000, method: 'cash', paid_at: '2026-01-10' }], ...extra });
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });
describe('amount-source synthetic characterization', () => {
  it('issued8800 paid8000 due800 is ledger arithmetic', async () => {
    const { w, card } = await ledger({ invoices: [invoice()] });
    expect(card.find('[data-testid="contract-amounts"]').text()).toBe('本合約帳單 應收 NT$ 8,800・已收 NT$ 8,000・未收 NT$ 800');
    expect(card.find('[data-testid="contract-last-payment"]').text()).toContain('NT$ 8,000'); w.unmount();
  });
  it('manual issued8000 paid8000 remains settled despite current course8800', async () => {
    const { w, card, course } = await ledger({ invoices: [invoice({ total_amount: 8000, stored_total_amount: 8000,
      outstanding_amount: 0, status: 'paid', hand_priced: true })], course: { paid: true } });
    expect(getCourseTotalFee(course)).toBe(8800);
    expect(card.find('[data-testid="contract-amounts"]').text()).toBe('本合約帳單 應收 NT$ 8,000・已收 NT$ 8,000・未收 NT$ 0');
    expect(card.find('[data-testid="contract-rate-note"]').exists()).toBe(false); w.unmount();
  });
  it('unpaid issued8000 current8800 comparison stays neutral without rewriting invoice', async () => {
    const bill = invoice({ total_amount: 8000, stored_total_amount: 8000, calculated_applied_amount: 0, paid_amount: 0,
      outstanding_amount: 8000, status: 'unpaid', payments: [], single_course_items: true, hand_priced: false });
    const { w, card, course } = await ledger({ invoices: [bill] });
    expect(issuedBillRateNote(course, [bill])).toEqual({ invoiceId: 31, issued: 8000, current: 8800 });
    // in-app #414 / #4155: contract Charge is shown apart from the stored bill total
    expect(card.find('[data-testid="contract-charge"]').text()).toBe('合約金額 NT$ 8,800');
    expect(card.find('[data-testid="contract-rate-note"]').text()).toContain('開單時金額 NT$ 8,000 ／ 目前課程金額 NT$ 8,800');
    expect(card.find('[data-testid="contract-rate-note"]').text()).toContain('不代表帳單有誤');
    expect(card.find('[data-testid="contract-amounts"]').text()).toContain('本合約帳單 應收 NT$ 8,000'); w.unmount();
  });
  it('monthly projection8800 does not invent a payment from stored8000', async () => {
    const { w, card } = await ledger({ course: { schedule_mode: 'date' }, invoices: [invoice({ stored_total_amount: 8000,
      computed_total_amount: 8800, amount_source: 'billable_sessions', calculated_applied_amount: 0, paid_amount: 0,
      outstanding_amount: 8800, status: 'unpaid', payments: [], billing_period: '2026-01' })] });
    expect(card.find('[data-testid="contract-amounts"]').text()).toBe('本合約帳單 應收 NT$ 8,800・已收 NT$ 0・未收 NT$ 8,800');
    expect(card.text()).not.toContain('已收 NT$ 8,000'); expect(w.text()).toContain('8,800'); w.unmount();
  });
  it('aggregates active invoices once and excludes void bills from8800/8000/800', async () => {
    const paid = invoice({ total_amount: 8000, stored_total_amount: 8000, outstanding_amount: 0, status: 'paid' });
    const extra = invoice({ id: 32, total_amount: 800, stored_total_amount: 800, status: 'unpaid',
      calculated_applied_amount: 0, paid_amount: 0, outstanding_amount: 800, payments: [] });
    const voided = invoice({ id: 33, status: 'void', total_amount: 9900, calculated_applied_amount: 0, outstanding_amount: 9900, payments: [] });
    const { w, card } = await ledger({ invoices: [paid, extra, voided] });
    expect(card.find('[data-testid="contract-amounts"]').text()).toBe('本合約帳單 應收 NT$ 8,800・已收 NT$ 8,000・未收 NT$ 800');
    w.unmount();
  });
  it('legacy paid flag without receipts never invents8000 received or payment date', async () => {
    const { w, card } = await ledger({ course: { paid: true, paid_at: '2026-01-10', legacy_paid_flag_only: true } });
    expect(card.text()).toContain('需核對：舊資料標記已收，缺付款紀錄');
    expect(card.find('[data-testid="contract-amounts"]').exists()).toBe(false);
    expect(card.find('[data-testid="contract-last-payment"]').exists()).toBe(false); w.unmount();
  });
});
