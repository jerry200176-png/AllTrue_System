// In-app #425: the ledger contract card offers 繳費單 like the 帳務中心 list; when it cannot, it is disabled with
// the same one-line reason TuitionDocActions gives for the equivalent list row (no new money rule).
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';
import TuitionDocActions from '../TuitionDocActions.vue';

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
const listReason = (row, canView = true) => {
  const w = mount(TuitionDocActions, { props: { row: { id: 9, payable_status: 'invoiced', ...row }, canView } });
  const r = w.find('[data-testid="tc-doc-slip-reason"]');
  return { disabled: w.get('[data-testid="tc-doc-slip"]').element.disabled, reason: r.exists() ? r.text() : null };
};

describe('ContractCard 繳費單 (in-app #425)', () => {
  it('money owed on an open bill: enabled, and click emits the course', async () => {
    const w = await card({ outstanding: 4400 });
    expect(slip(w)).toEqual(listReason({ payment_status: 'unpaid' }));
    expect(slip(w)).toEqual({ disabled: false, reason: null });
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 9 });
  });

  it.each([
    ['pending report', { outstanding: 4400, pendingReport: { report_id: 1, amount: 4400, date: '' } }, { payment_status: 'pending_report' }],
    ['paid', { course: { id: 9, subject: '數學', paid: true } }, { payment_status: 'paid' }],
    ['no bill yet', {}, { payment_status: undefined }],
    ['legacy flag only', { course: { id: 9, subject: '數學', paid: true }, lastPayment: { legacyUnverified: true } }, { payment_status: 'paid', legacy_paid_without_invoice: true }],
    ['no permission', { outstanding: 4400, canViewDocs: false }, { payment_status: 'unpaid', __noView: true }],
  ])('%s: disabled with the same reason as the 帳務中心 list', async (_l, props, row) => {
    const { __noView, ...r } = row;
    const w = await card(props);
    expect(slip(w)).toEqual(listReason(r, !__noView));
    expect(slip(w).disabled).toBe(true);
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.emitted('slip')).toBeUndefined();
  });

  it('shared-package follower and waived / tutoring cards', async () => {
    expect(slip(await card({ outstanding: 4400, sharedFollower: true }))).toEqual({ disabled: true, reason: '共用方案繳費單請至成員課程開立' });
    expect(slip(await card({ course: { id: 9, subject: '數學', paid: false, waived: true } }))).toBeNull();
    authedFetch.mockResolvedValue(respond({ schedule_mode: 'count', sessions: [], unscheduled_count: 0, class_type: 'tutoring' }));
    expect(slip(await card({}))).toBeNull();
  });
});
