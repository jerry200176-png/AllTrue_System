import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition 確認不收 (waive) action', () => {
  it('is shown on the settled_pending row only for directors', () => {
    const row = source.slice(source.indexOf("r.payment_status === 'pending_reconciliation'\">"));
    expect(row.slice(0, 1400)).toContain('v-if="canWaive && r.closed_reason" class="tc-btn tc-btn--reject" @click="openWaiveDialog(r)"');
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

  it('F7 S3a: shows the backend reconciliation label and the 付款期間待確認 state instead of 正常', () => {
    expect(source).toContain(`row.pending_reconciliation" class="acct-chip acct-chip--pending">{{ row.reconciliation_label || (row.closed_reason ? ENDED_PENDING_LABEL : PAUSED_PENDING_LABEL) }}`);
    expect(source).toContain(`row.payment_review_required" class="acct-chip acct-chip--pending">{{ row.reconciliation_label || '付款期間待確認' }}`);
    expect(source).toContain('!row.pending_reconciliation && !row.payment_review_required" class="text-light">正常');
  });

  it('hides 確認不收 on paused rows (no closed_reason) because the waive endpoint rejects them', () => {
    expect(source).toContain('v-if="canWaive && r.closed_reason" class="tc-btn tc-btn--reject" @click="openWaiveDialog(r)"');
  });

  it('refuses to batch-select or submit rows whose payable is null or 0', () => {
    expect(source).toContain("const hasBatchAmount = (r) => Number(r?.payable_outstanding ?? r?.payable_amount ?? 0) > 0;");
    expect(source).toContain("if (ps !== 'pending_report' && !hasBatchAmount(r)) return false;");
    expect(source.match(/&& hasBatchAmount\(r\)\)/g)).toHaveLength(3);
  });
});
