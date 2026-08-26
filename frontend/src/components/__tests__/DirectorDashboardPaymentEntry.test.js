import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const dashboard = readFileSync(resolve(__dirname, '../../pages/DirectorDashboard.vue'), 'utf8');
const tuition = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('director payment shortcuts', () => {
  it('exposes notification and payment-detail actions from each alert', () => {
    expect(dashboard).toContain('PaymentSlipModal');
    expect(dashboard).toContain('AccountingLedgerModal');
    expect(dashboard).toContain('繳費通知');
    expect(dashboard).toContain('繳費明細');
    expect(dashboard).toContain('openPaymentSlip(student)');
    expect(dashboard).toContain('openPaymentLedger(student)');
  });

  it('keeps notice generation limited to outstanding payment states', () => {
    expect(dashboard).toContain("['unpaid', 'partial', 'pending_report'].includes(student?.payment_status)");
    expect(dashboard).toContain('invoice_id: c.invoice_id || null');
    expect(dashboard).toContain('student_class_id: c.student_class_id || c.id || c.class_id || null');
  });

  it('labels accounting-center ledger actions as payment details', () => {
    expect(tuition).toContain('title="查看學生繳費明細"');
    expect(tuition).toContain('查看繳費明細');
    expect(tuition).toContain('>繳費明細</button>');
  });
});
