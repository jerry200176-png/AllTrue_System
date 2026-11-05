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
    expect(source).toMatch(/已改成「\$\{WAIVED_LABEL\}」[\s\S]{0,120}loadAlerts\(\), loadReviewCourses\(\)/);
  });

  it('F7 S3a: the 待核對 strip shows the review-required state and why', () => {
    expect(source).toContain(`row.payment_review_required" class="acct-chip acct-chip--pending" :title="REVIEW_REQUIRED_LABEL">{{ REVIEW_REQUIRED_SHORT_LABEL }}`);
    expect(source).toContain('需核對：{{ r.label }}。怎麼處理：{{ r.text }}'); // #4797, in-app #469
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

describe('tuition 已核對 (#4798)', () => {
  it('shows only on review rows with nothing owed, and posts to the check endpoint then reloads', () => {
    expect(source).toContain('v-if="row.payment_review_required && row.outstanding_amount === 0"');
    expect(source).toContain('`tc-review-check-${row.student_class_id}`');
    expect(source).toContain('/api/v1/student-classes/${row.student_class_id}/payment-review/check');
    expect(source).toContain('確認這門課的帳都對了？標記後就不再列在待核對。');
    // askConfirm() only resolves through a mounted host (Codex P1 on #4828).
    expect(source).toMatch(/<ConfirmDialogHost \/>/);
    expect(source).toContain("import ConfirmDialogHost from '../components/course-management/ConfirmDialogHost.vue';");
    expect(source).toMatch(/payment-review\/check[\s\S]{0,400}json\.message[\s\S]{0,300}loadAlerts\(\), loadReviewCourses\(\)/);
  });
});

describe('tuition 需核對 next steps (in-app #469)', () => {
  it('offers 到課程處理 only for the reasons fixed in course management, and no bill button for old months', () => {
    expect(source).toContain(`reviewNeeds(row, 'course')" class="tc-btn" :data-testid="\`tc-review-course-\${row.student_class_id}\`" @click="emit('navigate', { target: 'course-mgmt', studentId: row.student_id, courseId: row.student_class_id, studentName: row.student_name })"`);
    expect(source).not.toContain('tc-review-drafts-');
  });
});
