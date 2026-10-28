// In-app #396: opened from one course, the billing panel listed every contract sorted by amount
// owed with nothing marking the course the director clicked, so they had to scroll and guess.
// The opened course's card is now marked and scrolled into view; the owed-first order stays.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import PaymentAllocation from '../tuition/PaymentAllocation.vue';

const course = (id, subject) => ({ id, subject, schedule_mode: 'date', charge: 0, paid: false, stop: false, package_id: null });

async function mountLedger({ studentClassId, studentId, invoices = [], courses }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  const payload = {
    summary: {}, scope: { student_class_id: studentClassId }, receipts: [], anomalies: [], invoices,
    courses: courses || [course(11, '數學'), course(42, '英文'), course(7, '理化')],
  };
  vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: false, status: 500, json: async () => ({}) })));
  const w = mount(AccountingLedgerModal, {
    attachTo: document.body,
    props: { show: true, studentClassId, studentId, branchId: 1 },
    global: { stubs: { Transition: false } },
  });
  await flushPromises();
  return w;
}

afterEach(() => {
  vi.unstubAllGlobals();
  delete Element.prototype.scrollIntoView;
});

describe('帳務面板 · 標出並捲到你點開的那門課 (in-app #396)', () => {
  it('marks only the opened course and scrolls to it when another contract is listed first', async () => {
    const scrolled = [];
    Element.prototype.scrollIntoView = function scrollIntoView() { scrolled.push(this.getAttribute('data-testid')); };
    const w = await mountLedger({
      studentClassId: 42,
      invoices: [{ id: 1, student_class_id: 11, status: 'unpaid', outstanding_amount: 4400, total_amount: 4400, payments: [] }],
    });
    const cards = w.findAll('.ledger-contracts [data-testid^="contract-card-"]');
    expect(cards[0].attributes('data-testid')).toBe('contract-card-11'); // owed-first order unchanged
    const focused = w.findAll('[data-testid="contract-focused"]');
    expect(focused).toHaveLength(1);
    expect(w.find('[data-testid="contract-card-42"] [data-testid="contract-focused"]').exists()).toBe(true);
    expect(scrolled).toEqual(['contract-card-42']);
    w.unmount();
  });

  it('opened for a whole student (no course): nothing is marked and nothing scrolls', async () => {
    const scrolled = [];
    Element.prototype.scrollIntoView = function scrollIntoView() { scrolled.push(this.getAttribute('data-testid')); };
    const w = await mountLedger({ studentClassId: undefined, studentId: 5 });
    expect(w.findAll('[data-testid="contract-focused"]')).toHaveLength(0);
    expect(scrolled).toEqual([]);
    w.unmount();
  });

  it('#437: a paid opened course does not change the other contract selected for collection', async () => {
    const w = await mountLedger({
      studentClassId: 42,
      courses: [course(11, '數學'), { ...course(42, '英文'), paid: true }],
      invoices: [{ id: 55, student_class_id: 11, status: 'unpaid', outstanding_amount: 800, total_amount: 800, payments: [] }],
    });
    expect(w.find('[data-testid="contract-card-42"] [data-testid="contract-focused"]').exists()).toBe(true);
    const button = w.get('[data-testid="ledger-sticky-pay-btn"]');
    expect(button.text()).toContain('數學');
    const scope = w.get('[data-testid="ledger-pay-scope"]');
    expect(scope.isVisible()).toBe(true);
    // #437: the opened 英文 is settled, so the hint names both courses instead of a generic scope line.
    expect(scope.text()).toBe('你點開的「英文」目前沒有要收的款；這個按鈕是登記「數學」的收款，下一步可再確認科目與金額。');
    expect(button.attributes('aria-describedby')).toBe(scope.attributes('id'));
    await button.trigger('click');
    expect(w.findComponent(PaymentAllocation).props('contracts').map((c) => ({ id: c.id, invoice_id: c.invoice_id, owed: c.owed })))
      .toEqual([{ id: 11, invoice_id: 55, owed: 800 }]);
    expect(fetch.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
    w.unmount();
  });

  it('#4559 review: an opened course left out of the batch while it still owes is not called settled', async () => {
    const w = await mountLedger({
      studentClassId: 42,
      // 英文 and 理化 share package 9 and both owe, so neither joins the batch (#3892); only 數學 does.
      courses: [course(11, '數學'), { ...course(42, '英文'), package_id: 9 }, { ...course(7, '理化'), package_id: 9 }],
      invoices: [
        { id: 55, student_class_id: 11, status: 'unpaid', outstanding_amount: 800, total_amount: 800, payments: [] },
        { id: 56, student_class_id: 42, status: 'unpaid', outstanding_amount: 600, total_amount: 600, payments: [] },
        { id: 57, student_class_id: 7, status: 'unpaid', outstanding_amount: 600, total_amount: 600, payments: [] },
      ],
    });
    const text = w.find('[data-testid="ledger-pay-scope"]').text();
    expect(text).not.toContain('沒有要收的款');
    expect(text).toContain('不含你點開的「英文」');
    w.unmount();
  });

  it('#437: when the opened course itself can be collected, the generic scope line stays', async () => {
    const w = await mountLedger({ studentClassId: 11, courses: [course(11, '數學'), { ...course(42, '英文'), paid: true }] });
    expect(w.get('[data-testid="ledger-pay-scope"]').text()).toContain('不限於點開的課程');
    w.unmount();
  });

  it('#437: settled contracts offer neither a collection action nor its scope hint', async () => {
    const w = await mountLedger({
      studentClassId: 42,
      courses: [{ ...course(11, '數學'), paid: true }, { ...course(42, '英文'), paid: true }],
    });
    expect(w.find('[data-testid="ledger-sticky-pay"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-pay-scope"]').exists()).toBe(false);
    w.unmount();
  });

  it('#437: multiple contracts are named in the next step rather than promised on the count-only button', async () => {
    const w = await mountLedger({
      studentClassId: 42,
      courses: [course(11, '數學'), { ...course(42, '英文'), paid: true }, course(7, '理化')],
    });
    const button = w.get('[data-testid="ledger-sticky-pay-btn"]');
    expect(button.text()).toContain('2 份合約');
    const scope = w.get('[data-testid="ledger-pay-scope"]');
    expect(scope.isVisible()).toBe(true);
    expect(scope.text()).toMatch(/「(數學、理化|理化、數學)」/);
    expect(scope.text()).toContain('下一步可再確認科目與金額');
    await button.trigger('click');
    expect(w.findComponent(PaymentAllocation).props('contracts').map((c) => c.id).sort((a, b) => a - b)).toEqual([7, 11]);
    w.unmount();
  });
});
