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
    global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, teleport: true } },
  });
  await flushPromises();
  return wrapper;
}

// F-5: the queue table is gone; the same rules are read off the student list.
const badges = (w, id) => w.find(`[data-testid="sbl-row-${id}"]`).findAll('.at-badge').map((b) => b.text());

describe('金額待確認 rows in the student list (Founder D2)', () => {
  it('marks the row 需核對 and keeps its amount out of the 未繳 total', async () => {
    const w = await mountPage();
    expect(badges(w, 2)).toEqual(['需核對']);
    expect(badges(w, 1)).toEqual(['未繳']);
    const summary = w.find('[data-testid="sbl-summary"]').text();
    expect(summary).toContain('本校區到今天未繳 NT$ 8,800');
    expect(summary).toContain('NT$ 3,400（1 筆，不在未繳內）');
    w.unmount();
  });

  it('keeps a pending parent report on a 金額待確認 bill confirmable, but its amount out of the 未繳 total', async () => {
    const w = await mountPage([CONFIRMED, REPORTED]);
    expect(badges(w, 3)).toEqual(['需核對', '待確認入帳']);
    expect(w.find('[data-testid="sbl-action-3"]').text()).toBe('確認入帳');
    const summary = w.find('[data-testid="sbl-summary"]').text();
    expect(summary).toContain('本校區到今天未繳 NT$ 8,800');
    expect(summary).toContain('NT$ 4,400（1 筆，不在未繳內）');
    w.unmount();
  });
});
