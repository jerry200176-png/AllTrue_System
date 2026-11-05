// Billing v3: a row's button opens THAT contract, never the student's first owing one.
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
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
    w.unmount();
  });
});
