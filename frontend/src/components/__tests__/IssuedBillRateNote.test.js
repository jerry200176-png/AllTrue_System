// In-app #408 / #4115, #3980 root cause B. Founder Q2 = A: an issued bill keeps its amount when the
// course rate changes; the director sees both numbers side by side (display only, no write).
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { issuedBillRateNote } from '../../lib/issuedBillRateNote.js';

vi.mock('../../lib/authedFetch.js', () => ({
  authedFetch: async () => ({ ok: true, status: 200, json: async () => ({ schedule_mode: 'count', sessions: [], unscheduled_count: 0 }) }),
  getAccessToken: async () => 'tok',
}));
import ContractCard from '../tuition/ContractCard.vue';

const course = (over = {}) => ({ id: 7, schedule_mode: 'count', paid: false, package_id: null, current_contract_charge: 7200, ...over });
const bill = (over = {}) => ({ id: 41, student_class_id: 7, status: 'unpaid', total_amount: 8800, paid_amount: 0, calculated_applied_amount: 0, single_course_items: true, ...over });

describe('issuedBillRateNote', () => {
  it('shows a stale bill after a rate edit (Charge recomputed, bill not)', () => {
    expect(issuedBillRateNote(course(), [bill()])).toEqual({ invoiceId: 41, issued: 8800, current: 7200 });
  });

  it('ignores voided bills and other courses when picking the issued bill', () => {
    const rows = [bill({ id: 40, status: 'void', total_amount: 7200 }), bill(), bill({ id: 50, student_class_id: 8 })];
    expect(issuedBillRateNote(course(), rows)?.invoiceId).toBe(41);
  });

  it('is hidden when the amounts are equal', () => {
    expect(issuedBillRateNote(course(), [bill({ total_amount: 7200 })])).toBeNull();
  });

  it('is hidden for a discounted renewal (Charge is the discounted amount, Rate x count is not)', () => {
    // Rate 1000 x 8 = 8000, renewal billed the discounted final amount 7000 and stored it as Charge.
    expect(issuedBillRateNote(course({ current_contract_charge: 7000 }), [bill({ total_amount: 7000 })])).toBeNull();
  });

  it('is hidden when Charge carries a session_charge delta the bill already includes', () => {
    // Rate 1000 x 8 = 8000 plus a preserved +300 delta (G-009): Charge 8300, billed 8300.
    expect(issuedBillRateNote(course({ current_contract_charge: 8300 }), [bill({ total_amount: 8300 })])).toBeNull();
  });

  it('is hidden for a multi-course or hand-priced bill', () => {
    expect(issuedBillRateNote(course(), [bill({ single_course_items: false })])).toBeNull();
    expect(issuedBillRateNote(course(), [bill({ single_course_items: undefined })])).toBeNull();
  });

  it('is hidden when the bill is paid, partly paid or has money applied', () => {
    expect(issuedBillRateNote(course(), [bill({ status: 'paid', paid_amount: 8800, calculated_applied_amount: 8800 })])).toBeNull();
    expect(issuedBillRateNote(course(), [bill({ status: 'partial', calculated_applied_amount: 1000 })])).toBeNull();
    expect(issuedBillRateNote(course(), [bill({ calculated_applied_amount: 500 })])).toBeNull();
  });

  it('is hidden when the only bill is voided, or there is no bill yet', () => {
    expect(issuedBillRateNote(course(), [bill({ status: 'void' })])).toBeNull();
    expect(issuedBillRateNote(course(), [])).toBeNull();
  });

  it('is hidden when the contract Charge is zero or negative', () => {
    expect(issuedBillRateNote(course({ current_contract_charge: 0 }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course({ current_contract_charge: -1 }), [bill()])).toBeNull();
  });

  it('is hidden for non-count-mode contracts and when the server sends no value', () => {
    expect(issuedBillRateNote(course({ schedule_mode: 'date' }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course({ current_contract_charge: null }), [bill()])).toBeNull();
  });

  it('is hidden for a course marked paid (G-009), a shared package, a free-gift bill or several open bills', () => {
    expect(issuedBillRateNote(course({ paid: true }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course({ package_id: 3 }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course(), [bill({ zero_amount_reason: 'free_gift', total_amount: 0 })])).toBeNull();
    expect(issuedBillRateNote(course(), [bill(), bill({ id: 42 })])).toBeNull();
  });
});

describe('ContractCard issued-bill note', () => {
  const note = { invoiceId: 41, issued: 8800, current: 7200 };

  it('shows both amounts, the recorded void-and-reissue path, and the void link when allowed', async () => {
    const w = mount(ContractCard, { props: { course: { id: 7 }, rateNote: note, canVoid: true } });
    await flushPromises();
    const text = w.find('[data-testid="contract-rate-note"]').text();
    expect(text).toContain('開單時金額 NT$ 8,800 ／ 目前課程金額 NT$ 7,200');
    expect(text).toContain('作廢此帳單（需填原因');
    expect(text).toContain('不會連到作廢的那張');
    expect(text).not.toContain('沒有可留紀錄');
    await w.find('[data-testid="contract-rate-void"]').trigger('click');
    expect(w.emitted('void-bill')?.[0]).toEqual([41]);
  });

  it('has no void button when the bill cannot be voided directly, and no note without a difference', async () => {
    const w = mount(ContractCard, { props: { course: { id: 7 }, rateNote: note } });
    await flushPromises();
    expect(w.find('[data-testid="contract-rate-note"]').exists()).toBe(true);
    expect(w.find('[data-testid="contract-rate-void"]').exists()).toBe(false);
    const none = mount(ContractCard, { props: { course: { id: 7 } } });
    await flushPromises();
    expect(none.find('[data-testid="contract-rate-note"]').exists()).toBe(false);
  });
});

describe('AccountingLedgerModal issued-bill row hint', () => {
  it('marks only the issued unpaid bill and voids it through the existing audited action', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const invoices = [bill({ outstanding_amount: 8800, payments: [], can_direct_void: true }), bill({ id: 40, status: 'void', total_amount: 7200, payments: [] })];
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200,
      json: async () => ({ summary: {}, scope: { student_class_id: 7 }, courses: [course()], invoices, receipts: [], anomalies: [] }) })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 7, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    expect(w.find('[data-testid="ledger-rate-hint-41"]').text()).toContain('目前課程金額 NT$ 7,200');
    expect(w.find('[data-testid="ledger-rate-hint-40"]').exists()).toBe(false);
    vi.unstubAllGlobals();
  });
});
