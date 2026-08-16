import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/TuitionCollectionPage.vue');
const source = readFileSync(pagePath, 'utf8');

describe('TuitionCollectionPage receipt entry paths', () => {
  it('ReceiptModal only receives report-id (payment report id)', () => {
    expect(source).toMatch(/<ReceiptModal[\s\S]*?:report-id="receiptReportId"/);
    expect(source).not.toMatch(/<ReceiptModal[\s\S]*?:receipt-id=/);
    expect(source).not.toMatch(/<ReceiptModal[\s\S]*?:payment-id=/);
  });

  it('list receipt button opens via payment report id helper', () => {
    expect(source).toContain('openReceiptByReport(row.report_id)');
    expect(source).toContain('function openReceiptByReport(reportId)');
    expect(source).toMatch(/receiptReportId\.value\s*=\s*Number\(reportId\)/);
  });

  it('accounting confirm auto-open uses latest_payment_report_id', () => {
    expect(source).toMatch(/receiptReportId\.value\s*=\s*row\.latest_payment_report_id/);
  });

  it('uses 待對帳 on collection tabs and shows a batch-select hint', () => {
    expect(source).toContain("{ key: 'pending_report', label: '待對帳' }");
    expect(source).toContain("pending_report:   { label: '待對帳'");
    expect(source).toContain('勾選最左欄後才會出現批次回報或確認列。');
  });

  it('admin reported-paid path does not auto-open a receipt', () => {
    expect(source).toContain('已送出待對帳，請會計確認入帳後才會開收據');
    expect(source).not.toMatch(/if\s*\(result\?\.report_id\)\s*\{[\s\S]*receiptReportId\.value\s*=\s*result\.report_id/);
  });

  it('batch confirm does not auto-open receipts', () => {
    expect(source).toContain('submitBatchConfirm');
    expect(source).toContain('/api/v1/payment-reports/confirm-batch');
    expect(source).toContain('/api/v1/payment-reports/director-record-batch');
    const batchConfirm = source.match(/async function submitBatchConfirm\(\) \{[\s\S]*?\n\}/);
    expect(batchConfirm).toBeTruthy();
    expect(batchConfirm[0]).not.toMatch(/receiptReportId/);
    expect(batchConfirm[0]).not.toMatch(/receiptOpen/);
  });

  it('class-list receipt lookup opens with match.id (payment report id)', () => {
    expect(source).toContain('async function viewReceiptForClass(row)');
    expect(source).toMatch(/receiptReportId\.value\s*=\s*match\.id/);
  });
});
