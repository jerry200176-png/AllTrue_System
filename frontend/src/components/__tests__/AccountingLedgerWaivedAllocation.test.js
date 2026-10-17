// #4205 item 2: a waived contract (server ContractMoneyState::isWaived) is terminal. A real waiver keeps Paid=0 and its
// bill is voided, so it would pass `!c.paid`; the bulk 登記收款 must still not offer it (submitting can only fail with
// course_waived), while an ordinary unpaid contract stays offered.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import PaymentAllocation from '../tuition/PaymentAllocation.vue';

const course = (id, subject, over = {}) => ({ id, subject, schedule_mode: 'count', charge: 12000, paid: false, stop: false,
  free_gift_settled: false, waived: false, paid_at: null, package_id: null, ...over });
// The real Paid=0 waiver shape: closed_reason waived (sent as `waived: true`), Stop set, its only bill voided.
const waivedCourse = (id, subject, over = {}) => course(id, subject, { stop: true, closed_reason: 'waived', waived: true, ...over });
const voidedBill = (id, studentClassId) => ({ id, student_class_id: studentClassId, subject: '英文', billing_period: '2026-09',
  total_amount: 12000, calculated_applied_amount: 0, outstanding_amount: 0, status: 'void', payments: [] });

async function mountLedger(payload) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: false, status: 500, json: async () => ({}) })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
  await flushPromises();
  return w;
}
const ledger = (courses, invoices = []) => ({ summary: {}, scope: { student_class_id: 1 }, invoices, receipts: [], anomalies: [], courses });
afterEach(() => vi.unstubAllGlobals());

describe('帳務面板 · 已免收的合約不進批次登記收款 (#4205)', () => {
  it('offers only the ordinary unpaid contract, never the waived one', async () => {
    const w = await mountLedger(ledger([course(1, '數學'), waivedCourse(2, '英文')], [voidedBill(70, 2)]));
    const btn = w.find('[data-testid="ledger-sticky-pay-btn"]');
    expect(btn.exists()).toBe(true);
    expect(btn.text()).toContain('數學');
    expect(btn.text()).not.toContain('2 份合約');
    await btn.trigger('click');
    const ids = w.findComponent(PaymentAllocation).props('contracts').map((c) => c.id);
    expect(ids).toEqual([1]);
  });

  it('shows no bulk action when the only open-looking contract is waived', async () => {
    const w = await mountLedger(ledger([waivedCourse(2, '英文')], [voidedBill(70, 2)]));
    expect(w.find('[data-testid="ledger-sticky-pay"]').exists()).toBe(false);
  });

  it('a waived first member of a shared package does not take the package action from its unpaid sibling', async () => {
    const w = await mountLedger(ledger([waivedCourse(1, '數學', { package_id: 9 }), course(2, '英文', { package_id: 9 })]));
    const btn = w.find('[data-testid="ledger-sticky-pay-btn"]');
    expect(btn.exists()).toBe(true);
    expect(btn.text()).toContain('英文');
  });
});
