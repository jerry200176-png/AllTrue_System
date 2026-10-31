// #4114 (Founder Q3): a contract whose only paid evidence is the legacy Paid / package flag (server
// `legacy_paid_flag_only`) reads the neutral 需核對 label in amber: never green 已收, never 未繳, never owed.
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const base = { subject: '數學', schedule_mode: 'count', alert_type: 'low_sessions', payable_status: 'unbilled', remaining_sessions: 1, charge: 5000, paid_amount: 0, outstanding: 0, payable_outstanding: null };
const FLAG_ONLY = { ...base, id: 1, student_id: 1, student_name: '旗標生', payment_status: 'paid', legacy_paid_flag_only: true };
const RECEIVED = { ...base, id: 2, student_id: 2, student_name: '收款生', payment_status: 'paid', legacy_paid_flag_only: false, paid_amount: 5000, last_paid_at: '2026-10-01' };
// a flag-only renewal row that still names a confirmed receipt keeps its 撤銷
const FLAG_WITH_RECEIPT = { ...base, id: 3, student_id: 3, student_name: '收據生', payment_status: 'renew_needed', legacy_paid_flag_only: true, has_confirmed_receipt: true, receipt_report_id: 77 };

const json = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-15T10:00:00+08:00'));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token', user: { role: 'director' } }));
});
afterAll(() => vi.useRealTimers());

describe('legacy paid flag without a payment record (#4114)', () => {
  it('shows 需核對 instead of 已收, keeps the row out of every owed count, and offers no 撤銷 without a receipt', async () => {
    global.fetch = vi.fn((url) => Promise.resolve(json(String(url).includes('/api/v1/alerts/tuition') ? [FLAG_ONLY, RECEIVED, FLAG_WITH_RECEIPT] : [])));
    const w = mount(TuitionCollectionPage, {
      props: { branchId: 1, initialTab: 'all' },
      global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, teleport: true } },
    });
    await flushPromises();

    // F-5: the queue table is gone; the student list and the drawer's card actions carry these rules.
    const badge = (id) => w.find(`[data-testid="sbl-row-${id}"]`).findAll('.at-badge').map((b) => b.text());
    expect(badge(1)).toEqual(['需核對（舊資料標已收，查無收款）']);
    expect(badge(2)).toEqual(['已收']);
    expect(w.find('[data-testid="sbl-summary"]').text()).toContain('本校區到今天未繳 NT$ 0');

    // #4174 review: no receipt stands behind a flag-only row, so there is nothing to 撤銷
    const actions = w.findComponent({ name: 'AccountingLedgerModal' }).props('rowActions');
    expect(actions[1].void).toBe(false);
    expect(actions[2].void).toBe(true);
    expect(actions[3].void).toBe(true);
    w.unmount();
  });
});
