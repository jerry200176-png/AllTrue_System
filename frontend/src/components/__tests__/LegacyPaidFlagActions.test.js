// Founder GO 2026-10-21 (docs/DIRECTOR_PAYMENT_ALERT_RULES.md §只有舊「已收」標記): the 需核對 card offers
// 已收過，補登收款 and 其實沒收，改成未繳 instead of a dead-end label.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';
import LegacyPaidFlagActions from '../tuition/LegacyPaidFlagActions.vue';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const HELP = '這門課標記已收，但找不到收款紀錄。已經收過就補登收款；其實沒收就改成未繳。';
const q = (id) => document.body.querySelector(`[data-testid="${id}"]`);
const setValue = async (id, value) => {
  const el = q(id).matches('input,select,textarea') ? q(id) : q(id).querySelector('input,select,textarea');
  el.value = value;
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'));
  await flushPromises();
};
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

  it('replaces the dead-end label with the next-step sentence and both actions', async () => {
    const w = await mountCard({});
    expect(w.find('[data-testid="legacy-flag-help"]').text()).toBe(HELP);
    expect(w.find('[data-testid="legacy-backfill-open"]').text()).toBe('已收過，補登收款');
    expect(w.find('[data-testid="legacy-clear-open"]').text()).toBe('其實沒收，改成未繳');
    expect(w.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(false);
  });

  it.each([
    ['a role that cannot resolve', {}, { canResolveLegacy: false }],
    ['a shared package member', { package_id: 3 }, {}],
    ['a monthly contract', { schedule_mode: 'date' }, {}],
    ['a pending report', {}, { pendingReport: { report_id: 1, amount: 100 } }],
  ])('keeps the plain 需核對 label for %s', async (_label, course, props) => {
    const w = await mountCard(course, props);
    expect(w.find('[data-testid="legacy-flag-actions"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
  });

  it('a resolved action asks the ledger to reload', async () => {
    const w = await mountCard({});
    w.findComponent(LegacyPaidFlagActions).vm.$emit('resolved');
    expect(w.emitted('changed')).toHaveLength(1);
  });
});

describe('LegacyPaidFlagActions', () => {
  const mountActions = () => {
    wrapper = mount(LegacyPaidFlagActions, { attachTo: document.body, props: { courseId: 9, contractAmount: 8800 } });
    return wrapper;
  };

  it('補登收款 requires date and method, prefills the contract amount and replays the same key on retry', async () => {
    const w = mountActions();
    await w.find('[data-testid="legacy-backfill-open"]').trigger('click');
    await flushPromises();
    expect(q('legacy-backfill-amount').value).toBe('8800');
    expect(q('legacy-backfill-submit').disabled).toBe(true);
    await setValue('legacy-backfill-date', '2025-01-15');
    expect(q('legacy-backfill-submit').disabled).toBe(true);
    await setValue('legacy-backfill-method', 'cash');
    await setValue('legacy-backfill-amount', '8000');
    expect(q('legacy-backfill-submit').disabled).toBe(false);

    authedFetch.mockResolvedValueOnce(respond({ message: '網路中斷' }, 500));
    q('legacy-backfill-submit').click();
    await flushPromises();
    expect(w.find('[data-testid="legacy-error"]').exists()).toBe(true);
    expect(w.emitted('resolved')).toBeUndefined();

    const [[url, body]] = posted();
    expect(url).toBe('/api/v1/payment-reports/director-record');
    expect(body).toMatchObject({ student_class_id: 9, legacy_flag_backfill: true, payment_date: '2025-01-15', payment_method: 'cash', amount: 8000 });
    expect(body.idempotency_key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  });

  it('改成未繳 needs a reason and posts it to the clear endpoint', async () => {
    const w = mountActions();
    await w.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    expect(q('legacy-clear-submit').disabled).toBe(true);
    await setValue('legacy-clear-reason', '家長說還沒繳');
    authedFetch.mockResolvedValueOnce(respond({ message: '已改成未繳' }));
    q('legacy-clear-submit').click();
    await flushPromises();
    expect(posted()).toEqual([['/api/v1/payment-reports/legacy-flag-clear', { student_class_id: 9, reason: '家長說還沒繳' }]]);
    expect(w.emitted('resolved')).toHaveLength(1);
  });

  it('shows the server refusal sentence', async () => {
    const w = mountActions();
    await w.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    await setValue('legacy-clear-reason', '其實沒收');
    authedFetch.mockResolvedValueOnce(respond({ message: '這門課已有收款紀錄，不是只有舊標記；請重新整理後在學生帳務核對。沒有變更任何資料。', code: 'legacy_flag_has_payment' }, 422));
    q('legacy-clear-submit').click();
    await flushPromises();
    expect(w.find('[data-testid="legacy-error"]').text()).toContain('已有收款紀錄');
    expect(w.emitted('resolved')).toBeUndefined();
  });
});
