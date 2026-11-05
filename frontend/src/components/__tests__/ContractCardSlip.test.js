// In-app #425: the ledger contract card offers 繳費單 with the same rule as the 帳務中心 list (lib/billingDocState.js).
// It is shown only when it opens, or when the reason is something to act on (金額待確認); otherwise the card already
// says why (已收清 / 待核對 / 共用方案) and no disabled button is added.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';
import { openCardMenu, menuItem } from './cardMenu.js';
import { slipAvailability } from '../../lib/billingDocState.js';

const respond = (body) => ({ ok: true, status: 200, json: async () => body });
beforeEach(() => {
  authedFetch.mockReset();
  authedFetch.mockResolvedValue(respond({ schedule_mode: 'count', sessions: [], unscheduled_count: 0, class_type: 'regular' }));
});

async function card(props) {
  const w = mount(ContractCard, { props: { focused: true, course: { id: 9, subject: '數學', paid: false }, canViewDocs: true, ...props } });
  await flushPromises();
  return w;
}
const slip = async (w) => {
  if (!w.find('.am__trigger').exists()) return null;
  await openCardMenu(w);
  const b = menuItem('slip');
  if (!b) return null;
  const r = b.querySelector('.am__reason');
  return { disabled: b.getAttribute('aria-disabled') === 'true', reason: r ? r.textContent : null };
};

describe('ContractCard 繳費單 (in-app #425)', () => {
  it('an outstanding invoice keeps its slip despite an unverified legacy Paid flag', async () => {
    const w = await card({
      course: { id: 9, subject: '數學', paid: true },
      lastPayment: { legacyUnverified: true },
      outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 },
    });
    expect(await slip(w)).toEqual({ disabled: false, reason: null });
    await openCardMenu(w); menuItem('slip').click(); await flushPromises();
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 9 });
    w.unmount();
  });

  it('money owed on an open bill: enabled, and click emits the course', async () => {
    const w = await card({ outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 } });
    expect(await slip(w)).toEqual({ disabled: false, reason: null });
    await openCardMenu(w); menuItem('slip').click(); await flushPromises();
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 9 });
  });

  it('no bill yet: the estimated slip by course is offered, same verdict as the unbilled list row', async () => {
    expect(await slip(await card({}))).toEqual({ disabled: false, reason: null });
    expect(slipAvailability({ id: 9, payment_status: 'unpaid', payable_status: 'unbilled' })).toEqual({ ok: true });
  });

  it('金額待確認 (server verdict): shown disabled with the list reason', async () => {
    const w = await card({ outstanding: 4400, amounts: { due: 4400, received: 0, owed: 4400 }, course: { id: 9, subject: '數學', paid: false, amount_pending_confirmation: true } });
    expect(await slip(w)).toEqual({ disabled: true, reason: slipAvailability({ id: 9, payment_status: 'unpaid', amount_pending_confirmation: true }).reason });
    expect((await slip(w)).reason).toBe('金額需核對，不能產生繳費單');
    await openCardMenu(w); menuItem('slip').click(); await flushPromises();
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
    expect(await slip(await card(props))).toBeNull();
  });

  it('tutoring: no 繳費單 button', async () => {
    authedFetch.mockResolvedValue(respond({ schedule_mode: 'count', sessions: [], unscheduled_count: 0, class_type: 'tutoring' }));
    expect(await slip(await card({}))).toBeNull();
  });

  it('#4765: lessons outside the contract dates show 需核對 and offer neither 登記這筆 nor 繳費單', async () => {
    const w = await card({ course: { id: 9, subject: '數學', paid: false, start_date: '2026-10-10', end_date: '2026-10-31', lessons_outside_contract: ['2026-09-29', '2026-09-30'] } });
    expect(w.get('[data-testid="contract-outside"]').text()).toBe('需核對：9/29、9/30 的課不在合約日期（10/10–10/31）內，請先改合約日期');
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    expect(await slip(w)).toBeNull();
  });
});
