// #3991 (Founder 2026-10-13): a NT$0 bill is shown with its explicit 免費／贈送 mark so it can be audited.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import { FREE_GIFT_LABEL, ZERO_REASON_FREE_GIFT } from '../../lib/courseMoneyState.js';

const invoice = (id, over = {}) => ({ id, student_class_id: 1, subject: 'Math', billing_period: '2026-10', total_amount: 0,
  calculated_applied_amount: 0, outstanding_amount: 0, status: 'paid', payments: [], ...over });
afterEach(() => vi.unstubAllGlobals());

describe('學生帳務對帳 · 免費／贈送 badge', () => {
  it('shows the badge only on a bill marked free/gift', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    const invoices = [invoice(51, { zero_amount_reason: ZERO_REASON_FREE_GIFT }), invoice(52)];
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200,
      json: async () => ({ summary: {}, scope: { student_class_id: 1 }, invoices, receipts: [], anomalies: [] }) })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    expect(w.find('[data-testid="ledger-free-gift-51"]').text()).toBe(FREE_GIFT_LABEL);
    expect(w.find('[data-testid="ledger-free-gift-52"]').exists()).toBe(false);
  });
});
