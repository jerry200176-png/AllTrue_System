// Founder GO 2026-10-21 (docs/DIRECTOR_PAYMENT_ALERT_RULES.md §只有舊「已收」標記): the 需核對 card offers
// 已收過，補登收款 (the one payment form, PaymentEntryModal in legacy-backfill mode) and 其實沒收，改成未繳.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';
import LegacyPaidFlagActions from '../tuition/LegacyPaidFlagActions.vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const HELP = '這門課標記已收，但找不到收款紀錄。已經收過就補登收款；其實沒收就改成未繳。';
const q = (id) => document.body.querySelector(`[data-testid="${id}"]`);
const posted = () => authedFetch.mock.calls.filter((c) => c[1]?.method === 'POST').map((c) => [c[0], JSON.parse(c[1].body)]);

let wrapper;
beforeEach(() => { authedFetch.mockReset(); document.body.innerHTML = ''; });
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('ContractCard legacy-flag actions', () => {
  const mountCard = async (course, props = {}) => {
    authedFetch.mockResolvedValueOnce(respond({ sessions: [], unscheduled_count: 0 }));
    wrapper = mount(ContractCard, { attachTo: document.body, props: {
      course: { id: 9, subject: '數學', schedule_mode: 'count', paid: true, charge: 8800, current_contract_charge: 8800, ...course },
      lastPayment: { legacyUnverified: true }, canResolveLegacy: true, ...props,
    } });
    await flushPromises();
    return wrapper;
  };
  it('adds the next-step sentence and both actions under the 需核對 label', async () => {
    const w = await mountCard({});
    expect(w.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
    expect(w.find('[data-testid="legacy-flag-help"]').text()).toBe(HELP);
    expect(w.find('[data-testid="legacy-backfill-open"]').text()).toBe('已收過，補登收款');
    expect(w.find('[data-testid="legacy-clear-open"]').text()).toBe('其實沒收，改成未繳');
  });
  it('with an open bill the card has one way to take money: 補登收款, never 登記這筆 as well', async () => {
    const w = await mountCard({}, { outstanding: 3000 });
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(true);
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    await w.find('[data-testid="legacy-backfill-open"]').trigger('click');
    expect(w.emitted('legacy-backfill')[0][0].id).toBe(9);
  });
  it.each([
    ['a role that cannot resolve', {}, { canResolveLegacy: false }],
    ['a pending report', {}, { pendingReport: { report_id: 1, amount: 100 } }],
    ['a tutoring course', { class_type: 'tutoring' }, {}],
  ])('keeps the plain 需核對 label for %s', async (_label, course, props) => {
    const w = await mountCard(course, props);
    expect(w.find('[data-testid="legacy-flag-actions"]').exists()).toBe(false);
  });
  // In-app #428 / #438: the label used to be a dead end for monthly and shared-package contracts.
  it.each([
    ['a monthly contract', { schedule_mode: 'date', charge: 3000, current_contract_charge: null }],
    ['the lead card of a shared package', { package_id: 3 }],
    // The ledger sends package_id null for a monthly member and in_package true (AccountingController::ledger).
    ['a monthly contract in a package', { schedule_mode: 'date', package_id: null, in_package: true }],
  ])('offers both actions on %s', async (_label, course) => {
    const w = await mountCard(course);
    expect(w.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(true);
    expect(w.find('[data-testid="legacy-clear-open"]').exists()).toBe(true);
    await w.find('[data-testid="legacy-backfill-open"]').trigger('click');
    expect(w.emitted('legacy-backfill')[0][0].id).toBe(9);
  });
  it('tells a shared package that 改成未繳 clears every member, and a single course nothing extra', async () => {
    const shared = await mountCard({ package_id: 3 });
    await shared.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    expect(q('legacy-shared-note').textContent).toBe('這門課屬於共用方案：方案內所有課程會一起改成未繳。');
    wrapper.unmount();
    document.body.innerHTML = '';
    wrapper.unmount();
    document.body.innerHTML = '';
    const monthlyMember = await mountCard({ schedule_mode: 'date', package_id: null, in_package: true });
    await monthlyMember.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    expect(q('legacy-shared-note')).not.toBeNull();
    wrapper.unmount();
    document.body.innerHTML = '';
    const single = await mountCard({});
    await single.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    expect(q('legacy-clear-reason')).not.toBeNull();
    expect(q('legacy-shared-note')).toBeNull();
  });
  it('a follower card of a shared package points to the lead card instead of offering actions', async () => {
    const w = await mountCard({ package_id: 3 }, { sharedFollower: true, sharedLeadSubject: '英文' });
    expect(w.find('[data-testid="legacy-flag-actions"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-shared-follower"]').text()).toBe('共用方案，請到「英文」卡處理收款');
  });
});

describe('LegacyPaidFlagActions 改成未繳', () => {
  const openClear = async () => {
    wrapper = mount(LegacyPaidFlagActions, { attachTo: document.body, props: { courseId: 9 } });
    await wrapper.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    return wrapper;
  };
  const setReason = async (v) => {
    const el = q('legacy-clear-reason').matches('textarea') ? q('legacy-clear-reason') : q('legacy-clear-reason').querySelector('textarea');
    el.value = v; el.dispatchEvent(new Event('input')); await flushPromises();
  };
  it('asks for a reason inline, then posts it to the clear endpoint', async () => {
    const w = await openClear();
    q('legacy-clear-submit').click();
    await flushPromises();
    expect(q('legacy-error').textContent).toContain('原因');
    expect(posted()).toEqual([]);
    await setReason('家長說還沒繳');
    authedFetch.mockResolvedValueOnce(respond({ message: '已改成未繳' }));
    q('legacy-clear-submit').click();
    await flushPromises();
    expect(posted()).toEqual([['/api/v1/payment-reports/legacy-flag-clear', { student_class_id: 9, reason: '家長說還沒繳' }]]);
    expect(w.emitted('resolved')).toHaveLength(1);
  });
  it('keeps the dialog open with the server refusal sentence', async () => {
    const w = await openClear();
    await setReason('其實沒收');
    authedFetch.mockResolvedValueOnce(respond({ message: '這門課已有收款紀錄，不是只有舊標記；請重新整理後在學生帳務核對。沒有變更任何資料。', code: 'legacy_flag_has_payment' }, 422));
    q('legacy-clear-submit').click();
    await flushPromises();
    expect(q('legacy-error').textContent).toContain('已有收款紀錄');
    expect(w.emitted('resolved')).toBeUndefined();
  });
});

describe('PaymentEntryModal legacy-backfill mode (補登收款)', () => {
  const row = { id: 9, student_name: '王小明', subject: '數學', charge: 8800, payable_status: 'unbilled', payable_amount: null, payable_outstanding: null, invoice_id: null };
  const open = async () => {
    wrapper = mount(PaymentEntryModal, { props: { show: false, row, mode: 'legacy-backfill' } });
    await wrapper.setProps({ show: true });
    await nextTick();
    return wrapper;
  };
  it('is the same form with the contract amount prefilled, no 免費 mark and a 送出即入帳 hint', async () => {
    const w = await open();
    expect(w.find('input[type="number"]').element.value).toBe('8800');
    expect(w.find('[data-testid="pe-legacy-hint"]').text()).toContain('送出即入帳，不需要再確認');
    expect(w.find('[data-testid="pe-free-gift"]').exists()).toBe(false);
    expect(w.find('input[type="date"]').attributes('max')).toBeTruthy();
  });
  it('posts legacy_flag_backfill with one idempotency key that a retry reuses', async () => {
    const w = await open();
    authedFetch.mockResolvedValueOnce(respond({ message: '網路中斷' }, 500));
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(w.emitted('confirmed')).toBeUndefined();
    authedFetch.mockResolvedValueOnce(respond({ status: 'confirmed', replayed: true }));
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(w.emitted('confirmed')).toHaveLength(1);
    const [[url, first], [, retry]] = posted();
    expect(url).toBe('/api/v1/payment-reports/director-record');
    expect(first).toMatchObject({ student_class_id: 9, legacy_flag_backfill: true, amount: 8800, payment_method: 'transfer' });
    expect(first.invoice_id).toBeUndefined();
    expect(first.idempotency_key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(retry.idempotency_key).toBe(first.idempotency_key);
  });
  it('sends the invoice id of the bill shown, so the server cannot pick another (#4367)', async () => {
    wrapper = mount(PaymentEntryModal, { props: { show: false, row: { ...row, invoice_id: 77, payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000 }, mode: 'legacy-backfill' } });
    await wrapper.setProps({ show: true });
    await nextTick();
    authedFetch.mockResolvedValueOnce(respond({ status: 'confirmed' }));
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(posted()[0][1]).toMatchObject({ legacy_flag_backfill: true, invoice_id: 77 });
  });
});
