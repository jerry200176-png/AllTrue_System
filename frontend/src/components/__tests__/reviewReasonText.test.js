import { describe, expect, it } from 'vitest';
import { reviewReasonText } from '../../lib/courseMoneyState.js';

describe('reviewReasonText', () => {
  const c = (r) => ({ monthly_payment: { review_reasons: r } });
  it('maps known codes and drops unknown ones', () => {
    expect(reviewReasonText(c(['item_period_outside_contract', 'nope', 'unbilled_month']))).toBe('帳單期間超出合約日期、有上課月份還沒開帳單');
    expect(reviewReasonText(c(['nope']))).toBe('');
    expect(reviewReasonText(c(['amount_discrepancy']))).toBe('帳單金額和堂數算出的金額不同');
  });
  it('is empty without reasons', () => {
    expect(reviewReasonText(c([]))).toBe('');
    expect(reviewReasonText(null)).toBe('');
  });
});
