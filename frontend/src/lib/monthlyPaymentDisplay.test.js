import assert from 'node:assert/strict';
import { monthlyPaymentLabel, periodPaymentLabel } from './monthlyPaymentDisplay.js';
assert.equal(monthlyPaymentLabel({ monthly_payment: { billing_period: '2026-09', payment_status: 'unpaid' }, payment_status: 'paid' }), '2026-09 未繳費');
assert.equal(monthlyPaymentLabel({ monthly_payment: { review_required: true }, payment_status: 'paid' }), '付款期間待確認');
assert.equal(monthlyPaymentLabel({ payment_status: 'paid' }), null);
assert.equal(monthlyPaymentLabel({ monthly_payment: { billing_period: '2026-09', payment_status: 'unpaid' }, payment_status: 'pending_report' }), '2026-09 待對帳');
assert.equal(periodPaymentLabel('partial'), '部分繳');
console.log('monthlyPaymentDisplay passed');
