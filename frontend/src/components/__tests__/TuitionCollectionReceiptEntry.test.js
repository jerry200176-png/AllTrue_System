import { describe, it, expect, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';
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


  it('F-5: the queue table, its tabs and its sticky batch bar are gone; batches start from the student list', () => {
    expect(source).toContain('const STATUS_CONFIG = TUITION_STATUS_CONFIG;');
    expect(source).not.toContain('TAB_DEFS');
    expect(source).not.toContain("activeAccountingTab === 'receivables'");
    expect(source).toContain('@batch="onBillBatch"');
  });

  it('receipt records fetch accounting/payments without a page-local PIN modal', () => {
    expect(source).toContain('authedFetch(`/api/v1/accounting/payments?${params}`');
    expect(source).not.toContain('PinLockModal');
  });


  it('prevents mixed batch operations', () => {
    expect(source).toContain("if (modes.size > 1) return 'mixed';");
    expect(source).toContain('一次只能選同一種：未繳，或待確認入帳。');
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
    expect(source).toMatch(/function onBillBatch[\s\S]{0,400}openBatchPreview\(\);/);
    expect(source).toContain('role="dialog"');
    expect(source).toContain('aria-modal="true"');
    expect(source).toContain('送出前確認');
    expect(source).toContain('function confirmBatchPreview()');
    expect(source).toContain('if (!batchPreviewOpen.value) return;');
    expect(source).toContain('批次摘要');
    expect(source).toContain('只會處理上方未繳／繳了一部分的課程');
    expect(source).toContain('只處理上面這幾筆');
  });


  it('requires explicit forfeiture when settling with remaining sessions (#1839)', () => {
    expect(source).toContain('settleTargetStillOwesSessions');
    expect(source).toContain('確認結案會取消未來排課並放棄這些剩餘額度');
    expect(source).toContain('forfeit_remaining: true');
    expect(source).toContain(':disabled="settleLoading"');
    expect(source).not.toContain('請先排課後再結案');
  });

  it('offers the real no-renew dialog for a paid standalone monthly reminder', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token', user: { role: 'director' } }));
    const fetch = vi.fn(async url => ({ ok: true, status: 200, json: async () => String(url).includes('/api/v1/alerts/tuition')
      ? [{ id: 71, student_id: 81, student_name: '合成月結學生', subject: '合成月結', schedule_mode: 'date', payment_status: 'monthly_due_soon', remaining_sessions: 0 }]
      : [] }));
    vi.stubGlobal('fetch', fetch);
    const page = shallowMount(TuitionCollectionPage, { props: { branchId: 1 } });
    try {
      await flushPromises();
      const ledger = page.findComponent({ name: 'AccountingLedgerModal' });
      expect(ledger.props('rowActions')[71].settle).toBe(true);
      ledger.vm.$emit('row-action', { kind: 'settle', courseId: 71 });
      await flushPromises();
      expect(page.find('.tc-overlay').text()).toContain('合成月結');
      expect(page.find('.tc-overlay').text()).toContain('結案');
      expect(fetch.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
    } finally { page.unmount(); vi.unstubAllGlobals(); localStorage.clear(); }
  });
});
