// in-app #428: a trial that carries its payment date never gets an invoice by design. The server derives
// `trial_fee_recorded_by_date` (StudentClass::isTrialFeeRecordedByDate, same rule as legacy_paid_flag_only), and the
// ledger card must not show 需核對 for it. A paid course without that flag (regular, or a trial without a date) still does.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const course = (id, subject, over = {}) => ({ id, subject, schedule_mode: 'count', charge: 500, paid: true, stop: false,
  free_gift_settled: false, waived: false, paid_at: '2026-10-20', package_id: null, trial_fee_recorded_by_date: false, ...over });

async function mountLedger(courses) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
  const payload = { summary: {}, scope: { student_class_id: 1 }, invoices: [], receipts: [], anomalies: [], courses };
  vi.stubGlobal('fetch', vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: false, status: 500, json: async () => ({}) })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
  await flushPromises();
  return w;
}
afterEach(() => vi.unstubAllGlobals());

describe('帳務面板 · 有繳費日期的試聽課不顯示需核對 (in-app #428)', () => {
  it('a dated paid trial reads 已收, not 需核對; a regular paid course without evidence still reads 需核對', async () => {
    const w = await mountLedger([course(1, '數學', { trial_fee_recorded_by_date: true }), course(2, '英文')]);
    const trial = w.find('[data-testid="contract-card-1"]');
    const regular = w.find('[data-testid="contract-card-2"]');
    expect(trial.exists()).toBe(true);
    expect(trial.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(false);
    expect(trial.find('[data-testid="contract-money"]').text()).toContain('已收');
    // #4339 review: the pay line shows the recorded trial payment, never 還沒繳費 beside 已收.
    expect(trial.find('[data-testid="contract-last-payment"]').text()).toBe('繳費日 2026/10/20　NT$ 500');
    expect(regular.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
  });

  it('a package-created trial member (Charge 0, fee on the package) shows the date only, never NT$ 0', async () => {
    const w = await mountLedger([course(1, '數學', { charge: 0, package_id: 7, trial_fee_recorded_by_date: true })]);
    expect(w.find('[data-testid="contract-card-1"] [data-testid="contract-last-payment"]').text()).toBe('繳費日 2026/10/20');
  });

  it('a trial the server did not exempt (e.g. paid without a date) stays 需核對', async () => {
    const w = await mountLedger([course(1, '數學', { paid_at: null, trial_fee_recorded_by_date: false })]);
    expect(w.find('[data-testid="contract-card-1"] [data-testid="contract-legacy-unverified"]').exists()).toBe(true);
  });
});

// #4597: a package-only paid mark (member Paid=0, no PayDate or bill) now arrives as paid=true, so the card offers the actions.
describe('帳務面板 · 只有方案已收標記的共用方案成員 (#4597)', () => {
  it('shows 需核對 with 補登收款 and 改成未繳 on the card when the server sends paid, and none when unpaid', async () => {
    const member = { package_id: 7, in_package: true, paid_at: null, charge: 0 };
    const w = await mountLedger([course(1, '數學', member), course(2, '英文', { ...member, package_id: null, paid: false })]);
    const card = w.find('[data-testid="contract-card-1"]');
    expect(card.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
    expect(card.find('[data-testid="legacy-backfill-open"]').exists()).toBe(true);
    expect(card.find('[data-testid="legacy-clear-open"]').exists()).toBe(true);
    expect(w.find('[data-testid="contract-card-2"] [data-testid="legacy-backfill-open"]').exists()).toBe(false);
  });
});
