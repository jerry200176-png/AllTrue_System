// Founder D2 (in-app #394): a stopped review_required contract with an open bill is findable as 金額待確認,
// but never counted in 待處理 / 已結束，未收 or summed into the confirmed money totals.
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const base = { student_name: '測試生', subject: '數學', schedule_mode: 'date', alert_type: 'pending_reconciliation', payable_status: 'invoiced', remaining_sessions: 0, closed_reason: 'settled' };
const CONFIRMED = { ...base, id: 1, student_id: 1, payment_status: 'pending_reconciliation', charge: 8800, payable_amount: 8800, payable_outstanding: 8800, paid_amount: 0, outstanding: 8800, amount_pending_confirmation: false, pending_confirmation_amount: 0 };
const REVIEW = { ...base, id: 2, student_id: 2, payment_status: 'amount_review', charge: 4400, payable_amount: 4400, payable_outstanding: 3400, paid_amount: 1000, outstanding: 3400, open_periods: [{ billing_period: '2026-09', outstanding: 3400, due_date: null }], amount_pending_confirmation: true, pending_confirmation_amount: 3400 };

const REPORTED = { ...base, id: 3, student_id: 3, student_name: '回報生', payment_status: 'pending_report', latest_payment_report_id: 77, charge: 4400, payable_amount: 4400, payable_outstanding: 4400, paid_amount: 0, outstanding: 4400, days_until_settlement: -5, amount_pending_confirmation: true, pending_confirmation_amount: 4400 };

const json = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-15T10:00:00+08:00'));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
});
afterAll(() => vi.useRealTimers());

async function mountPage(alertRows = [CONFIRMED, REVIEW]) {
  global.fetch = vi.fn((url) => Promise.resolve(json(String(url).includes('/api/v1/alerts/tuition') ? alertRows : [])));
  const wrapper = mount(TuitionCollectionPage, {
    props: { branchId: 1, initialTab: 'all' },
    global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, StudentBillingList: true, OperationsQuickStart: true, teleport: true } },
  });
  await flushPromises();
  return wrapper;
}

const tabBadge = (w, label) => w.findAll('.tc-tab').find((t) => t.text().startsWith(label)).find('.tc-tab-badge').text();

describe('金額待確認 rows in the tuition queue (Founder D2)', () => {
  it('lists the row with the pending marker and keeps it out of confirmed counts and totals', async () => {
    const w = await mountPage();

    expect(w.text()).toContain('測試生');
    expect(w.find('[data-testid="tc-amount-review-hint"]').exists()).toBe(true);
    expect(tabBadge(w, '需核對金額')).toBe('1');
    expect(tabBadge(w, '已結束，未收')).toBe('1');
    expect(tabBadge(w, '待處理')).toBe('1');

    const summary = w.find('.tc-summary-disclosure__hint').text();
    expect(summary).toContain('已開帳單未繳 NT$ 8,800');
    expect(summary).toContain('需核對金額 1 筆 NT$ 3,400，未計入未收');
    expect(w.find('[data-testid="tc-pending-review-card"]').text()).toContain('NT$ 3,400');
    // 收款率 = confirmed paid / confirmed billed: the pending row's 1,000 / 4,400 stays out.
    expect(w.find('.tc-rate').text()).toContain('0%');
    w.unmount();
  });

  it('keeps a pending parent report on a 金額待確認 bill confirmable, but its amount out of debt totals and overdue', async () => {
    const w = await mountPage([CONFIRMED, REPORTED]);

    expect(tabBadge(w, '待確認入帳')).toBe('1');
    expect(tabBadge(w, '需核對金額')).toBe('1');
    expect(tabBadge(w, '逾期應收')).toBe('0');
    const row = w.findAll('tr').find((tr) => tr.text().includes('回報生'));
    expect(row.text()).toContain('確認入帳');
    expect(row.find('[data-testid="tc-amount-review-hint"]').exists()).toBe(true);

    const summary = w.find('.tc-summary-disclosure__hint').text();
    expect(summary).toContain('已開帳單未繳 NT$ 8,800');
    expect(summary).toContain('需核對金額 1 筆 NT$ 4,400，未計入未收');
    w.unmount();
  });
});
