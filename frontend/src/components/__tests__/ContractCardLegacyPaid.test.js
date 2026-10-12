// #3894 — 舊制已繳（課程已標記繳費、沒有系統帳單）不可顯示成「還沒開帳單」，
// 也不可提供開帳單／登記收款。Synthetic data only.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));
import ContractCard from '../tuition/ContractCard.vue';

const COVERAGE = {
  student_class_id: 9, subject: '數學', schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31',
  memo: '', unscheduled_count: 0,
  sessions: [
    { class_session_id: 1, date: '2026-09-01', status: 'attended', payment: 'no_invoice' },
    { class_session_id: 2, date: '2026-09-08', status: 'scheduled', payment: 'no_invoice' },
  ],
};
const mountCard = async (props) => {
  authedFetch.mockResolvedValueOnce({ ok: true, status: 200, json: async () => COVERAGE });
  const w = mount(ContractCard, { props });
  await flushPromises();
  return w;
};
beforeEach(() => authedFetch.mockReset());

describe('ContractCard legacy paid / no invoice', () => {
  it('shows a neutral legacy state, never 還沒開帳單 / 開帳單 / 登記這筆', async () => {
    const w = await mountCard({ course: { id: 9, paid: true }, amounts: null, billDraft: { amount: 1000 } });
    expect(w.get('[data-testid="contract-legacy-paid"]').text()).toBe('舊制已繳（無新系統帳單）');
    for (const r of w.findAll('[data-testid="contract-session"]')) {
      expect(r.text()).toContain('舊制已繳（無新系統帳單）');
      expect(r.text()).not.toContain('還沒開帳單');
    }
    expect(w.text()).not.toContain('還沒開帳單');
    expect(w.text()).not.toContain('還沒繳費');
    expect(w.get('[data-testid="contract-last-payment"]').text()).toBe('舊制已繳（無新系統帳單）');
    expect(w.find('[data-testid="contract-issue"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
  });

  it('unpaid course with no invoice keeps 還沒開帳單 and the existing actions', async () => {
    const w = await mountCard({ course: { id: 9, paid: false }, amounts: null, billDraft: { amount: 1000 } });
    expect(w.text()).toContain('還沒開帳單');
    expect(w.get('[data-testid="contract-last-payment"]').text()).toBe('還沒繳費');
    expect(w.find('[data-testid="contract-legacy-paid"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-issue"]').exists()).toBe(true);
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(true);
  });

  it('paid via real invoice stays 已收清', async () => {
    const w = await mountCard({ course: { id: 9, paid: true }, amounts: { due: 100, received: 100, owed: 0 } });
    expect(w.get('[data-testid="contract-money"]').text()).toContain('已收清');
    expect(w.find('[data-testid="contract-legacy-paid"]').exists()).toBe(false);
  });
});
