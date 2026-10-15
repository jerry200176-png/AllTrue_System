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

const estimate = { rate: 900, session_count: 8, amount: 7200 };
const course = (over = {}) => ({ id: 7, schedule_mode: 'count', paid: false, package_id: null, current_rate_estimate: estimate, ...over });
const bill = (over = {}) => ({ id: 41, student_class_id: 7, status: 'unpaid', total_amount: 8800, paid_amount: 0, calculated_applied_amount: 0, ...over });

describe('issuedBillRateNote', () => {
  it('shows the issued amount next to the current-rate estimate when they differ', () => {
    expect(issuedBillRateNote(course(), [bill()])).toEqual({ invoiceId: 41, issued: 8800, estimate: 7200, rate: 900, count: 8 });
  });

  it('ignores voided bills and other courses when picking the issued bill', () => {
    const rows = [bill({ id: 40, status: 'void', total_amount: 7200 }), bill(), bill({ id: 50, student_class_id: 8 })];
    expect(issuedBillRateNote(course(), rows)?.invoiceId).toBe(41);
  });

  it('is hidden when the amounts are equal', () => {
    expect(issuedBillRateNote(course(), [bill({ total_amount: 7200 })])).toBeNull();
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

  it('is hidden for non-count-mode contracts and when the server sends no estimate', () => {
    expect(issuedBillRateNote(course({ schedule_mode: 'date' }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course({ current_rate_estimate: null }), [bill()])).toBeNull();
  });

  it('is hidden for a course marked paid (G-009), a shared package, a free-gift bill or several open bills', () => {
    expect(issuedBillRateNote(course({ paid: true }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course({ package_id: 3 }), [bill()])).toBeNull();
    expect(issuedBillRateNote(course(), [bill({ zero_amount_reason: 'free_gift', total_amount: 0 })])).toBeNull();
    expect(issuedBillRateNote(course(), [bill(), bill({ id: 42 })])).toBeNull();
  });
});

describe('ContractCard issued-bill note', () => {
  const note = { invoiceId: 41, issued: 8800, estimate: 7200, rate: 900, count: 8 };

  it('shows both amounts and links to the existing billing correction when the host offers it', async () => {
    const w = mount(ContractCard, { props: { course: { id: 7 }, rateNote: note, canCorrect: true } });
    await flushPromises();
    const text = w.find('[data-testid="contract-rate-note"]').text();
    expect(text).toContain('開單時金額 NT$ 8,800 ／ 目前單價試算 NT$ 7,200');
    expect(text).toContain('單價 NT$ 900，8 堂');
    expect(text).toContain('帳單不會跟著單價自動改');
    await w.find('[data-testid="contract-rate-correct"]').trigger('click');
    expect(w.emitted('correct')?.[0]).toEqual([{ id: 7 }]);
  });

  it('has no correction button where the host cannot open it, and no note without a difference', async () => {
    const w = mount(ContractCard, { props: { course: { id: 7 }, rateNote: note } });
    await flushPromises();
    expect(w.find('[data-testid="contract-rate-note"]').exists()).toBe(true);
    expect(w.find('[data-testid="contract-rate-correct"]').exists()).toBe(false);
    const none = mount(ContractCard, { props: { course: { id: 7 } } });
    await flushPromises();
    expect(none.find('[data-testid="contract-rate-note"]').exists()).toBe(false);
  });
});

describe('AccountingLedgerModal issued-bill row hint', () => {
  it('marks only the issued unpaid bill and re-emits the correction link', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    const invoices = [bill({ outstanding_amount: 8800, payments: [] }), bill({ id: 40, status: 'void', total_amount: 7200, payments: [] })];
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200,
      json: async () => ({ summary: {}, scope: { student_class_id: 7 }, courses: [course()], invoices, receipts: [], anomalies: [] }) })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 7, branchId: 1, canCorrect: true }, global: { stubs: { Transition: false } } });
    await flushPromises();
    expect(w.find('[data-testid="ledger-rate-hint-41"]').text()).toContain('目前單價試算 NT$ 7,200');
    expect(w.find('[data-testid="ledger-rate-hint-40"]').exists()).toBe(false);
    await w.find('[data-testid="contract-rate-correct"]').trigger('click');
    expect(w.emitted('billing-correction')?.[0]?.[0]?.id).toBe(7);
    vi.unstubAllGlobals();
  });
});
