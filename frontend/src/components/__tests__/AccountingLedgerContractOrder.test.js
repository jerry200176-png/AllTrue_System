import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import ContractCard from '../tuition/ContractCard.vue';
import PaymentAllocation from '../tuition/PaymentAllocation.vue';

const course = (id, subject, start_date, over = {}) => ({
  id, subject, start_date, schedule_mode: 'count', charge: 1000,
  paid: true, stop: false, package_id: null, ...over,
});
const wrappers = [];
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); vi.unstubAllGlobals(); localStorage.clear(); delete Element.prototype.scrollIntoView; });
async function open(courses, invoices = [], studentClassId = null, { receipts = [], drafts = [] } = {}) {
  const payload = { summary: {}, scope: { student_class_id: studentClassId }, courses, invoices, receipts, anomalies: [] };
  const before = JSON.stringify(payload);
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-only' }));
  vi.stubGlobal('fetch', vi.fn(async (url) => ({ ok: true, status: 200,
    json: async () => {
      if (String(url).startsWith('/api/v1/accounting/ledger')) return payload;
      if (String(url).startsWith('/api/v1/accounting/monthly-drafts')) return { data: drafts };
      return { sessions: [] };
    },
  })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentId: 99, studentClassId, branchId: 1 }, global: { stubs: { Transition: false } } });
  wrappers.push(w); await flushPromises();
  return { w, payload, before, cards: () => w.findAllComponents(ContractCard) };
}

describe('in-app564 contract scan order', () => {
  it('keeps every card, groups equal-balance subjects, and shows the newer contract first', async () => {
    const { cards, payload, before } = await open([
      course(1, '數學', '2026-08-08'), course(2, '英文', '2026-09-23'),
      course(3, '數學', '2026-10-08', { paid: false }), course(4, '化學', '2026-10-02'),
      course(5, '英文', '2026-10-07', { paid: false }), course(6, '數學', '2026-10-09'),
    ]);
    const rows = cards().map((c) => c.props('course'));
    expect(rows.map((c) => c.id).sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5, 6]);
    for (const subject of ['數學', '英文']) {
      const indices = rows.flatMap((c, i) => c.subject === subject ? [i] : []);
      expect(indices.at(-1) - indices[0] + 1).toBe(indices.length);
    }
    expect(rows.filter((c) => c.subject === '數學').map((c) => c.id)).toEqual([6, 3, 1]);
    expect(rows.filter((c) => c.subject === '英文').map((c) => c.id)).toEqual([5, 2]);
    expect(JSON.stringify(payload)).toBe(before);
    expect(fetch.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
  });

  it('retains outstanding-invoice priority ahead of newer zero-balance contracts', async () => {
    const { cards } = await open([
      course(1, '數學', '2026-11-01'), course(2, '英文', '2026-01-01', { paid: false }),
      course(3, '數學', '2026-10-01', { paid: false }),
    ], [2, 3].map((id) => ({ id: id + 10, student_class_id: id, status: 'unpaid', outstanding_amount: 1000, total_amount: 1000, payments: [] })));
    const rows = cards();
    expect(rows.slice(0, 2).every((c) => c.props('outstanding') > 0)).toBe(true);
    expect(rows.at(-1).props('course').id).toBe(1);
  });

  it('shows the contracts before documents and prioritizes each existing action', async () => {
    const { w, cards } = await open([
      course(1, '化學', '2026-11-14'),
      course(2, '數學', '2026-10-01', { paid: false }),
      course(3, '英文', '2026-09-12', { paid: false, schedule_mode: 'date' }),
      course(4, '國文', '2026-08-16', { paid: false }),
      course(5, '英文', '2026-10-12', { schedule_mode: 'date' }),
    ], [
      { id: 12, student_class_id: 2, status: 'unpaid', outstanding_amount: 1000, total_amount: 1000, payments: [] },
      { id: 13, student_class_id: 5, status: 'paid', outstanding_amount: 0, total_amount: 1000, calculated_applied_amount: 1000, payments: [] },
    ], null, {
      receipts: [{ report_id: 15, student_class_id: 4, status: 'pending', amount: 1000 }],
      drafts: [3, 5].map((student_class_id) => ({ student_class_id, status: 'ready', proposed_end_date: '2099-12-31' })),
    });
    expect(cards().map((c) => c.props('course').id)).toEqual([4, 2, 5, 3, 1]);
    expect(w.get('[data-testid="contract-card-5"]').get('[data-testid="contract-issue"]').text()).toContain('開帳單');
    const contracts = w.get('[data-testid="ledger-contracts"]').element;
    for (const name of ['invoices', 'receipts']) {
      const section = w.get(`[data-testid="ledger-fold-${name}"]`).element;
      expect(contracts.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    }
    expect(fetch.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
  });

  it('does not move shared-package payment ownership when display dates change the card order', async () => {
    const { w, cards } = await open([
      course(1, '數學', '2026-08-01', { paid: false, package_id: 9 }),
      course(2, '數學', '2026-10-01', { paid: false, package_id: 9 }),
    ]);
    expect(cards().map((c) => c.props('course').id)).toEqual([2, 1]);
    expect(cards().find((c) => c.props('course').id === 1).props('sharedFollower')).toBe(false);
    expect(cards().find((c) => c.props('course').id === 2).props('sharedFollower')).toBe(true);
    await w.get('[data-testid="ledger-sticky-pay-btn"]').trigger('click');
    expect(w.findComponent(PaymentAllocation).props('contracts').map((c) => c.id)).toEqual([1]);
  });

  it('puts missing dates last and resolves equal dates deterministically without hiding either row', async () => {
    const { cards } = await open([
      course(1, '數學', null), course(2, '數學', '2026-10-01'),
      course(3, '數學', 'bad-date'), course(4, '數學', '2026-10-01'),
    ]);
    expect(cards().map((c) => c.props('course').id)).toEqual([4, 2, 3, 1]);
  });

  it('scrolls to the opened course when the former first row moves below newer contracts', async () => {
    const scrolled = [];
    Element.prototype.scrollIntoView = function () { scrolled.push(this.getAttribute('data-testid')); };
    const { cards } = await open([course(1, '數學', '2026-01-01'), course(2, '英文', '2026-10-01'), course(3, '數學', '2026-10-01')], [], 1);
    expect(cards().at(-1).props('course').id).toBe(1);
    expect(scrolled).toContain('contract-card-1');
  });
});
