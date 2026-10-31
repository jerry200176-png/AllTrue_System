import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition 確認不收 (waive) action', () => {
  it('is shown on the settled_pending row only for directors', () => {
    // F-5: the button moved to the drawer's contract card; the page still decides who sees it.
    expect(source).toContain("waive: canWaive.value && r.payment_status === 'pending_reconciliation' && Boolean(r.closed_reason)");
    expect(source).toContain("if (kind === 'waive') openWaiveDialog(row);");
    expect(source).toContain('const canWaive = computed(() => isDirectorRole(getAuthRole()));');
  });

  it('requires a reason and posts it to the waive endpoint, then refreshes', () => {
    expect(source).toContain(':disabled="waiveReason.trim().length < 2 || waiveLoading || waivableAmount === null"');
    expect(source).toContain('/api/v1/accounting/courses/${waiveTarget.value.id}/waive');
    expect(source).toContain('JSON.stringify({ reason, expected_amount: waivableAmount.value })');
    expect(source).toContain('waivable_amount');
    expect(source).not.toContain('waiveTarget.payable_outstanding');
    expect(source).toMatch(/已改成「\$\{WAIVED_LABEL\}」[\s\S]{0,120}loadAlerts\(\), loadSettledCourses\(\)/);
  });

  it('labels waived rows in the settled table instead of 正常', () => {
    expect(source).toContain(`v-if="row.closed_reason === 'waived'" class="acct-chip">{{ WAIVED_LABEL }}`);
  });

  it('F7 S3a: shows the backend reconciliation label and the review-required state instead of 正常', () => {
    expect(source).toContain(`row.pending_reconciliation" class="acct-chip acct-chip--pending">{{ row.closed_reason ? ENDED_PENDING_LABEL : PAUSED_PENDING_LABEL }}`);
    expect(source).toContain(`row.payment_review_required" class="acct-chip acct-chip--pending">{{ REVIEW_REQUIRED_LABEL }}`);
    expect(source).toContain('!row.pending_reconciliation && !row.payment_review_required" class="text-light">正常');
  });

  it('hides 確認不收 on paused rows (no closed_reason) because the waive endpoint rejects them', () => {
    expect(source).toContain("pending_reconciliation' && Boolean(r.closed_reason)");
  });

  it('refuses to batch-select or submit rows whose payable is null or 0', () => {
    expect(source).toContain("const hasBatchAmount = (r) => Number(r?.payable_outstanding ?? r?.payable_amount ?? 0) > 0;");
    expect(source).toContain("r.payable_status === 'invoiced' && hasBatchAmount(r)"); // what the student list may pick
    expect(source.match(/&& hasBatchAmount\(r\)\)/g)).toHaveLength(3); // preview rows, submit rows, open-preview rows
  });
});
