import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';
import { buildAccountingCsvRows } from '../../lib/studentClassDisplay.js';

const rows = [
  { report_id: 7101, student_class_id: 6101, receipt_no: 'RCPT-SYNTHETIC-7101', student_name: '合成學生', subject: 'Math', status: 'confirmed', payment_date: '2026-09-12', total_amount: 800, cash_amount: 800 },
  { report_id: 7102, student_class_id: 6102, receipt_no: 'RCPT-SYNTHETIC-7102', student_name: '合成學生', subject: 'Math', status: 'voided', payment_date: '2026-09-11', total_amount: 0, cash_amount: 0, course_lifecycle: 'history_settled', course_lifecycle_label: '歷史課程' },
];
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });
async function withPage(assertions, fixtureRows = rows) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
  const fetchMock = vi.fn(async (input) => {
    const url = new URL(String(input), 'http://synthetic.local');
    const status = url.searchParams.get('status') || 'confirmed';
    const data = url.pathname.startsWith('/api/v1/accounting/payments')
      ? fixtureRows.filter((r) => status === 'all' || r.status === status) : [];
    return { ok: true, json: async () => ({ data, summary: { total_count: data.filter((r) => r.status === 'confirmed').length, grand_total: data.reduce((n, r) => n + r.total_amount, 0) } }) };
  });
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = shallowMount(TuitionCollectionPage, { props: { branchId: 1 } });
  try {
    await wrapper.findAll('button').find((b) => b.text().includes('收據紀錄')).trigger('click');
    await flushPromises();
    await assertions(wrapper, fetchMock);
    expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
  } finally { wrapper.unmount(); }
}
async function queryStatus(wrapper, status) {
  const select = wrapper.find('[aria-label="收據狀態"]');
  expect(select.exists()).toBe(true);
  await select.setValue(status);
  await wrapper.find('.acct-filter-actions button').trigger('click');
  await flushPromises();
}
const tableRows = (wrapper) => wrapper.findAll('tr.acct-row-clickable');
describe('In-app350 existing void/history trace read path', () => {
  it('keeps the default confirmed-only receipts and ordinary actions', () => withPage(async (wrapper) => {
    expect(tableRows(wrapper)).toHaveLength(1);
    expect(tableRows(wrapper)[0].text()).toContain('查看收據');
    expect(tableRows(wrapper)[0].text()).toContain('撤銷');
  }));
  it.each(['all', 'voided'])('can query existing %s status including historical void reports', (status) => withPage(async (wrapper, fetchMock) => {
    await queryStatus(wrapper, status);
    expect(tableRows(wrapper)).toHaveLength(status === 'all' ? 2 : 1);
    const row = tableRows(wrapper).find((r) => r.text().includes('7102'));
    expect(row.text()).toContain('已作廢');
    expect(row.text()).toContain('歷史課程');
    expect(row.text()).not.toContain('查看收據');
    expect(row.text()).not.toContain('撤銷');
    const url = new URL(fetchMock.mock.calls.at(-1)[0], 'http://synthetic.local');
    expect(url.searchParams.get('status')).toBe(status);
    expect(url.searchParams.get('branch_id')).toBe('1');
  }));
  it('opens voided report ledger instead of a valid receipt or repeat void action', () => withPage(async (wrapper) => {
    await queryStatus(wrapper, 'voided');
    await tableRows(wrapper)[0].trigger('click');
    const ledger = wrapper.findComponent({ name: 'AccountingLedgerModal' });
    expect(ledger.props('show')).toBe(true);
    expect(ledger.props('reportId')).toBe(7102);
    expect(ledger.props('studentClassId')).toBe(6102);
    expect(wrapper.findComponent({ name: 'ReceiptModal' }).props('show')).toBe(false);
  }));
  it('exports the selected read status without treating void rows as collected money', () => withPage(async (wrapper, fetchMock) => {
    await queryStatus(wrapper, 'all');
    const write = vi.fn();
    vi.spyOn(window, 'open').mockReturnValue({ closed: false, close: vi.fn(), document: { open: vi.fn(), write, close: vi.fn() } });
    try {
      await wrapper.find('.acct-filter-actions .tc-btn--receipt').trigger('click');
      await flushPromises();
      const request = fetchMock.mock.calls.find(([url]) => String(url).includes('/accounting/payments/export?'));
      const url = new URL(request[0], 'http://synthetic.local');
      expect(url.searchParams.get('status')).toBe('all');
      expect(url.searchParams.get('branch_id')).toBe('1');
      expect(write.mock.calls.at(-1)[0]).toContain('已作廢 / 歷史課程');
      expect(wrapper.find('.tc-card--total .tc-card-num').text()).toBe('1');
      expect(wrapper.find('.tc-card--outstanding .tc-card-num').text()).toBe('NT$ 800');
    } finally { window.open.mockRestore(); }
  }));
  it('does not describe an empty void query as missing collected payments', () => withPage(async (wrapper) => {
    await queryStatus(wrapper, 'voided');
    expect(wrapper.findComponent({ name: 'AtEmpty' }).props('title')).toBe('此區間尚無符合狀態的收據紀錄');
  }, []));
  it('keeps CSV void records explicitly marked and outside payment totals', () => {
    const csv = buildAccountingCsvRows([rows[1]])[0];
    expect(csv[12]).toContain('已作廢');
    expect(csv.slice(8, 11)).toEqual([0, 0, 0]);
  });
});
