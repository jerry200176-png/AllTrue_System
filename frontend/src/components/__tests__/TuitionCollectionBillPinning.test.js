// Billing v3: a row's button opens THAT contract, never the student's first owing one.
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { toastState } from '../../composables/useToast.js';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const row = (o) => ({ student_id: 1, student_name: '測試生', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 1000, payable_amount: 1000, ...o });
const ROWS = [row({ id: 10, subject: '數學' }), row({ id: 11, subject: '英文' })];
const json = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-05T10:00:00+08:00'));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token', user: { role: 'director' } }));
});
afterAll(() => vi.useRealTimers());

describe('帳單 rows open the clicked contract', () => {
  it('the 2nd contract 登記收款 opens contract 11 with the record action', async () => {
    global.fetch = vi.fn((url) => Promise.resolve(json(String(url).includes('/api/v1/alerts/tuition') ? ROWS : [])));
    const w = mount(TuitionCollectionPage, {
      props: { branchId: 1 },
      global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, teleport: true } },
    });
    await flushPromises();
    await w.get('[data-testid="bill-action-c11"]').trigger('click');
    const ledger = w.findComponent({ name: 'AccountingLedgerModal' });
    expect(ledger.props('show')).toBe(true);
    expect(ledger.props('studentClassId')).toBe(11);
    expect(ledger.props('initialAction')).toBe('record');
    expect(ledger.props('sheetEntry')).toBe(true); // the right-side sheet, for that contract only
    w.unmount();
  });

  const pageWith = async (rows, ledger = {}) => {
    global.fetch = vi.fn((url) => {
      const u = String(url);
      return Promise.resolve(json(u.includes('/api/v1/alerts/tuition') ? rows : u.includes('/accounting/ledger') ? ledger : []));
    });
    const w = mount(TuitionCollectionPage, {
      props: { branchId: 1 },
      global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, teleport: true } },
    });
    await flushPromises();
    return w;
  };
  const PENDING = [row({ id: 12, subject: '國文', payment_status: 'pending_report', latest_payment_report_id: 90 })];
  const LEDGER = { courses: [{ id: 12 }], invoices: [], receipts: [{ report_id: 90, student_class_id: 12, status: 'pending', amount: 1000, payment_date: '2026-11-03', payment_method: 'cash' }] };

  it('確認入帳 on a row opens the quick view of that contract (not the student drawer)', async () => {
    const w = await pageWith(PENDING, LEDGER);
    await w.get('[data-testid="bill-action-c12"]').trigger('click');
    await flushPromises();
    expect(w.findComponent({ name: 'BillQuickView' }).props('open')).toBe(true);
    expect(w.findComponent({ name: 'AccountingLedgerModal' }).props('show')).toBe(false);
    expect(w.find('[data-testid="qv-report"]').exists()).toBe(true);
    w.unmount();
  });

  it('退回 in the row menu opens the quick view reject flow, and a confirm reloads the list with a receipt toast', async () => {
    const w = await pageWith(PENDING, LEDGER);
    const menu = w.get('[data-testid="bill-row-c12"]').findComponent({ name: 'ActionMenu' });
    menu.vm.$emit('select', 'reject');
    await flushPromises();
    expect(w.findComponent({ name: 'BillQuickView' }).props('rejectWanted')).toBe(true);
    expect(w.find('[data-testid="qv-reject-reason"]').exists()).toBe(true);
    expect(w.findComponent({ name: 'AccountingLedgerModal' }).props('show')).toBe(false);
    w.unmount();
  });

  it('after 確認入帳 the quick view closes, the list reloads and the toast offers the receipt', async () => {
    const w = await pageWith(PENDING, LEDGER);
    await w.get('[data-testid="bill-action-c12"]').trigger('click');
    await flushPromises();
    const alertCalls = () => global.fetch.mock.calls.filter(([u]) => String(u).includes('/api/v1/alerts/tuition')).length;
    const before = alertCalls();
    w.findComponent({ name: 'BillQuickView' }).vm.$emit('done', { action: 'confirm', reportId: 90 });
    await flushPromises();
    expect(w.findComponent({ name: 'BillQuickView' }).props('open')).toBe(false);
    expect(alertCalls()).toBe(before + 1);
    const t = toastState.toasts.at(-1);
    expect([t.title, t.action.label]).toEqual(['已確認入帳', '看收據']);
    w.unmount();
  });

  it('save-and-next walks the owing rows in list order and filter, and the last one closes the sheet with a toast', async () => {
    const rows = [
      row({ id: 10, subject: '數學', payable_outstanding: 1000 }),
      row({ id: 11, subject: '英文', payable_outstanding: 2000 }),
      row({ id: 12, student_id: 2, student_name: '另一位', subject: '國文', payable_outstanding: 9000 }),
    ];
    const w = await pageWith(rows);
    const ledger = () => w.findComponent({ name: 'AccountingLedgerModal' });
    await w.get('[data-testid="bill-search"]').setValue('測試'); // filter: only the first student's rows
    await w.get('[data-testid="bill-action-c11"]').trigger('click'); // list order: 11 (2000) before 10 (1000)
    expect(ledger().props('studentClassId')).toBe(11);
    ledger().vm.$emit('save-next');
    await flushPromises();
    expect(ledger().props('studentClassId')).toBe(10); // 12 is filtered out, so it is never visited
    ledger().vm.$emit('save-next');
    await flushPromises();
    expect(ledger().props('show')).toBe(false);
    expect(toastState.toasts.at(-1).title).toBe('已經是最後一筆未繳了');
    w.unmount();
  });

  it('核對 on a 需核對 row opens the student drawer on that contract, not the quick view', async () => {
    const w = await pageWith([row({ id: 13, subject: '自然', payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 500 })]);
    await w.get('[data-testid="bill-action-c13"]').trigger('click');
    expect(w.findComponent({ name: 'BillQuickView' }).props('open')).toBe(false);
    expect(w.findComponent({ name: 'AccountingLedgerModal' }).props('studentClassId')).toBe(13);
    expect(w.findComponent({ name: 'AccountingLedgerModal' }).props('sheetEntry')).toBe(false);
    w.unmount();
  });
});
