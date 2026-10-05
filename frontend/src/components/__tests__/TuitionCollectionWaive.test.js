import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition 確認不收 (waive) action', () => {
  it('is shown on the settled_pending row only for directors', () => {
    const row = source.slice(source.indexOf("r.payment_status === 'pending_reconciliation'\">"));
    expect(row.slice(0, 1400)).toContain('v-if="canWaive" class="tc-btn tc-btn--reject" @click="openWaiveDialog(r)"');
    expect(source).toContain("['director', 'super_admin'].includes(getAuthRole())");
  });

  it('requires a reason and posts it to the waive endpoint, then refreshes', () => {
    expect(source).toContain(':disabled="waiveReason.trim().length < 2 || waiveLoading || waivableAmount === null"');
    expect(source).toContain('/api/v1/accounting/courses/${waiveTarget.value.id}/waive');
    expect(source).toContain('JSON.stringify({ reason, expected_amount: waivableAmount.value })');
    expect(source).toContain('waivable_amount');
    expect(source).not.toContain('waiveTarget.payable_outstanding');
    expect(source).toMatch(/已確認不收[\s\S]{0,120}loadAlerts\(\), loadSettledCourses\(\)/);
  });

  it('labels waived rows in the settled table instead of 正常', () => {
    expect(source).toContain(`v-if="row.closed_reason === 'waived'" class="acct-chip">確認不收`);
  });
});
