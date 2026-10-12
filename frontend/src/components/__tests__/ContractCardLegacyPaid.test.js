// #3894 — 舊制已繳（已標記繳費、沒有系統帳單）不可顯示成「還沒開帳單」，也不可提供開帳單／登記收款。Synthetic.
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

const has = (w, id) => w.find(`[data-testid="${id}"]`).exists();
const LEGACY = '舊制已繳（無新系統帳單）';

describe('ContractCard legacy paid / no invoice', () => {
  it('shows a neutral legacy state, never 還沒開帳單 / 還沒繳費 / 開帳單 / 登記這筆', async () => {
    const w = await mountCard({ course: { id: 9, paid: true }, amounts: null, billDraft: { amount: 1000 } });
    expect(w.get('[data-testid="contract-legacy-paid"]').text()).toBe(LEGACY);
    expect(w.get('[data-testid="contract-last-payment"]').text()).toBe(LEGACY);
    w.findAll('[data-testid="contract-session"]').forEach((r) => expect(r.text()).toContain(LEGACY));
    expect(w.text()).not.toMatch(/還沒開帳單|還沒繳費/);
    expect([has(w, 'contract-issue'), has(w, 'contract-record')]).toEqual([false, false]);
  });

  it('unpaid with no invoice keeps 還沒開帳單 and the actions; invoice-paid stays 已收清', async () => {
    const w = await mountCard({ course: { id: 9, paid: false }, amounts: null, billDraft: { amount: 1000 } });
    expect(w.text()).toContain('還沒開帳單');
    expect(w.get('[data-testid="contract-last-payment"]').text()).toBe('還沒繳費');
    expect([has(w, 'contract-legacy-paid'), has(w, 'contract-issue'), has(w, 'contract-record')]).toEqual([false, true, true]);
    const paid = await mountCard({ course: { id: 9, paid: true }, amounts: { due: 100, received: 100, owed: 0 } });
    expect(paid.get('[data-testid="contract-money"]').text()).toContain('已收清');
    expect(has(paid, 'contract-legacy-paid')).toBe(false);
  });
});
