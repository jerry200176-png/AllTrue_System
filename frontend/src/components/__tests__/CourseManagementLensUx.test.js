import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { usageBalanceWarningTitle } from '../../lib/courseRowWarnings';
const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue'), source = readFileSync(pagePath, 'utf8');
describe('CourseManagement lens UX', () => {
  it('keeps the lens role, triage summary, and resettable accessible filters', () => {
    for (const marker of ['course-lens-guidance', 'course-lens-summary', 'const courseLensMetrics = computed', '剩餘 2 堂以下', '堂數待對帳', 'usageReviewCount', 'tag-usage-review', 'for="course-filter-student"', 'id="course-filter-student"', 'data-testid="course-filter-clear"', '清除篩選', 'function clearCourseFilters()', ':focus-visible', 'class="course-lens-nav-action"', "@click=\"emit('navigate', 'students')\""]) {
      expect(source).toContain(marker);
    }
    expect(source).not.toContain('唯讀營運視圖');
    expect(source).toContain('course-header-goto-students-create');
  });

  it('keeps the usage reconciliation warning visible in history cards', () => {
    const historySection = source.slice(source.indexOf('class="history-section"'));
    expect(historySection).toContain("hc.usage_balance_status === 'review_required'");
    expect(historySection).toContain('usageBalanceWarningTitle(hc)');
    expect(historySection).toContain('堂數待對帳');
    expect(historySection).toContain('@click.stop="openLedgerForCourse(hc)"');
    expect(historySection).toContain('查看對帳明細');
  });

  it('provides a read-only next step from active-course reconciliation warnings', () => {
    const activeSection = source.slice(0, source.indexOf('class="history-section"'));
    expect(activeSection).toContain('@click.stop="openLedgerForCourse(c)"');
    expect(activeSection).toContain('tag-usage-review--action');
    expect(activeSection).toContain('點擊查看對帳明細');
  });

  it('uses the shared warning helper for active and history cards', () => {
    expect(source).toContain("import { courseRowWarningSummary, usageBalanceWarningTitle } from '../lib/courseRowWarnings'");
    expect(source).toContain('usageBalanceWarningTitle(c)');
    expect(source).toContain('usageBalanceWarningTitle(hc)');
  });

  it('does not turn a monthly null balance into a prepaid balance warning', () => {
    const monthly = {
      usage_balance_diagnostic: {
        stored_remaining_sessions: 0,
        expected_remaining_sessions: null,
        class_session_used_sessions: 1,
        ledger_used_sessions: 0,
        cancelled_usage_artifacts: 0,
      },
    };
    expect(usageBalanceWarningTitle(monthly)).not.toContain('原始記錄為剩');
    expect(usageBalanceWarningTitle(monthly)).toContain('已用堂數或扣堂紀錄不一致');
    expect(usageBalanceWarningTitle({ usage_balance_diagnostic: {
      ...monthly.usage_balance_diagnostic,
      cancelled_usage_artifacts: 1,
    } })).toContain('已取消課堂');
    expect(usageBalanceWarningTitle({ usage_balance_diagnostic: {
      ...monthly.usage_balance_diagnostic,
      stored_remaining_sessions: 1,
      expected_remaining_sessions: 0,
    } })).toContain('原始記錄為剩 1 堂');
  });
});
