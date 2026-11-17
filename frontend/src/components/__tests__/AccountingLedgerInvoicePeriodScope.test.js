import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const wrappers = [];
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); vi.unstubAllGlobals(); localStorage.clear(); });
async function open(range = {}, paid = false) {
  const invoice = { id: 31, student_class_id: 9, billing_period: '2024-01', total_amount: 1200,
    calculated_applied_amount: paid ? 300 : 0, outstanding_amount: paid ? 900 : 1200, status: paid ? 'partial' : 'unpaid',
    payments: paid ? [{ id: 41, amount: 300, applied_amount: 300, application_status: 'applied', paid_at: '2024-02-20', method: 'cash' }] : [], ...range };
  const course = { id: 9, subject: '合成數學', schedule_mode: 'date', paid: false, package_id: null,
    start_date: '2024-01-01', end_date: '2024-02-28', charge: 1200, rate: 300 };
  const payload = { student: { id: 91, name: '合成學生' }, scope: { student_class_id: 9 }, summary: {},
    courses: [course], invoices: [invoice], receipts: [], anomalies: [] };
  const before = JSON.stringify(payload);
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-only', user: { role: 'director' } }));
  vi.stubGlobal('fetch', vi.fn(async (url) => ({ ok: true, status: 200, json: async () => String(url).startsWith('/api/v1/accounting/ledger') ? payload : {
    ...course, sessions: ['2024-01-06', '2024-01-13', '2024-02-03', '2024-02-10'].map((date, i) => ({ class_session_id: i + 1, date, status: 'attended', payment: 'unpaid' })), unscheduled_count: 0,
  } })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 9, branchId: 1 }, global: { stubs: { Transition: false } } });
  wrappers.push(w); await flushPromises();
  return { w, payload, before, line: () => w.get('[data-testid="contract-month-pay"]').text() };
}

describe('in-app558 invoice scope under a month heading', () => {
  it('labels the complete service range and whole-bill total beside only two January lessons', async () => {
    const { w, line, payload, before } = await open({ period_start: '2024-01-01', period_end: '2024-02-28' });
    expect(line()).toContain('2024/01/01–2024/02/28');
    expect(line()).toContain('整張帳單合計 NT$ 1,200');
    expect(w.findAll('[data-testid="contract-session"]')).toHaveLength(4);
    expect(w.findAll('[data-testid="contract-month-pay"]')).toHaveLength(1);
    expect(JSON.stringify(payload)).toBe(before);
    expect(fetch.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
    expect(fetch.mock.calls.filter(([u]) => String(u).includes('/sessions'))).toHaveLength(1);
  });

  it('keeps a proven single-month bill concise', async () => {
    const { line } = await open({ period_start: '2024-01-01', period_end: '2024-01-31' });
    expect(line()).toBe('應繳 NT$ 1,200');
  });

  it.each([{}, { period_start: 'bad-date', period_end: '2024-02-28' }, { period_start: '2024-02-28', period_end: '2024-01-01' }])
  ('uses a bill-total label when its service range is not established: %j', async (range) => {
    const { line } = await open(range);
    expect(line()).toBe('整張帳單合計 NT$ 1,200');
    expect(line()).not.toContain('bad-date');
  });

  it('retains the recorded payment date and amount while clarifying the invoice range', async () => {
    const { line } = await open({ period_start: '2024-01-01', period_end: '2024-02-28' }, true);
    expect(line()).toContain('整張帳單合計 NT$ 1,200');
    expect(line()).toContain('已收 2/20 NT$ 300');
  });
});
