import { describe, it, expect } from 'vitest';
import { slipAvailability, receiptAvailability } from './billingDocState.js';

// #3894 — 繳費單／收據 availability (rule behind the TuitionDocActions button pair). Per-state outcomes for real API rows: SlipReceiptStateContract.test.js.
const mk = (row) => ({ id: 1, payable_status: 'invoiced', ...row });
const out = (row, canView = true) => { const r = mk(row); const s = slipAvailability(r, canView); const c = receiptAvailability(r, canView); return [!s.ok, !c.ok, s.reason ?? null, c.reason ?? null]; };
const NO_RECEIPT = '收款確認後才有收據', PAID = '已繳清，不用繳費單';

describe('slip / receipt availability', () => {
  it.each([
    ['pending_reconciliation', [false, true, null, NO_RECEIPT]],
    ['pending_report', [true, true, '已回報，等確認入帳', NO_RECEIPT]],
    ['paid', [true, false, PAID, null]],
    ['monthly_due_soon', [true, false, PAID, null]],
    [undefined, [true, true, '還沒開帳單', NO_RECEIPT]],
  ])('%s -> [slip disabled, receipt disabled, slip reason, receipt reason]', (status, expected) => {
    expect(out({ payment_status: status })).toEqual(expected);
  });
  it('no permission disables both with 沒有權限', () => {
    expect(out({ payment_status: 'paid', payable_status: 'unbilled' }, false)).toEqual([true, true, '沒有權限', '沒有權限']);
  });
  it('a 金額待確認 contract never gets a slip (Founder D2)', () => {
    expect(slipAvailability(mk({ payment_status: 'pending_reconciliation', amount_pending_confirmation: true }))).toEqual({ ok: false, reason: '金額需核對，不能產生繳費單' });
  });
  it('package rows (id null) get their own reasons, and a bill id still allows a slip', () => {
    expect(receiptAvailability({ id: null, payment_status: 'monthly_due_soon' }).reason).toBe('共用方案收據請至成員課程查看');
    expect(receiptAvailability({ id: null, payment_status: 'paid' }).reason).toBe('共用方案收據請至成員課程查看');
    expect(slipAvailability({ id: null, payment_status: 'unpaid', payable_status: 'unbilled' }).reason).toBe('共用方案繳費單請至成員課程開立');
    expect(slipAvailability({ id: null, invoice_id: 9, payment_status: 'unpaid' }).ok).toBe(true);
  });
  it.each([{ legacy_paid_without_invoice: true }, { payable_status: 'unbilled' }])('legacy paid %j: slip reason, receipt left to the lookup', (extra) => {
    const row = mk({ payment_status: 'paid', ...extra });
    expect(slipAvailability(row).reason).toBe('已繳清，系統裡沒有帳單');
    expect(receiptAvailability(row).ok).toBe(true);
  });
});
