import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TuitionDocActions from '../TuitionDocActions.vue';

// #3894 — 繳費單／收據固定兩顆入口；synthetic rows only. Per-state outcomes for real API rows: SlipReceiptStateContract.test.js.
const mk = (row, canView = true) => mount(TuitionDocActions, { props: { row: { id: 1, payable_status: 'invoiced', ...row }, canView } });
const t = (w, k) => w.get(`[data-testid="tc-doc-${k}"]`).element.disabled;
const why = (w, k) => (w.find(`[data-testid="tc-doc-${k}-reason"]`).exists() ? w.get(`[data-testid="tc-doc-${k}-reason"]`).text() : null);
const NO_RECEIPT = '收款確認後才有收據', PAID = '已繳清，不用繳費單';

describe('TuitionDocActions', () => {
  it.each([
    ['pending_reconciliation', [false, true, null, NO_RECEIPT]],
    ['pending_report', [true, true, '已回報，等確認入帳', NO_RECEIPT]],
    ['paid', [true, false, PAID, null]],
    ['monthly_due_soon', [true, false, PAID, null]],
    [undefined, [true, true, '還沒開帳單', NO_RECEIPT]],
  ])('%s -> [slip disabled, receipt disabled, slip reason, receipt reason]', (status, expected) => {
    const w = mk({ payment_status: status });
    expect([t(w, 'slip'), t(w, 'receipt'), why(w, 'slip'), why(w, 'receipt')]).toEqual(expected);
  });
  it('no permission disables both with 沒有權限 and never offers the ledger link', () => {
    const w = mk({ payment_status: 'paid', payable_status: 'unbilled' }, false);
    expect([t(w, 'slip'), t(w, 'receipt'), why(w, 'slip'), why(w, 'receipt')]).toEqual([true, true, '沒有權限', '沒有權限']);
    expect(w.find('[data-testid="tc-doc-ledger"]').exists()).toBe(false);
  });
  it('emits only for enabled buttons', async () => {
    const w = mk({ payment_status: 'unpaid' });
    await w.get('[data-testid="tc-doc-slip"]').trigger('click');
    await w.get('[data-testid="tc-doc-receipt"]').trigger('click');
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 1 });
    expect(w.emitted('receipt')).toBeUndefined();
  });
  it('id-less package rows still get unique aria-describedby ids', () => {
    const attrs = () => ['slip', 'receipt'].map((k) => mk({ id: null, payment_status: 'pending_report' }).get(`[data-testid="tc-doc-${k}"]`).attributes('aria-describedby'));
    expect(new Set([...attrs(), ...attrs()]).size).toBe(4);
    expect(mk({ id: null, payment_status: 'monthly_due_soon', payable_status: 'unbilled' }).find('[data-testid="tc-doc-ledger"]').exists()).toBe(false); // no class to scope a ledger
    expect([t(mk({ id: null, payment_status: 'monthly_due_soon' }), 'receipt'), why(mk({ id: null, payment_status: 'paid' }), 'receipt')]).toEqual([true, '共用方案收據請至成員課程查看']); // no class to look a receipt up by
    expect([why(mk({ id: null, payment_status: 'unpaid', payable_status: 'unbilled' }), 'slip'), t(mk({ id: null, invoice_id: 9, payment_status: 'unpaid' }), 'slip')]).toEqual(['共用方案繳費單請至成員課程開立', false]); // no class or invoice to load a slip from
  });
  it.each([{ legacy_paid_without_invoice: true }, { payable_status: 'unbilled' }])('legacy paid %j: slip reason, receipt left to the lookup, ledger link', async (extra) => {
    const w = mk({ payment_status: 'paid', ...extra });
    expect([why(w, 'slip'), t(w, 'receipt')]).toEqual(['已繳清，系統裡沒有帳單', false]);
    await w.get('[data-testid="tc-doc-ledger"]').trigger('click');
    expect(w.emitted('ledger')).toHaveLength(1);
    expect(w.emitted('slip')).toBeUndefined();
  });
  // #3924: monthly-package alert rows come back with id null; the page's `receiptLoading` also starts at null.
  it('a monthly-package row (id null) is disabled with its own reason, not as a stuck "busy" lookup', () => {
    const w = mk({ id: null, payment_status: 'monthly_due_soon' });
    expect([t(w, 'receipt'), why(w, 'receipt')]).toEqual([true, '共用方案收據請至成員課程查看']);
  });
});
