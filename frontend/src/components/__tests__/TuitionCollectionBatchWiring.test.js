// Billing UX F-5a2: the bill list reuses the page's batch preview, submit functions and CSV/session handlers.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

describe('page wiring (F-5a2)', () => {
  const page = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/TuitionCollectionPage.vue'), 'utf8');
  it('reuses the table batch preview, submit functions and CSV/session handlers', () => {
    expect(page).toContain('@batch="onBillBatch"');
    expect(page).toContain('@export="exportBills"');
    expect(page).toContain('watch(activeAccountingTab, () => { selectedIds.value = []; batchFromList = false; })');
    expect(page).toContain('openSessionDetail(b.src)');
    expect(page).toMatch(/function onBillBatch[\s\S]{0,400}openBatchPreview\(\);/);
    // the list picks contracts with the table's own rules
    expect(page).toContain("['unpaid', 'partial', 'pending_reconciliation'].includes(r.payment_status) && r.payable_status === 'invoiced' && hasBatchAmount(r)");
    expect(page).toContain("r.payment_status === 'pending_report' && Boolean(r.latest_payment_report_id)");
    // 回報 fields moved into the preview dialog, and its submit still needs a date
    expect(page).toContain('data-testid="batch-report-fields"');
    expect(page).toContain("batchPreviewMode === 'report' && !batchForm.payment_date");
  });
});
