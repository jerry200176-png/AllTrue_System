import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TuitionDocActions from '../TuitionDocActions.vue';

// #3894 — 繳費單／收據固定兩顆入口；synthetic rows only.
const mk = (payment_status, canView = true, id = 1, invoice_id = 500) =>
  mount(TuitionDocActions, { props: { row: { id, payment_status, invoice_id }, canView } });
const state = (w) => ({
  slip: w.get('[data-testid="tc-doc-slip"]').element.disabled,
  receipt: w.get('[data-testid="tc-doc-receipt"]').element.disabled,
  slipReason: w.find('[data-testid="tc-doc-slip-reason"]').exists() ? w.get('[data-testid="tc-doc-slip-reason"]').text() : null,
  receiptReason: w.find('[data-testid="tc-doc-receipt-reason"]').exists() ? w.get('[data-testid="tc-doc-receipt-reason"]').text() : null,
});

describe('TuitionDocActions state matrix', () => {
  it.each([
    ['unpaid', { slip: false, receipt: true, slipReason: null, receiptReason: '收款確認後才有收據' }],
    ['partial', { slip: false, receipt: true, slipReason: null, receiptReason: '收款確認後才有收據' }],
    ['pending_reconciliation', { slip: false, receipt: true, slipReason: null, receiptReason: '收款確認後才有收據' }],
    ['pending_report', { slip: true, receipt: true, slipReason: '已回報，等確認入帳', receiptReason: '收款確認後才有收據' }],
    ['paid', { slip: true, receipt: false, slipReason: '已繳清，不用繳費單', receiptReason: null }],
    ['renew_needed', { slip: true, receipt: false, slipReason: '已繳清，不用繳費單', receiptReason: null }],
    ['monthly_due_soon', { slip: true, receipt: false, slipReason: '已繳清，不用繳費單', receiptReason: null }],
    [undefined, { slip: true, receipt: true, slipReason: '還沒開帳單', receiptReason: '收款確認後才有收據' }],
  ])('%s -> disabled flags and one-line reasons', (status, expected) => {
    expect(state(mk(status))).toEqual(expected);
  });

  it.each(['unpaid', 'paid', undefined])('%s without permission disables both with 沒有權限', (status) => {
    expect(state(mk(status, false))).toEqual({
      slip: true, receipt: true, slipReason: '沒有權限', receiptReason: '沒有權限',
    });
  });

  it('always renders both named actions, even when neither is available', () => {
    const w = mk(undefined, false);
    expect(w.get('[data-testid="tc-doc-slip"]').text()).toContain('繳費單');
    expect(w.get('[data-testid="tc-doc-receipt"]').text()).toContain('收據');
  });

  it('emits the row only for enabled buttons; disabled clicks do nothing', async () => {
    const w = mk('unpaid');
    await w.get('[data-testid="tc-doc-slip"]').trigger('click');
    await w.get('[data-testid="tc-doc-receipt"]').trigger('click');
    expect(w.emitted('slip')).toHaveLength(1);
    expect(w.emitted('slip')[0][0]).toMatchObject({ id: 1 });
    expect(w.emitted('receipt')).toBeUndefined();
  });

  it.each([
    ['flag', { id: 1, payment_status: 'paid', invoice_id: 500, legacy_paid_without_invoice: true }],
    ['paid without invoice', { id: 1, payment_status: 'paid', invoice_id: null }],
  ])('legacy paid, no invoice (%s): both disabled with reasons and a ledger link', async (_n, row) => {
    const w = mount(TuitionDocActions, { props: { row } });
    expect(state(w)).toEqual({
      slip: true, receipt: true,
      slipReason: '已在舊系統繳清，沒有新帳單',
      receiptReason: '舊系統繳費，系統內沒有電子收據；可看繳費明細',
    });
    await w.get('[data-testid="tc-doc-ledger"]').trigger('click');
    expect(w.emitted('ledger')[0][0]).toMatchObject({ id: 1 });
    expect(w.emitted('slip')).toBeUndefined();
  });

  it('no ledger link when not legacy or without permission', () => {
    expect(mk('paid').find('[data-testid="tc-doc-ledger"]').exists()).toBe(false);
    const w = mount(TuitionDocActions, { props: { row: { id: 1, payment_status: 'paid', invoice_id: null }, canView: false } });
    expect(w.find('[data-testid="tc-doc-ledger"]').exists()).toBe(false);
  });

  it('busy disables the receipt button (double-click guard)', () => {
    const w = mount(TuitionDocActions, { props: { row: { id: 2, payment_status: 'paid' }, busy: true } });
    expect(w.get('[data-testid="tc-doc-receipt"]').element.disabled).toBe(true);
  });
});
