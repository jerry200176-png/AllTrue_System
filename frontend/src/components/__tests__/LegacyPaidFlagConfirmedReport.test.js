// Reproduce the known UI/server contract gap using synthetic ledger evidence.
// Source431's original 422 body and authoritative case binding remain UNKNOWN.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import ContractCard from '../tuition/ContractCard.vue';
import LegacyPaidFlagActions from '../tuition/LegacyPaidFlagActions.vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...args) => authedFetch(...args), getAccessToken: async () => 'fixture-token' }));
const response = (body) => ({ ok: true, status: 200, json: async () => body });
const wrappers = [];
const course = { id: 2, subject: '測試課程', paid: true, charge: 6000, current_contract_charge: 6000, schedule_mode: 'count', class_type: 'regular' };
const receipt = { report_id: 5, student_class_id: 2, status: 'confirmed', amount: 6000, payment_date: '2026-01-01' };
const posted = () => [...authedFetch.mock.calls, ...fetch.mock.calls].filter(([, options]) => options?.method === 'POST');
const mountWith = (component, options) => { const wrapper = mount(component, { attachTo: document.body, ...options }); wrappers.push(wrapper); return wrapper; };
const mountLedger = async (receipts, options = {}) => {
  const body = { scope: {}, student: { id: 1, name: 'Fixture' }, courses: [course], invoices: [], receipts, anomalies: [], ...options.body };
  fetch.mockImplementation(async (url) => response(String(url).includes('monthly-drafts') ? { data: [] } : body));
  const wrapper = mountWith(AccountingLedgerModal, { props: { show: true, studentClassId: 2, ...options.props } });
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  authedFetch.mockReset();
  authedFetch.mockResolvedValue(response({ sessions: [], unscheduled_count: 0 }));
  vi.stubGlobal('fetch', vi.fn());
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture', user: { role: 'director' } }));
});
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('confirmed receipt keeps impossible legacy actions closed', () => {
  it.each([
    ['missing date', { payment_date: null }],
    ['zero amount', { amount: 0 }],
  ])('ledger: %s stays neutral, offers review and sends no mutation', async (_label, fields) => {
    const w = await mountLedger([{ ...receipt, ...fields }]);
    const card = w.find('[data-testid="contract-card-2"]');
    expect(card.find('[data-testid="contract-legacy-unverified"]').exists()).toBe(true);
    expect(card.find('[data-testid="legacy-flag-help"]').text()).toContain('收款紀錄');
    expect(card.find('[data-testid="legacy-backfill-open"]').exists()).toBe(false);
    expect(card.find('[data-testid="legacy-clear-open"]').exists()).toBe(false);
    expect(card.find('[data-testid="contract-last-payment"]').exists()).toBe(false);
    expect(card.text()).not.toContain('已收清');
    expect(posted()).toEqual([]);
  });

  it('blocking legacy actions must not enable regular or batch collection instead', async () => {
    const w = await mountLedger([{ ...receipt, payment_date: null }], { body: { invoices: [
      { id: 1, student_class_id: 2, status: 'unpaid', total_amount: 6000, calculated_applied_amount: 0, outstanding_amount: 6000, payments: [] },
    ] } });
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-sticky-pay-btn"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-amounts"]').text()).toContain('未收 NT$ 6,000');
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(false);
    expect(w.findComponent(PaymentEntryModal).props('show')).toBe(false);
  });

  it('the record shortcut explains existing confirmed evidence without opening a form', async () => {
    const w = await mountLedger([{ ...receipt, payment_date: null }], { props: { initialAction: 'record' } });
    expect(w.text()).toContain('收款紀錄');
    expect(w.text()).not.toContain('再選擇補登收款或改成未繳');
    expect(w.findComponent(PaymentEntryModal).props('show')).toBe(false);
  });

  it('a stale backfill event cannot open a form after confirmed evidence loaded', async () => {
    const w = await mountLedger([{ ...receipt, payment_date: null }]);
    w.findComponent(ContractCard).vm.$emit('legacy-backfill', course);
    await flushPromises();
    expect(w.findComponent(PaymentEntryModal).props('show')).toBe(false);
    expect(posted()).toEqual([]);
  });

  it('a refreshed confirmed report closes a previously opened backfill form', async () => {
    const w = await mountLedger([]);
    await w.find('[data-testid="legacy-backfill-open"]').trigger('click');
    expect(w.findComponent(PaymentEntryModal).props('show')).toBe(true);
    const body = { scope: {}, student: { id: 1, name: 'Fixture' }, courses: [course], invoices: [], receipts: [{ ...receipt, payment_date: null }], anomalies: [] };
    fetch.mockImplementation(async (url) => response(String(url).includes('monthly-drafts') ? { data: [] } : body));
    await w.setProps({ reportId: 5 });
    await flushPromises();
    expect(w.findComponent(PaymentEntryModal).props('show')).toBe(false);
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(false);
    expect(posted()).toEqual([]);
  });

  it.each(['voided', 'rejected'])('a %s report does not block the existing legacy choices', async (status) => {
    const w = await mountLedger([{ ...receipt, status }]);
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(true);
    expect(w.find('[data-testid="legacy-clear-open"]').exists()).toBe(true);
  });

  it('confirmed evidence on another course does not hide this course actions', async () => {
    const w = await mountLedger([{ ...receipt, student_class_id: 99 }]);
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(true);
    expect(w.find('[data-testid="legacy-clear-open"]').exists()).toBe(true);
  });

  it('a confirmed report on a shared-package sibling closes the lead card like the server does', async () => {
    const pkg = { ...course, package_id: 3 };
    const w = await mountLedger([{ ...receipt, student_class_id: 3, payment_date: null }], { body: { courses: [pkg, { ...pkg, id: 3, subject: '同方案' }] } });
    const lead = w.find('[data-testid="contract-card-2"]');
    expect(lead.find('[data-testid="legacy-flag-help"]').text()).toContain('收款紀錄');
    expect(lead.find('[data-testid="legacy-backfill-open"]').exists()).toBe(false);
    expect(lead.find('[data-testid="legacy-clear-open"]').exists()).toBe(false);
  });

  it('a valid receipt retains its date, amount and existing payment display', async () => {
    const w = await mountLedger([receipt]);
    expect(w.find('[data-testid="contract-last-payment"]').text()).toBe('繳費日 2026/01/01　NT$ 6,000');
    expect(w.find('[data-testid="legacy-flag-actions"]').exists()).toBe(false);
  });

  it('ContractCard keeps the legacy-choice gate while confirmed-report controls are suppressed', async () => {
    const w = mountWith(ContractCard, { props: { course, outstanding: 6000, lastPayment: { legacyUnverified: true }, canResolveLegacy: true, hasConfirmedReport: true } });
    await flushPromises();
    expect(w.find('[data-testid="legacy-backfill-open"]').exists()).toBe(false);
    expect(w.find('[data-testid="legacy-clear-open"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    expect(w.emitted('legacy-backfill')).toBeUndefined();
  });

  it('new confirmed evidence closes a previously opened clear dialog without a POST', async () => {
    const w = mountWith(LegacyPaidFlagActions, { props: { courseId: 2 } });
    await w.find('[data-testid="legacy-clear-open"]').trigger('click');
    await flushPromises();
    expect(document.body.querySelector('[data-testid="legacy-clear-submit"]')).toBeTruthy();
    await w.setProps({ hasConfirmedReport: true });
    expect(document.body.querySelector('[data-testid="legacy-clear-submit"]')).toBeNull();
    expect(posted()).toEqual([]);
    expect(w.emitted('resolved')).toBeUndefined();
  });
});
