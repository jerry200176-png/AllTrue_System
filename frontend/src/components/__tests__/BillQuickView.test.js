import { describe, expect, it, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import BillQuickView from '../tuition/BillQuickView.vue';

const BILL = { key: 'c7', kind: 'confirm', label: '待確認入帳', class_id: 7, student_name: '學生甲', subject: '數學', period: '2026-09-01 ~ 2026-12-31', src: {} };
const LEDGER = {
  courses: [{ id: 7 }],
  invoices: [{ id: 1, student_class_id: 7, status: 'unpaid', total_amount: 8800, calculated_applied_amount: 0, outstanding_amount: 8800 }],
  receipts: [
    { report_id: 55, student_class_id: 7, status: 'pending', amount: 8800, payment_date: '2026-11-03', payment_method: 'transfer', account_last5: '12345' },
    { report_id: 56, student_class_id: 8, status: 'pending', amount: 1, payment_date: '2026-11-03', payment_method: 'cash' },
  ],
};
const json = (body, ok = true) => ({ ok, status: ok ? 200 : 400, json: () => Promise.resolve(body) });
let calls;
beforeEach(() => {
  calls = [];
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  global.fetch = vi.fn((url, init = {}) => {
    calls.push([String(url), init.method || 'GET', init.body]);
    if (String(url).includes('/accounting/ledger')) return Promise.resolve(json(LEDGER));
    if (String(url).includes('/sessions')) return Promise.resolve(json({ sessions: [{ class_session_id: 1, date: '2026-11-04', start_time: '18:00', status: 'attended' }] }));
    return Promise.resolve(json({ message: 'ok' }));
  });
});
const mountQv = async (props = {}) => {
  const w = mount(BillQuickView, { props: { open: true, bill: BILL, branchId: 1, ...props }, global: { stubs: { teleport: true } } });
  await flushPromises();
  return w;
};

describe('BillQuickView', () => {
  it('reads the existing ledger for that contract and shows the amounts and the parent report', async () => {
    const w = await mountQv();
    expect(calls[0][0]).toContain('/api/v1/accounting/ledger?student_class_id=7');
    expect(w.get('[data-testid="qv-money"]').text()).toContain('NT$ 8,800');
    const report = w.get('[data-testid="qv-report"]').text();
    expect(report).toContain('NT$ 8,800');
    expect(report).toContain('2026-11-03');
    expect(report).toContain('匯款');
    expect(report).toContain('後5碼 12345');
    expect(w.text()).not.toContain('NT$ 1・'); // the other contract's report is not shown
  });

  it('確認入帳 asks first, then confirms that report and hands it on', async () => {
    const w = await mountQv();
    await w.get('[data-testid="qv-confirm"]').trigger('click');
    expect(calls.some((c) => c[0].includes('/confirm'))).toBe(false);
    await w.get('[data-testid="qv-confirm-submit"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c[0].includes('payment-reports/55/confirm') && c[1] === 'PUT')).toBe(true);
    expect(w.emitted('done')[0][0]).toEqual({ action: 'confirm', reportId: 55 });
  });

  it('退回 needs a reason and sends it', async () => {
    const w = await mountQv();
    await w.get('[data-testid="qv-reject"]').trigger('click');
    expect(w.get('[data-testid="qv-reject-submit"]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid="qv-reject-reason"]').setValue('金額不對');
    await w.get('[data-testid="qv-reject-submit"]').trigger('click');
    await flushPromises();
    const call = calls.find((c) => c[0].includes('payment-reports/55/reject'));
    expect(JSON.parse(call[2])).toEqual({ rejection_note: '金額不對' });
    expect(w.emitted('done')[0][0]).toEqual({ action: 'reject', reportId: 55 });
  });

  it('opens straight on the reject reason when asked from the row menu', async () => {
    const w = await mountQv({ rejectWanted: true });
    expect(w.find('[data-testid="qv-reject-reason"]').exists()).toBe(true);
  });

  it('shows 登記收款 (and no report block) when nothing is waiting, and loads the lessons only when opened', async () => {
    global.fetch = vi.fn((url) => Promise.resolve(json(String(url).includes('/accounting/ledger') ? { ...LEDGER, receipts: [] } : { sessions: [] })));
    const w = await mountQv({ bill: { ...BILL, kind: 'owing', label: '未繳' } });
    expect(w.find('[data-testid="qv-report"]').exists()).toBe(false);
    await w.get('[data-testid="qv-record"]').trigger('click');
    expect(w.emitted('record')).toHaveLength(1);
    expect(global.fetch.mock.calls.some(([u]) => String(u).includes('/sessions'))).toBe(false);
  });
});
