// In-app #396: opened from one course, the billing panel listed every contract sorted by amount
// owed with nothing marking the course the director clicked, so they had to scroll and guess.
// The opened course's card is now marked and scrolled into view; the owed-first order stays.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const course = (id, subject) => ({ id, subject, schedule_mode: 'date', charge: 0, paid: false, stop: false, package_id: null });

async function mountLedger({ studentClassId, studentId, invoices = [] }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  const payload = {
    summary: {}, scope: { student_class_id: studentClassId }, receipts: [], anomalies: [], invoices,
    courses: [course(11, '數學'), course(42, '英文'), course(7, '理化')],
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
});
