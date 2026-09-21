import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  calculateTransactionDiscountPreview,
  normalizeTransactionDiscount,
} from '../../lib/coursePricing.js';

describe('transaction discount preview', () => {
  it('gates every canonical scheduler mount and never sends calculated totals', () => {
    const app = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');
    const students = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
    const courseManagement = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
    const smartCalendar = readFileSync(resolve(__dirname, '../../pages/SmartCalendar.vue'), 'utf8');
    expect(app).toContain(':can-use-transaction-discount="isDirector"');
    expect(students).toContain('canUseTransactionDiscount: { type: Boolean, default: false }');
    expect(students).toContain(':can-use-transaction-discount="props.canUseTransactionDiscount"');
    expect(students).not.toContain(':can-use-transaction-discount="true"');
    expect(courseManagement).toContain(':can-use-transaction-discount="canUseTransactionDiscount"');
    expect(smartCalendar).toContain(':can-use-transaction-discount="canUseTransactionDiscount"');
    expect(students).not.toContain('original_amount: purchaseDiscountPreview');
    expect(courseManagement).not.toContain('original_amount:');
    expect(smartCalendar).not.toContain('final_amount:');
  });

  it('defaults to NONE and preserves the original total', () => {
    expect(calculateTransactionDiscountPreview(1000)).toMatchObject({
      type: 'NONE', discountAmount: 0, finalAmount: 1000, requiresReason: false,
    });
  });

  it('previews fixed, percentage, and 100% discounts with integer totals', () => {
    expect(calculateTransactionDiscountPreview(1001, { type: 'FIXED_AMOUNT', value: '200' })).toMatchObject({ discountAmount: 200, finalAmount: 801 });
    expect(calculateTransactionDiscountPreview(101, { type: 'PERCENTAGE', value: '12.5' })).toMatchObject({ discountAmount: 13, finalAmount: 88 });
    expect(calculateTransactionDiscountPreview(1000, { type: 'PERCENTAGE', value: '100' })).toMatchObject({ discountAmount: 1000, finalAmount: 0 });
  });

  it('does not create a discount payload for an invalid mode', () => {
    expect(normalizeTransactionDiscount({ type: 'coupon', value: 10 })).toEqual({ type: 'NONE', value: '0', reason: '' });
  });

  it('resets stale discount state instead of inheriting it into a new transaction', () => {
    expect(normalizeTransactionDiscount()).toEqual({ type: 'NONE', value: '0', reason: '' });
    expect(calculateTransactionDiscountPreview(800, { type: 'NONE', value: '0', reason: '' }))
      .toMatchObject({ discountAmount: 0, finalAmount: 800, requiresReason: false });
  });

  it('requires a reason for a non-zero discount payload', () => {
    expect(calculateTransactionDiscountPreview(800, { type: 'FIXED_AMOUNT', value: '100', reason: '' }))
      .toMatchObject({ type: 'FIXED_AMOUNT', discountAmount: 100, requiresReason: true });
  });
});
