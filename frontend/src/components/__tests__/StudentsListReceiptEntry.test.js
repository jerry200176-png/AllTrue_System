import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/StudentsList.vue');
const source = readFileSync(pagePath, 'utf8');

// Mirrors TuitionCollectionReceiptEntry.test.js's contract: StudentsList.vue must
// wire ReceiptModal the same way as the accounting page so a fresh payment
// (unpaid -> paid) can navigate straight into the existing receipt flow without
// a second receipt-creation path.
describe('StudentsList receipt entry paths', () => {
  it('ReceiptModal only receives report-id (payment report id)', () => {
    expect(source).toMatch(/<ReceiptModal[\s\S]*?:report-id="receiptReportId"/);
    expect(source).not.toMatch(/<ReceiptModal[\s\S]*?:receipt-id=/);
    expect(source).not.toMatch(/<ReceiptModal[\s\S]*?:payment-id=/);
  });

  it('post-confirm auto-open uses result.report_id, not a client-generated id', () => {
    expect(source).toMatch(/if\s*\(result\?\.report_id\)\s*\{[\s\S]*openReceiptByReport\(result\.report_id\)/);
  });

  it('openReceiptByReport helper sets receiptReportId from the payment report id', () => {
    expect(source).toContain('function openReceiptByReport(reportId)');
    expect(source).toMatch(/receiptReportId\.value\s*=\s*Number\(reportId\)/);
  });

  it('onPaymentEntryConfirmed refreshes courses before opening the receipt (no stale data)', () => {
    expect(source).toMatch(/const onPaymentEntryConfirmed = async \(result\) => \{[\s\S]*await loadStudentCourses/);
  });

  it('latest payment info panel opens an existing receipt via view-receipt, never creates one', () => {
    expect(source).toMatch(/<LatestPaymentInfoModal[\s\S]*?@view-receipt="openReceiptByReport"/);
  });
});
