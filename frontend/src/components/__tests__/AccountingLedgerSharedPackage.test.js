// #3753 (PRD v2 D18): a shared CoursePackage is one payment obligation, so the billing panel
// offers one payment action for the package, not one per member card.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import PaymentAllocation from '../tuition/PaymentAllocation.vue';
const course = (id, subject, packageId) => ({ id, subject, schedule_mode: 'count', charge: 0, paid: false, stop: false, package_id: packageId });
function ledger(over = {}) {
  return {
    summary: {}, scope: { student_class_id: 1 }, invoices: [], receipts: [], anomalies: [],
    courses: [course(1, '數學', 9), course(2, '英文', 9), course(3, '理化', null)],
    ...over,
  };
}
async function mountLedger(payload) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: false, status: 500, json: async () => ({}) })));
  const w = mount(AccountingLedgerModal, {
    props: { show: true, studentClassId: 1, branchId: 1 },
    global: { stubs: { Transition: false } },
  });
  await flushPromises();
  return w;
}
afterEach(() => vi.unstubAllGlobals());
describe('帳務面板 · 共用方案只有一個收款入口 (#3753)', () => {
  it('two members of one package get one 登記這筆 and the sticky action counts the package once', async () => {
    const w = await mountLedger(ledger());
    expect(w.findAll('[data-testid="contract-record"]')).toHaveLength(2); // package (1 card) + 理化
    expect(w.findAll('[data-testid="contract-shared-follower"]')).toHaveLength(1);
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('2 份合約');
  });
  it('#4002: a tutoring member never leads a package that also has a payable member', async () => {
    const w = await mountLedger(ledger({
      courses: [{ ...course(1, '輔導', 9), class_type: 'tutoring' }, course(2, '英文', 9), course(3, '理化', null)],
    }));
    const recordCards = w.findAll('[data-testid="contract-record"]');
    expect(recordCards).toHaveLength(2); // 英文 leads the package + 理化
    const followerOf = (id) => w.findAllComponents({ name: 'ContractCard' }).find((card) => card.props('course').id === id).props('sharedFollower');
    expect(followerOf(2)).toBe(false); // 英文 holds the package action
    expect(followerOf(1)).toBe(true);
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('2 份合約');
  });
  it('mixed states: a paid first member does not hide the package action from its unpaid sibling', async () => {
    const w = await mountLedger(ledger({
      courses: [{ ...course(1, '數學', 9), paid: true }, course(2, '英文', 9), course(3, '理化', null)],
    }));
    const recordCards = w.findAll('[data-testid="contract-record"]');
    expect(recordCards).toHaveLength(2); // 英文 (package) + 理化
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('2 份合約');
  });
  it('the follower hint names the card that holds the action, not the first member (pending report on a later member)', async () => {
    const w = await mountLedger(ledger({
      receipts: [{ report_id: 5, student_class_id: 2, amount: 24000, status: 'pending', payment_date: '2026-08-20' }],
    }));
    const hint = w.find('[data-testid="contract-shared-follower"]');
    expect(hint.exists()).toBe(true);
    expect(hint.text()).toContain('「英文」');
    expect(hint.text()).not.toContain('第一張');
  });
  it('a pending report on one member shows once for the package and offers no second record', async () => {
    const w = await mountLedger(ledger({
      receipts: [{ report_id: 5, student_class_id: 2, amount: 24000, status: 'pending', payment_date: '2026-08-20' }],
    }));
    expect(w.findAll('[data-testid="contract-confirm"]')).toHaveLength(1);
    expect(w.findAll('[data-testid="contract-record"]')).toHaveLength(1); // only 理化
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('理化');
  });
  it('#3892 review: legacy member bills keep explicit card actions but cannot partially register through one batch', async () => {
    const inv = (id, sc) => ({ id, student_class_id: sc, status: 'unpaid', total_amount: 6000, outstanding_amount: 6000, calculated_applied_amount: 0, due_date: '2026-08-15', payments: [] });
    const w = await mountLedger(ledger({ invoices: [inv(11, 1), inv(12, 2)] }));
    expect(w.findAll('[data-testid="contract-shared-follower"]')).toHaveLength(0);
    expect(w.findAll('[data-testid="contract-record"]')).toHaveLength(3); // 數學 + 英文 + 理化
    expect(w.get('[data-testid="ledger-package-batch-note"]').text()).toContain('請從要處理的課程卡');
    expect(w.get('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('理化');
    await w.get('[data-testid="ledger-sticky-pay-btn"]').trigger('click');
    expect(w.findComponent(PaymentAllocation).props('contracts').map((c) => c.id)).toEqual([3]);
  });
});
