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

  it('post-confirm auto-open uses result.report_id', () => {
    expect(source).toMatch(/if\s*\(result\?\.report_id\)\s*\{[\s\S]*receiptReportId\.value\s*=\s*result\.report_id/);
  });

  it('class-list receipt lookup opens with match.id (payment report id)', () => {
    expect(source).toContain('async function viewReceiptForClass(row)');
    expect(source).toMatch(/receiptReportId\.value\s*=\s*match\.id/);
  });
});
