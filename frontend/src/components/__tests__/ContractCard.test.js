// Billing redesign PRD v2 (D11b/D13/D15/D16/D17): one card per contract with the
// full memo (editable), every lesson grouped by month with its payment state.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a) }));

import ContractCard from '../tuition/ContractCard.vue';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const COVERAGE = {
  student_class_id: 9, subject: '數學', schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31',
  memo: '家長要求週二上課\n第二行',
  unscheduled_count: 2,
  sessions: [
    { class_session_id: 1, date: '2026-09-01', start_time: '18:00', status: 'attended', payment: 'paid' },
    { class_session_id: 2, date: '2026-09-08', start_time: '18:00', status: 'leave', payment: 'paid' },
    { class_session_id: 3, date: '2026-10-06', start_time: '18:00', status: 'scheduled', payment: 'unpaid' },
  ],
};

beforeEach(() => authedFetch.mockReset());

describe('ContractCard', () => {
  it('shows title, full memo, month groups, payment state and unscheduled count', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9, subject: '數學' } } });
    await flushPromises();

    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/accounting/contracts/9/sessions');
    expect(w.find('h5').text()).toBe('數學・2026/09/01–2026/12/31');
    expect(w.find('[data-testid="contract-memo"]').text()).toContain('第二行');
    expect(w.findAll('h6').map((h) => h.text())).toEqual(['2026 年 9 月', '2026 年 10 月']);
    const rows = w.findAll('[data-testid="contract-session"]').map((r) => r.text());
    expect(rows[0]).toContain('已付');
    expect(rows[2]).toContain('未付');
    expect(w.find('[data-testid="contract-summary"]').text()).toBe('共 3 堂・已上 1・還沒上 3');
    expect(w.find('[data-testid="contract-unscheduled"]').text()).toBe('還有 2 堂還沒排日期');
    expect(w.text()).not.toMatch(/課程 0|帳單-|收據-/);
  });

  it('saves a memo-only PUT and shows the new memo', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE)).mockResolvedValueOnce(respond({}));
    const w = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();

    await w.find('[data-testid="contract-memo-edit"]').trigger('click');
    await w.find('[data-testid="contract-memo-input"]').setValue('改成週四');
    await w.find('[data-testid="contract-memo-save"]').trigger('click');
    await flushPromises();

    const [url, init] = authedFetch.mock.calls[1];
    expect(url).toBe('/api/v1/student-classes/9');
    expect(init.method).toBe('PUT');
    expect(JSON.parse(init.body)).toEqual({ Memo: '改成週四' });
    expect(w.find('[data-testid="contract-memo"]').text()).toBe('改成週四');
    expect(w.emitted('changed')).toHaveLength(1);
  });

  it('keeps the card usable when dates fail to load', async () => {
    authedFetch.mockResolvedValueOnce(respond({}, 500));
    const w = mount(ContractCard, { props: { course: { id: 9, subject: '英文' } } });
    await flushPromises();
    expect(w.text()).toContain('上課日暫時無法載入');
  });
});

describe('AccountingLedgerModal contract cards', () => {
  it('renders one card per contract, money-owed first', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond({
      summary: {}, scope: {}, receipts: [], anomalies: [],
      courses: [{ id: 1, subject: '英文' }, { id: 2, subject: '數學' }],
      invoices: [{ id: 5, student_class_id: 2, outstanding_amount: 3000, status: 'unpaid', payments: [] }],
    })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    expect(w.findAll('[data-testid^="contract-card-"]').map((c) => c.attributes('data-testid'))).toEqual(['contract-card-2', 'contract-card-1']);
    vi.unstubAllGlobals();
  });
});
