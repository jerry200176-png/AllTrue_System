// #3753 (PRD v2 D18): a shared CoursePackage is one payment obligation, so the billing panel
// offers one payment action for the package, not one per member card.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

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

  it('a pending report on one member shows once for the package and offers no second record', async () => {
    const w = await mountLedger(ledger({
      receipts: [{ report_id: 5, student_class_id: 2, amount: 24000, status: 'pending', payment_date: '2026-08-20' }],
    }));
    expect(w.findAll('[data-testid="contract-confirm"]')).toHaveLength(1);
    expect(w.findAll('[data-testid="contract-record"]')).toHaveLength(1); // only 理化
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').text()).toContain('理化');
  });
});
