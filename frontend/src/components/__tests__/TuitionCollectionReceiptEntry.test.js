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

  it('uses 待對帳 on collection tabs and sticky batch bar when rows are selected', () => {
    expect(source).toContain("{ key: 'pending_report', label: '待對帳' }");
    expect(source).toContain("pending_report:   { label: '待對帳'");
    expect(source).toContain('tc-batch-bar--sticky');
    expect(source).toContain('v-if="selectedRows.length"');
    expect(source).not.toContain('勾選最左欄後才會出現批次回報或確認列。');
  });

  it('receipt records fetch accounting/payments without a page-local PIN modal', () => {
    expect(source).toContain('fetch(`/api/v1/accounting/payments?${params}`');
    expect(source).not.toContain('PinLockModal');
  });

  it('declares activeTab before batchMode / isRowSelectable / watch(activeTab) (TDZ)', () => {
    const decl = source.indexOf("const activeTab = ref('action')");
    expect(decl).toBeGreaterThan(-1);
    expect(decl).toBeLessThan(source.indexOf('function isRowSelectable'));
    expect(decl).toBeLessThan(source.indexOf("if (activeTab.value === 'pending_report') return 'confirm';"));
    expect(decl).toBeLessThan(source.indexOf('watch(activeTab,'));
  });

  it('opens on a mixed-status action queue and prevents mixed batch operations', () => {
    expect(source).toContain("{ key: 'action', label: '待處理' }");
    expect(source).toContain('aria-label="主任待處理佇列"');
    expect(source).toContain("if (activeTab.value === 'action') return ps === 'unpaid' || ps === 'partial' || ps === 'pending_report';");
    expect(source).toContain("if (modes.size > 1) return 'mixed';");
    expect(source).toContain('請分開選取未繳費或待對帳，才能進行批次處理。');
  });

  it('admin reported-paid path does not auto-open a receipt', () => {
    expect(source).toContain('已送出待對帳，畫面已切到待對帳；請按確認入帳後才會變成已繳費並開收據');
    expect(source).not.toMatch(/if\s*\(result\?\.report_id\)\s*\{[\s\S]*receiptReportId\.value\s*=\s*result\.report_id/);
  });

  it('moves successful or duplicate reports into the pending-accounting flow', () => {
    expect(source).toContain('@pending="onPendingReportConflict"');
    expect(source).toContain("activeTab.value = 'pending_report'");
    expect(source).toContain('畫面已切到待對帳');
    expect(source).toContain('請按確認入帳後才會變成已繳費並開收據');
  });

  it('shows a stable course reference so duplicate subjects cannot be mistaken for one course', () => {
    expect(source).toContain('formatCourseRef(r.id)');
    expect(source).toContain('course_start_date || r.course_end_date');
    expect(source).toContain('function formatCourseRef(id)');
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

  it('requires a read-only batch preview before either batch endpoint can run', () => {
    expect(source).toContain('@click="openBatchPreview"');
    expect(source).toContain('role="dialog"');
    expect(source).toContain('aria-modal="true"');
    expect(source).toContain('送出前確認');
    expect(source).toContain('function confirmBatchPreview()');
    expect(source).toContain('if (!batchPreviewOpen.value) return;');
    expect(source).toContain('批次摘要');
    expect(source).toContain('只會處理上方未繳／部分付款課程');
    expect(source).toContain('只會處理上方待對帳課程');
  });

  it('class-list receipt lookup opens with match.id (payment report id)', () => {
    expect(source).toContain('async function viewReceiptForClass(row)');
    expect(source).toMatch(/receiptReportId\.value\s*=\s*match\.id/);
  });

  it('blocks settle when remaining sessions are still owed (#1839)', () => {
    expect(source).toContain('settleTargetStillOwesSessions');
    expect(source).toContain('還有 ${Number(row.remaining_sessions)} 堂未上，請先排課後再結案');
    expect(source).toContain(':disabled="settleLoading || settleTargetStillOwesSessions"');
  });
});
