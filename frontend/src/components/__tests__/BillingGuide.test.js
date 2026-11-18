import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../composables/useConfirmDialog', () => ({ askConfirm: vi.fn(() => Promise.resolve(true)) }));
vi.mock('../../lib/receiptImage.js', () => ({ receiptImageBlob: vi.fn(() => Promise.resolve(new Blob(['x'], { type: 'image/png' }))) }));

import BillingGuide from '../tuition/BillingGuide.vue';
import { askConfirm } from '../../composables/useConfirmDialog';
import { receiptImageBlob } from '../../lib/receiptImage.js';
import { billRows } from '../../lib/billRows.js';

const T = '2026-11-18';
const row = (o) => ({ id: 7, student_id: 1, student_name: '學生甲', subject: '數學', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid', payable_status: 'invoiced', payable_invoice_id: 9, payable_amount: 6000, payable_outstanding: 6000, course_start_date: '2026-09-01', course_end_date: '2027-01-31', billing_period_start: '2026-11-01', billing_period_end: '2026-11-30', ...o });
const SLIP = { invoice_id: 9, student_name: '學生甲', campus_name: '大安', status: 'unpaid', remaining: 6000, total_amount: 6000, paid_amount: 0, due_date: '2026-11-30', items: [{ description: '數學', amount: 6000 }] };
const RECEIPT = { receipt_no: 'R-000055', student_name: '學生甲', campus_name: '大安', amount: 3000, payment_date: '2026-11-18', payment_method: 'cash', subject: '數學', schedule_mode: 'count' };
const invoice = (o = {}) => ({ id: 9, student_class_id: 7, status: 'unpaid', total_amount: 6000, calculated_applied_amount: 0, outstanding_amount: 6000, period_start: '2026-11-01', period_end: '2026-11-30', ...o });
const json = (body, ok = true, status = ok ? 200 : 422) => ({ ok, status, json: () => Promise.resolve(body) });

const EntryStub = defineComponent({ name: 'PaymentEntryModal', props: ['show', 'row', 'placement'], emits: ['close', 'confirmed', 'conflict'], setup: (p) => () => (p.show ? h('div', { 'data-testid': 'entry-open' }) : null) });

let calls; let ledger; let confirmAnswer;
const writes = () => calls.filter((c) => c[1] !== 'GET');
const hits = (part) => calls.filter((c) => c[0].includes(part));
beforeEach(() => {
  calls = [];
  ledger = { courses: [{ id: 7 }], invoices: [invoice()], receipts: [] };
  confirmAnswer = json({ message: '已確認入帳', report_id: 55, payment_id: 1, invoice_id: 9 });
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  Object.assign(navigator, { clipboard: { writeText: vi.fn(() => Promise.resolve()) } });
  URL.createObjectURL = vi.fn(() => 'blob:x'); URL.revokeObjectURL = vi.fn();
  HTMLAnchorElement.prototype.click = vi.fn();
  global.fetch = vi.fn((url, init = {}) => {
    const u = String(url);
    calls.push([u, init.method || 'GET']);
    if (u.includes('/accounting/ledger')) return Promise.resolve(json(structuredClone(ledger)));
    if (u.includes('/slip-data') || u.includes('/tuition-slip/')) return Promise.resolve(json(SLIP));
    if (u.endsWith('/receipt')) return Promise.resolve(json(RECEIPT));
    if (u.includes('/confirm')) return Promise.resolve(confirmAnswer);
    if (u.includes('/sessions')) return Promise.resolve(json({ sessions: [{ class_session_id: 1, date: '2026-11-04', start_time: '18:00', status: 'attended' }] }));
    return Promise.resolve(json({}));
  });
});

async function mountGuide(rows, extra = {}) {
  const w = mount(BillingGuide, {
    props: { bills: billRows(rows, T), branchId: 1, canDocs: true, ...extra },
    global: { stubs: { PaymentEntryModal: EntryStub, BillingDocument: { template: '<div data-testid="doc-stub" />' }, teleport: true } },
  });
  await flushPromises();
  return w;
}
// The page's reload: the next /alerts/tuition answer replaces the rows.
const reloadTo = (w, rows) => vi.fn(async () => { await w.setProps({ bills: billRows(rows, T) }); });

// The list's own order: the larger amount first.
const TWO = [row(), row({ id: 8, student_name: '學生乙', payable_invoice_id: 10, payable_outstanding: 1000 })];
const title = (w) => w.get('[data-testid="bg-title"]').text();

describe('BillingGuide', () => {
  it('shows one bill with the course period and the bill period on separate lines, and says when data is missing', async () => {
    const w = await mountGuide([row({ schedule_mode: 'date', remaining_sessions: null, due_date: null })]);
    const facts = w.get('[data-testid="bg-facts"]').text();
    expect(facts).toContain('課程期間2026-09-01 ~ 2027-01-31');
    expect(facts).toContain('本次帳單計費期間2026-11-01 ~ 2026-11-30');
    expect(facts).toContain('計費方式月結制');
    expect(facts).toContain('繳費期限沒有資料');
    expect(w.get('[data-testid="bg-money"]').text()).toMatch(/應收NT\$ 6,000已收NT\$ 0未繳NT\$ 6,000/);
    expect(w.get('[data-testid="bg-progress"]').text()).toBe('第 1 筆／共 1 筆');
  });

  it('a bill nobody paid stays 未繳: moving on writes nothing', async () => {
    const w = await mountGuide(TWO);
    expect(title(w)).toContain('學生甲');
    await w.get('[data-testid="bg-next"]').trigger('click');
    await flushPromises();
    expect(writes()).toEqual([]);
    expect(title(w)).toContain('學生乙');
    expect(w.get('[data-testid="bg-progress"]').text()).toBe('第 2 筆／共 2 筆');
  });

  it('稍後處理 comes back after the others; 略過 does not', async () => {
    const w = await mountGuide(TWO);
    await w.get('[data-testid="bg-later"]').trigger('click');
    await flushPromises();
    expect(title(w)).toContain('學生乙');
    await w.get('[data-testid="bg-skip"]').trigger('click');
    await flushPromises();
    expect(title(w)).toContain('學生甲');
    await w.get('[data-testid="bg-skip"]').trigger('click');
    await flushPromises();
    expect(w.find('[data-testid="bg-finished"]').exists()).toBe(true);
  });

  it('gives the slip of that bill on the same screen: copy text, preview, download', async () => {
    const w = await mountGuide([row()]);
    expect(hits('/api/v1/invoices/9/slip-data')).toHaveLength(1);
    await w.get('[data-testid="bg-doc-copy"]').trigger('click');
    await flushPromises();
    const text = navigator.clipboard.writeText.mock.calls[0][0];
    expect(text).toContain('帳單編號 #9');
    expect(text).toContain('學生甲');
    await w.get('[data-testid="bg-doc-download"]').trigger('click');
    await flushPromises();
    expect(w.find('[data-testid="bg-doc"]').exists()).toBe(true);
    expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1);
    expect(writes()).toEqual([]);
  });

  it('gives no slip while the amount needs checking; it says why and points at the existing entry', async () => {
    const w = await mountGuide([row({ payment_status: 'amount_review', amount_pending_confirmation: true, review_reasons: ['amount_discrepancy'] })]);
    expect(hits('slip')).toEqual([]);
    expect(w.get('[data-testid="bg-slip-reason"]').text()).toContain('金額需核對，不能產生繳費單');
    expect(w.get('[data-testid="bg-money"]').text()).toContain('金額需核對');
    await w.get('[data-testid="bg-review"]').trigger('click');
    expect(w.emitted('ledger')[0][1]).toBeNull();
  });

  it('a contract with no bill yet says so and opens the existing 開帳單 entry', async () => {
    const w = await mountGuide([row({ schedule_mode: 'date', remaining_sessions: null, payable_status: 'unbilled', payable_invoice_id: null, payable_amount: null, payable_outstanding: null, estimated_amount: 7200, billing_period_start: null, billing_period_end: null, payment_status: 'monthly_due_soon' })]);
    expect(w.get('[data-testid="bg-facts"]').text()).toContain('本次帳單計費期間還沒開帳單');
    expect(w.get('[data-testid="bg-money"]').text()).toContain('預估NT$ 7,200');
    await w.get('[data-testid="bg-issue"]').trigger('click');
    expect(w.emitted('ledger')[0][1]).toBe('issue');
  });

  it('登記 → 現在確認入帳 → the receipt of that payment; a part payment shows this payment and what is still 未繳', async () => {
    const w = await mountGuide([row()]);
    const pending = [row({ payment_status: 'pending_report', latest_payment_report_id: 55 })];
    await w.setProps({ reload: reloadTo(w, pending) });
    await w.get('[data-testid="bg-record"]').trigger('click');
    expect(w.find('[data-testid="entry-open"]').exists()).toBe(true);
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 3000, payment_date: '2026-11-18', payment_method: 'cash' }];
    w.getComponent(EntryStub).vm.$emit('confirmed', { report_id: 55, status: 'pending' });
    await flushPromises();
    expect(w.get('[data-testid="bg-notice"]').text()).toContain('還沒確認入帳');
    expect(w.get('[data-testid="bg-report"]').text()).toContain('NT$ 3,000');
    expect(w.get('[data-testid="bg-confirm"]').text()).toBe('現在確認入帳');
    expect(writes()).toEqual([]); // the guide itself has written nothing yet

    await w.setProps({ reload: reloadTo(w, [row({ payment_status: 'partial', paid_amount: 3000, payable_outstanding: 3000, has_confirmed_receipt: true, receipt_report_id: 55 })]) });
    ledger.receipts = [{ ...ledger.receipts[0], status: 'confirmed' }];
    ledger.invoices = [invoice({ status: 'partial', calculated_applied_amount: 3000, outstanding_amount: 3000 })];
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(writes()).toEqual([['/api/v1/payment-reports/55/confirm', 'PUT']]);
    expect(w.get('[data-testid="bg-step"]').text()).toContain('本次實收 NT$ 3,000');
    expect(w.get('[data-testid="bg-remaining"]').text()).toContain('未繳 NT$ 3,000');

    // Copying and downloading again reads nothing new and writes nothing.
    await w.get('[data-testid="bg-doc-copy"]').trigger('click');
    await w.get('[data-testid="bg-doc-download"]').trigger('click');
    await flushPromises();
    await w.get('[data-testid="bg-doc-download"]').trigger('click');
    await flushPromises();
    expect(navigator.clipboard.writeText.mock.calls[0][0]).toContain('R-000055');
    expect(hits('/payment-reports/55/receipt')).toHaveLength(1);
    expect(writes()).toHaveLength(1);
    expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(2);
  });

  it('a second 登記 the server refused (pending_report_exists) carries on with the existing report', async () => {
    const w = await mountGuide([row()]);
    await w.setProps({ reload: reloadTo(w, [row({ payment_status: 'pending_report', latest_payment_report_id: 55 })]) });
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'transfer' }];
    await w.get('[data-testid="bg-record"]').trigger('click');
    w.getComponent(EntryStub).vm.$emit('conflict', { code: 'pending_report_exists', report_id: 55 });
    await flushPromises();
    expect(w.find('[data-testid="entry-open"]').exists()).toBe(false);
    expect(w.get('[data-testid="bg-notice"]').text()).toContain('沒有重複登記');
    expect(w.find('[data-testid="bg-confirm"]').exists()).toBe(true);
    expect(writes()).toEqual([]);
  });

  it('a 確認入帳 resend the server refused shows the receipt of the payment already made, not a second payment', async () => {
    const pending = [row({ payment_status: 'pending_report', latest_payment_report_id: 55 })];
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide(pending);
    await w.setProps({ reload: vi.fn(async () => { ledger.receipts = [{ ...ledger.receipts[0], status: 'confirmed' }]; await w.setProps({ bills: [] }); }) });
    confirmAnswer = json({ message: '此回報已處理過' }, false);
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(hits('/confirm')).toHaveLength(1);
    expect(hits('/payment-reports/55/receipt')).toHaveLength(1);
    expect(w.find('[data-testid="bg-error"]').exists()).toBe(false);
    expect(w.get('[data-testid="bg-step"]').text()).toContain('已確認入帳');
  });

  it('a refused 確認入帳 that did not go through shows the reason and no receipt', async () => {
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 9000, payment_method: 'cash' }];
    const w = await mountGuide([row({ payment_status: 'pending_report', latest_payment_report_id: 55 })]);
    confirmAnswer = json({ message: '金額超過帳單未收餘額', code: 'amount_exceeds_outstanding' }, false);
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(w.get('[data-testid="bg-error"]').text()).toContain('金額超過');
    expect(hits('/receipt')).toEqual([]);
  });

  it('after a full payment the screen stays on the bill that was paid, not the contract\'s next unpaid bill', async () => {
    const oct = { billing_period_start: '2026-10-01', billing_period_end: '2026-10-31' };
    ledger.invoices = [invoice({ ...{ period_start: '2026-10-01', period_end: '2026-10-31' } }), invoice({ id: 10 })];
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide([row({ ...oct, payment_status: 'pending_report', latest_payment_report_id: 55 })]);
    // The server's row moves on to November (invoice 10) once October is paid in full.
    await w.setProps({ reload: vi.fn(async () => {
      ledger.receipts = [{ ...ledger.receipts[0], status: 'confirmed' }];
      ledger.invoices = [invoice({ status: 'paid', calculated_applied_amount: 6000, outstanding_amount: 0, period_start: '2026-10-01', period_end: '2026-10-31' }), invoice({ id: 10 })];
      await w.setProps({ bills: billRows([row({ payable_invoice_id: 10 })], T) });
    }) });
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(w.get('[data-testid="bg-facts"]').text()).toContain('本次帳單計費期間2026-10-01 ~ 2026-10-31');
    expect(w.get('[data-testid="bg-money"]').text()).toMatch(/應收NT\$ 6,000已收NT\$ 6,000未繳NT\$ 0/);
    expect(w.find('[data-testid="bg-remaining"]').exists()).toBe(false);
    expect(hits('slip')).toEqual([]);
  });

  it('two bills confirmed one after the other each show their own receipt', async () => {
    const pend = (id, report) => row({ id, payable_invoice_id: id + 2, payment_status: 'pending_report', latest_payment_report_id: report });
    ledger.courses = [{ id: 7 }, { id: 8 }];
    ledger.invoices = [invoice(), invoice({ id: 10, student_class_id: 8 })];
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }, { report_id: 56, student_class_id: 8, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide([pend(7, 55), pend(8, 56)]);
    const settle = (left) => vi.fn(async () => { await w.setProps({ bills: billRows(left, T) }); });
    for (const [report, left] of [[55, [pend(8, 56)]], [56, []]]) {
      confirmAnswer = json({ message: '已確認入帳', report_id: report, payment_id: 1, invoice_id: report === 55 ? 9 : 10 });
      await w.setProps({ reload: settle(left) });
      await w.get('[data-testid="bg-confirm"]').trigger('click');
      await flushPromises();
      expect(w.get('[data-testid="bg-step"]').text()).toContain('已確認入帳');
      expect(hits(`/payment-reports/${report}/receipt`)).toHaveLength(1);
      expect(w.find('[data-testid="bg-doc-copy"]').exists()).toBe(true);
      await w.get('[data-testid="bg-next"]').trigger('click');
      await flushPromises();
    }
    expect(w.find('[data-testid="bg-finished"]').exists()).toBe(true);
    expect(writes().map((c) => c[0])).toEqual(['/api/v1/payment-reports/55/confirm', '/api/v1/payment-reports/56/confirm']);
  });

  it('a report confirmed on a contract with no bill yet shows the bill the confirm issued, not 預估', async () => {
    ledger.invoices = [];
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide([row({ payable_status: 'unbilled', payable_invoice_id: null, payable_amount: null, payable_outstanding: null, estimated_amount: 6000, payment_status: 'pending_report', latest_payment_report_id: 55 })]);
    await w.setProps({ reload: vi.fn(async () => { ledger.invoices = [invoice({ status: 'paid', calculated_applied_amount: 6000, outstanding_amount: 0 })]; await w.setProps({ bills: [] }); }) });
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    const money = w.get('[data-testid="bg-money"]').text();
    expect(money).toMatch(/應收NT\$ 6,000已收NT\$ 6,000未繳NT\$ 0/);
    expect(money).not.toContain('預估');
  });

  it('never offers the previous bill\'s slip while the next bill is still loading', async () => {
    const w = await mountGuide(TWO);
    expect(w.find('[data-testid="bg-doc-copy"]').exists()).toBe(true);
    let release;
    const inner = global.fetch;
    global.fetch = vi.fn((url, init) => (String(url).includes('/accounting/ledger') ? new Promise((r) => { release = () => r(json(structuredClone(ledger))); }) : inner(url, init)));
    await w.get('[data-testid="bg-next"]').trigger('click');
    await nextTick();
    expect(title(w)).toContain('學生乙');
    expect(w.find('[data-testid="bg-doc-copy"]').exists()).toBe(false);
    release();
    await flushPromises();
    expect(hits('/api/v1/invoices/10/slip-data')).toHaveLength(1);
  });

  it('rereads the bill when the page refreshed its rows, even when the state did not change', async () => {
    const w = await mountGuide([row()]);
    ledger.invoices = [invoice({ status: 'partial', calculated_applied_amount: 2000, outstanding_amount: 4000 })];
    await w.setProps({ bills: billRows([row({ payment_status: 'partial', paid_amount: 2000, payable_outstanding: 4000 })], T) });
    await flushPromises();
    expect(w.get('[data-testid="bg-money"]').text()).toContain('未繳NT$ 4,000');
    expect(hits('/slip-data')).toHaveLength(2);
  });

  it('a paid count contract that needs renewing goes to the renewal flow, not 開帳單', async () => {
    const w = await mountGuide([row({ payment_status: 'renew_needed', payable_outstanding: 0, remaining_sessions: 1 })]);
    expect(w.find('[data-testid="bg-issue"]').exists()).toBe(false);
    await w.get('[data-testid="bg-renew"]').trigger('click');
    expect(w.emitted('renew')[0][0].class_id).toBe(7);
  });

  it('reads a count contract\'s payment deadline from its bill', async () => {
    ledger.invoices = [invoice({ due_date: '2026-11-25' })];
    const w = await mountGuide([row({ due_date: null })]);
    expect(w.get('[data-testid="bg-facts"]').text()).toContain('繳費期限2026-11-25');
  });

  it('names the downloaded file after the document it captured and blocks moving on meanwhile', async () => {
    const w = await mountGuide(TWO);
    let finish;
    receiptImageBlob.mockImplementationOnce(() => new Promise((r) => { finish = () => r(new Blob(['x'], { type: 'image/png' })); }));
    const names = [];
    HTMLAnchorElement.prototype.click = vi.fn(function click() { names.push(this.download); });
    await w.get('[data-testid="bg-doc-download"]').trigger('click');
    await flushPromises();
    expect(w.get('[data-testid="bg-next"]').attributes('disabled')).toBeDefined();
    finish();
    await flushPromises();
    expect(names).toEqual(['繳費單_學生甲_9.png']);
    expect(w.get('[data-testid="bg-next"]').attributes('disabled')).toBeUndefined();
  });

  it('a refused 確認入帳 whose report was 退回 elsewhere brings the slip back', async () => {
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide([row({ payment_status: 'pending_report', latest_payment_report_id: 55 })]);
    await w.setProps({ reload: vi.fn(async () => { ledger.receipts = []; await w.setProps({ bills: billRows([row()], T) }); }) });
    confirmAnswer = json({ message: '此回報已處理過' }, false);
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(w.get('[data-testid="bg-error"]').text()).toContain('已處理過');
    expect(w.find('[data-testid="bg-doc-copy"]').exists()).toBe(true);
  });

  it('cancelling the 確認入帳 question writes nothing', async () => {
    ledger.receipts = [{ report_id: 55, student_class_id: 7, status: 'pending', amount: 6000, payment_method: 'cash' }];
    const w = await mountGuide([row({ payment_status: 'pending_report', latest_payment_report_id: 55 })]);
    askConfirm.mockResolvedValueOnce(false);
    await w.get('[data-testid="bg-confirm"]').trigger('click');
    await flushPromises();
    expect(writes()).toEqual([]);
    expect(w.find('[data-testid="bg-confirm"]').exists()).toBe(true);
  });

  it('says so when the reread after 登記 failed, and a 免費／贈送 登記 repeats the server message', async () => {
    const w = await mountGuide([row()]);
    await w.setProps({ reload: vi.fn(async () => false) });
    await w.get('[data-testid="bg-record"]').trigger('click');
    w.getComponent(EntryStub).vm.$emit('confirmed', { report_id: null, message: '已記為免費／贈送' });
    await flushPromises();
    expect(w.get('[data-testid="bg-notice"]').text()).toBe('已記為免費／贈送');
    expect(w.get('[data-testid="bg-reload-failed"]').text()).toContain('重新載入失敗');
  });

  it('says a bill that left the 要處理 list is no longer there, without claiming it was paid', async () => {
    const w = await mountGuide([row()]);
    await w.setProps({ bills: billRows([row({ payment_status: 'paid', payable_outstanding: 0 })], T) });
    await nextTick();
    expect(w.find('[data-testid="bg-gone"]').exists()).toBe(true);
    expect(w.get('[data-testid="bg-gone"]').text()).not.toContain('已處理');
    expect(w.get('[data-testid="bg-state"]').text()).toBe('不在要處理');
    expect(w.find('[data-testid="bg-record"]').exists()).toBe(false);
  });
});
