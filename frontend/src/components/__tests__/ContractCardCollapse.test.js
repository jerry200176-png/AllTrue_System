// #4659 step 5: a contract card is one line until opened; risky actions live in the shared ••• menu with their old guards.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { openCardMenu, menuItem, pickMenu, unfold } from './cardMenu.js';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));
import ContractCard from '../tuition/ContractCard.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const respond = (body) => ({ ok: true, status: 200, json: async () => body });
const SESSIONS = { schedule_mode: 'count', memo: '備註全文', unscheduled_count: 0, sessions: [{ class_session_id: 1, date: '2026-10-06', start_time: '18:00', status: 'scheduled', payment: 'unpaid' }] };
let wrapper;
const card = async (props = {}) => {
  wrapper = mount(ContractCard, { attachTo: document.body, props: { course: { id: 9, subject: '數學', paid: false }, ...props } });
  await flushPromises();
  return wrapper;
};
beforeEach(() => { authedFetch.mockReset(); authedFetch.mockResolvedValue(respond(SESSIONS)); });
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('ContractCard collapsed line', () => {
  it('is one line (title, 剩 N 堂, pill, one button, •••) with the full body hidden', async () => {
    const w = await card({ outstanding: 8000 });
    expect(w.find('[data-testid="contract-toggle"]').attributes('aria-expanded')).toBe('false');
    expect(w.get('[data-testid="contract-left-line"]').text()).toBe('剩 1 堂');
    expect(w.get('[data-testid="contract-pill"]').text()).toBe('未繳 NT$ 8,000');
    for (const id of ['contract-body', 'contract-memo', 'contract-session', 'contract-amounts']) expect(w.find(`[data-testid="${id}"]`).exists()).toBe(false);
  });

  it('opens to the full card (memo, lessons) and the opened course starts open', async () => {
    const w = await card({ outstanding: 8000 });
    await w.get('[data-testid="contract-toggle"]').trigger('click');
    expect(w.get('[data-testid="contract-toggle"]').attributes('aria-expanded')).toBe('true');
    expect(w.get('[data-testid="contract-memo"]').text()).toBe('備註全文');
    expect(w.findAll('[data-testid="contract-session"]')).toHaveLength(1);
    wrapper.unmount();
    const f = await card({ focused: true });
    expect(f.get('[data-testid="contract-toggle"]').attributes('aria-expanded')).toBe('true');
  });

  it.each([
    ['pending report -> 確認入帳', { pendingReport: { report_id: 1, amount: 500 }, outstanding: 500 }, 'contract-confirm'],
    ['owes -> 登記這筆', { outstanding: 800 }, 'contract-record'],
    ['unbilled with a ready draft -> 開帳單', { billDraft: { amount: 4000 } }, 'contract-issue'],
    ['unbilled, no draft -> 登記這筆', {}, 'contract-record'],
  ])('%s', async (_l, props, testid) => {
    const w = await card(props);
    const buttons = ['contract-confirm', 'contract-record', 'contract-issue'].filter((id) => w.find(`[data-testid="${id}"]`).exists());
    expect(buttons).toEqual([testid]);
  });

  it('no primary when nothing is due; 登記這筆 stays reachable in ••• behind a ready draft', async () => {
    const w = await card({ course: { id: 9, subject: '數學', paid: true } });
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    wrapper.unmount();
    const d = await card({ billDraft: { amount: 4000 } });
    await openCardMenu(d);
    expect(menuItem('record')).not.toBeNull();
  });

  it('keeps warnings on the collapsed card', async () => {
    const w = await card({ course: { id: 9, subject: '數學', paid: false, start_date: '2026-10-10', end_date: '2026-10-31', lessons_outside_contract: ['2026-09-29'] } });
    expect(w.get('[data-testid="contract-outside"]').text()).toContain('需核對：9/29');
    wrapper.unmount();
    const l = await card({ course: { id: 9, subject: '數學', paid: true }, lastPayment: { legacyUnverified: true } });
    expect(l.get('[data-testid="contract-legacy-unverified"]').text()).toContain('需核對');
  });
});

describe('ContractCard ••• menu', () => {
  const ids = async (w) => { await openCardMenu(w); return [...document.body.querySelectorAll('.am__menu [data-action]')].map((n) => n.dataset.action); };

  it('director with every right sees the risky items after a divider, in the old order', async () => {
    const w = await card({ outstanding: 100, amounts: { due: 100, received: 50, owed: 100 }, pendingReport: { report_id: 1, amount: 100 }, rowActions: { waive: true, void: true, settle: true }, canViewDocs: true });
    expect(await ids(w)).toEqual(['reject', 'memo', 'waive', 'void', 'settle', 'refund']);
    expect(document.body.querySelectorAll('.am__menu .am__sep')).toHaveLength(1);
  });

  it('a non-director (no rowActions, no docs) gets no risky items and no 繳費單', async () => {
    const w = await card({ outstanding: 100, amounts: { due: 100, received: 0, owed: 100 } });
    expect(await ids(w)).toEqual(['memo']);
  });

  it('settle and void keep their own guards; each emits the same row-action', async () => {
    const w = await card({ rowActions: { settle: true } });
    expect(await ids(w)).toEqual(['memo', 'settle']);
    await pickMenu(w, 'settle');
    expect(w.emitted('row-action')).toEqual([['settle']]);
  });

  it('改備註 opens a collapsed card and its editor', async () => {
    const w = await card();
    await pickMenu(w, 'memo');
    expect(w.find('[data-testid="contract-memo-input"]').exists()).toBe(true);
  });

  it('退費 only for a count contract with money in', async () => {
    const w = await card({ amounts: { due: 100, received: 100, owed: 0 } });
    expect(await ids(w)).toContain('refund');
    wrapper.unmount();
    authedFetch.mockResolvedValue(respond({ ...SESSIONS, schedule_mode: 'date' }));
    const m = await card({ course: { id: 9, subject: '數學', schedule_mode: 'date' }, amounts: { due: 100, received: 100, owed: 0 } });
    expect(await ids(m)).not.toContain('refund');
  });
});

describe('AccountingLedgerModal sections', () => {
  afterEach(() => vi.unstubAllGlobals());
  const ledger = {
    student: { id: 1, name: '測試生' }, summary: {}, scope: {}, anomalies: [], courses: [{ id: 2, subject: '數學', paid: false }, { id: 3, subject: '英文', paid: false }],
    invoices: [{ id: 5, student_class_id: 2, total_amount: 100, calculated_applied_amount: 0, outstanding_amount: 100, status: 'unpaid', payments: [] }],
    receipts: [{ report_id: 7, student_class_id: 2, status: 'confirmed', amount: 100, payment_date: '2026-09-02' }, { report_id: 8, student_class_id: 2, status: 'pending', amount: 100, payment_date: '2026-09-03' }],
    voided_invoice_notes: [{ invoice_id: 9, invoice_no: 'INV-9', course_ref: '', label: '', notes: [{ id: 1, body: 'x', created_at: '2026-09-01' }] }],
  };
  const open = async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    authedFetch.mockResolvedValue(respond(SESSIONS));
    vi.stubGlobal('fetch', vi.fn(async (url) => respond(String(url).includes('monthly-drafts') ? { data: [] } : ledger)));
    wrapper = mount(AccountingLedgerModal, { attachTo: document.body, props: { show: true, studentClassId: 2 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    return wrapper;
  };

  it('starts with the opened contract open, the others one line, and 帳單 / 收款紀錄 / 備註 folded with counts', async () => {
    const w = await open();
    expect(w.get('[data-testid="contract-card-2"] [data-testid="contract-toggle"]').attributes('aria-expanded')).toBe('true');
    expect(w.get('[data-testid="contract-card-3"] [data-testid="contract-toggle"]').attributes('aria-expanded')).toBe('false');
    expect(w.get('[data-testid="ledger-fold-invoices"]').text()).toContain('帳單（1）');
    expect(w.get('[data-testid="ledger-fold-receipts"]').text()).toContain('收款紀錄（2）');
    expect(w.get('[data-testid="ledger-fold-voided"]').text()).toContain('已刪除課程的帳單備註（1）');
    expect(w.find('.ledger-table').exists()).toBe(false);
    expect(w.find('.ledger-receipt').exists()).toBe(false);
    expect(w.find('.ledger-voided-notes').exists()).toBe(false);
    await unfold(w, 'invoices'); await unfold(w, 'receipts'); await unfold(w, 'voided');
    expect(w.find('.ledger-table').exists()).toBe(true);
    expect(w.findAll('.ledger-receipt').length).toBeGreaterThan(0);
    expect(w.find('.ledger-voided-notes').exists()).toBe(true);
  });

  it('opening a receipt (after 確認入帳 or 查看收據) opens 收款紀錄 with it', async () => {
    const w = await open();
    w.findComponent(ContractCard).vm.$emit('confirmed', 7);
    await flushPromises();
    expect(w.get('[data-testid="ledger-fold-receipts"]').attributes('aria-expanded')).toBe('true');
  });

  it('auto-opens 帳單 and counts bills with a warning (差額 / 多收), so the fold never hides them', async () => {
    ledger.invoices = [{ ...ledger.invoices[0], id: 6, amount_discrepancy: true, stored_total_amount: 100, computed_total_amount: 120, period_sessions: 4 }];
    const w = await open();
    expect(w.get('[data-testid="ledger-fold-invoices"]').attributes('aria-expanded')).toBe('true');
    expect(w.get('[data-testid="ledger-fold-warn"]').text()).toBe('需留意 1');
    expect(w.find('[data-testid="ledger-amount-discrepancy-6"]').exists()).toBe(true);
    ledger.invoices = [{ ...ledger.invoices[0], amount_discrepancy: false }];
    wrapper.unmount();
    const q = await open();
    expect(q.get('[data-testid="ledger-fold-invoices"]').attributes('aria-expanded')).toBe('false');
    expect(q.find('[data-testid="ledger-fold-warn"]').exists()).toBe(false);
  });

  it('a hand-opened card stays open after an action reloads the ledger', async () => {
    const w = await open();
    const toggle = () => w.get('[data-testid="contract-card-3"] [data-testid="contract-toggle"]');
    await toggle().trigger('click');
    await pickMenu(w.get('[data-testid="contract-card-3"]'), 'memo');
    await w.get('[data-testid="contract-card-3"] [data-testid="contract-memo-input"]').setValue('新備註');
    await w.get('[data-testid="contract-card-3"] [data-testid="contract-memo-save"]').trigger('click');
    await flushPromises(); await flushPromises();
    expect(toggle().attributes('aria-expanded')).toBe('true');
  });

  it('folds and hand-opened cards reset when the branch changes', async () => {
    const w = await open();
    await unfold(w, 'receipts');
    await w.setProps({ branchId: 2 });
    await flushPromises();
    expect(w.get('[data-testid="ledger-fold-receipts"]').attributes('aria-expanded')).toBe('false');
  });
});

describe('ContractCard accessibility and status', () => {
  it('the toggle is inside a heading, named by the course, and controls the body only while open', async () => {
    const w = await card({ outstanding: 1 });
    const t = w.get('h5 [data-testid="contract-toggle"]');
    expect(t.attributes('aria-label')).toBeUndefined();
    expect(t.text()).toContain('課程');
    expect(t.attributes('aria-controls')).toBeUndefined();
    await t.trigger('click');
    expect(t.attributes('aria-controls')).toBe(w.get('[data-testid="contract-body"]').attributes('id'));
  });

  it.each([
    ['shared follower', { sharedFollower: true, outstanding: 5 }, '共用方案'],
    ['tutoring', { course: { id: 9, subject: '數學', no_payment_obligation: true } }, '輔導課'],
    ['unbilled', {}, '還沒開帳單'],
    ['legacy and owing', { course: { id: 9, subject: '數學', paid: true }, lastPayment: { legacyUnverified: true }, outstanding: 300 }, '需核對 NT$ 300'],
  ])('%s keeps a status pill when collapsed', async (_l, props, text) => {
    const w = await card(props);
    expect(w.get('[data-testid="contract-pill"]').text()).toBe(text);
  });
});
