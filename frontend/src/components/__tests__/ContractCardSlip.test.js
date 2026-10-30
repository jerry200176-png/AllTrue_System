// In-app #425: the ledger contract card offers 繳費單 with the same rule as the 帳務中心 list (lib/billingDocState.js).
// It is shown only when it opens, or when the reason is something to act on (金額待確認); otherwise the card already
// says why (已收清 / 待核對 / 共用方案) and no disabled button is added.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';
import { slipAvailability } from '../../lib/billingDocState.js';

const respond = (body) => ({ ok: true, status: 200, json: async () => body });
beforeEach(() => {
  authedFetch.mockReset();
  authedFetch.mockResolvedValue(respond({ schedule_mode: 'count', sessions: [], unscheduled_count: 0, class_type: 'regular' }));
});

async function card(props) {
  const w = mount(ContractCard, { props: { course: { id: 9, subject: '數學', paid: false }, canViewDocs: true, ...props } });
  await flushPromises();
  return w;
}
const slip = (w) => {
  const b = w.find('[data-testid="contract-slip"]');
  if (!b.exists()) return null;
  const r = w.find('[data-testid="contract-slip-reason"]');
  return { disabled: b.element.disabled, reason: r.exists() ? r.text() : null };
};

describe('ContractCard 繳費單 (in-app #425)', () => {
  it('an outstanding invoice keeps its slip despite an unverified legacy Paid flag', async () => {
    const w = await card({
      course: { id: 9, subject: '數學', paid: true },
      lastPayment: { legacyUnverified: true },
      outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 },
    });
    expect(slip(w)).toEqual({ disabled: false, reason: null });
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 9 });
    w.unmount();
  });

  it('money owed on an open bill: enabled, and click emits the course', async () => {
    const w = await card({ outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 } });
    expect(slip(w)).toEqual({ disabled: false, reason: null });
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 9 });
  });

  it('no bill yet: the estimated slip by course is offered, same verdict as the unbilled list row', async () => {
    expect(slip(await card({}))).toEqual({ disabled: false, reason: null });
    expect(slipAvailability({ id: 9, payment_status: 'unpaid', payable_status: 'unbilled' })).toEqual({ ok: true });
  });

  it('金額待確認 (server verdict): shown disabled with the list reason', async () => {
    const w = await card({ outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 }, course: { id: 9, subject: '數學', paid: false, amount_pending_confirmation: true } });
    expect(slip(w)).toEqual({ disabled: true, reason: slipAvailability({ id: 9, payment_status: 'unpaid', amount_pending_confirmation: true }).reason });
    expect(slip(w).reason).toBe('還沒開帳單，不能產生繳費單');
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.emitted('slip')).toBeUndefined();
  });

  it.each([
    ['paid', { course: { id: 9, subject: '數學', paid: true } }],
    ['bills all paid', { amounts: { due: 4400, received: 4400, owed: 0 } }],
    ['pending report', { outstanding: 4400, pendingReport: { report_id: 1, amount: 4400, date: '' } }],
    ['legacy flag only', { course: { id: 9, subject: '數學', paid: true }, lastPayment: { legacyUnverified: true } }],
    ['no permission', { outstanding: 4400, canViewDocs: false }],
    ['shared-package follower', { outstanding: 4400, sharedFollower: true }],
    ['waived', { course: { id: 9, subject: '數學', paid: false, waived: true } }],
  ])('%s: no 繳費單 button on the card', async (_l, props) => {
    expect(slip(await card(props))).toBeNull();
  });

  it('tutoring: no 繳費單 button', async () => {
    authedFetch.mockResolvedValue(respond({ schedule_mode: 'count', sessions: [], unscheduled_count: 0, class_type: 'tutoring' }));
    expect(slip(await card({}))).toBeNull();
  });
});
