// #4114 (Founder Q3): a contract whose only paid evidence is the legacy Paid / package flag (server
// `legacy_paid_flag_only`) reads the neutral 需核對 label in amber: never green 已收, never 未繳, never owed.
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

const base = { subject: '數學', schedule_mode: 'count', alert_type: 'low_sessions', payable_status: 'unbilled', remaining_sessions: 1, charge: 5000, paid_amount: 0, outstanding: 0, payable_outstanding: null };
const FLAG_ONLY = { ...base, id: 1, student_id: 1, student_name: '旗標生', payment_status: 'paid', legacy_paid_flag_only: true };
const RECEIVED = { ...base, id: 2, student_id: 2, student_name: '收款生', payment_status: 'paid', legacy_paid_flag_only: false, paid_amount: 5000, last_paid_at: '2026-10-01' };

const json = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-15T10:00:00+08:00'));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
});
afterAll(() => vi.useRealTimers());

describe('legacy paid flag without a payment record (#4114)', () => {
  it('shows 需核對 in amber instead of 已收, and keeps the row out of every owed count', async () => {
    global.fetch = vi.fn((url) => Promise.resolve(json(String(url).includes('/api/v1/alerts/tuition') ? [FLAG_ONLY, RECEIVED] : [])));
    const w = mount(TuitionCollectionPage, {
      props: { branchId: 1, initialTab: 'all' },
      global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true, AccountingLedgerModal: true, StudentBillingList: true, OperationsQuickStart: true, teleport: true } },
    });
    await flushPromises();

    const tag = (name) => w.findAll('tr').find((tr) => tr.text().includes(name)).find('.status-tag');
    expect(tag('旗標生').text()).toBe('需核對：舊資料標記已收，缺付款紀錄');
    expect(tag('旗標生').classes()).toContain('st-pending');
    expect(tag('旗標生').classes()).not.toContain('st-paid');
    expect(tag('收款生').text()).toBe('已收');
    expect(tag('收款生').classes()).toContain('st-paid');
    const badge = (label) => w.findAll('.tc-tab').find((t) => t.text().startsWith(label)).find('.tc-tab-badge').text();
    expect(badge('待處理')).toBe('0');
    w.unmount();
  });
});
