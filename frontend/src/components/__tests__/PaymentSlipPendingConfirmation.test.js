// Founder D2 (#4121 review): a parent slip never carries an amount that is still 金額待確認.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount } from '@vue/test-utils';
import TuitionDocActions from '../TuitionDocActions.vue';
import { canOfferPaymentSlip } from '../../lib/paymentStatus.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const dashboard = readFileSync(resolve(__dirname, '../../pages/DirectorDashboard.vue'), 'utf8');

describe('payment slip on 金額待確認 rows', () => {
  it('canOfferPaymentSlip refuses a pending-confirmation amount, whatever the status', () => {
    expect(canOfferPaymentSlip({ payment_status: 'pending_report', amount_pending_confirmation: true })).toBe(false);
    expect(canOfferPaymentSlip({ payment_status: 'unpaid', amount_pending_confirmation: true })).toBe(false);
    expect(canOfferPaymentSlip({ payment_status: 'pending_report', amount_pending_confirmation: false })).toBe(true);
    expect(canOfferPaymentSlip({ payment_status: 'unpaid' })).toBe(true);
    expect(canOfferPaymentSlip({ payment_status: 'paid' })).toBe(false);
  });

  it('the director dashboard keeps the flag from /alerts/tuition and gates 繳費單 on it', () => {
    expect(dashboard).toContain('amount_pending_confirmation: c.amount_pending_confirmation === true');
    expect(dashboard).toContain('const isPaymentNoticeAvailable = (student) => canOfferPaymentSlip(student);');
    expect(dashboard).toMatch(/v-if="isPaymentNoticeAvailable\(student\)"[^>]*@click="openPaymentSlip\(student\)">繳費單/);
  });

  it('the queue slip button is disabled with the 金額待確認 reason', () => {
    const w = mount(TuitionDocActions, { props: { row: { id: 1, payable_status: 'invoiced', payment_status: 'pending_reconciliation', amount_pending_confirmation: true }, canView: true } });
    expect(w.get('[data-testid="tc-doc-slip"]').element.disabled).toBe(true);
    expect(w.get('[data-testid="tc-doc-slip-reason"]').text()).toBe('金額待確認，請先確認應收金額');
  });
});
