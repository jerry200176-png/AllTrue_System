import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const studentsPagePath = resolve(__dirname, '../../pages/StudentsList.vue');
const closeCourseActionPath = resolve(__dirname, '../../lib/closeCourseNoRenew.js');
const manualSessionModalPath = resolve(__dirname, '../course-management/ManualSessionModal.vue');
const source = readFileSync(pagePath, 'utf8');
const studentsSource = readFileSync(studentsPagePath, 'utf8');
const closeCourseActionSource = readFileSync(closeCourseActionPath, 'utf8');
const courseActionsSource = readFileSync(resolve(__dirname, '../../lib/courseActions.js'), 'utf8');
const manualSessionModalSource = readFileSync(manualSessionModalPath, 'utf8');
const activeActionsStart = source.indexOf('<td class="cell-actions">');
const activeActionsEnd = source.indexOf('<tr v-if="!courseManagerEnabled && expandedDates.has(c.id)"', activeActionsStart) >= 0
  ? source.indexOf('<tr v-if="!courseManagerEnabled && expandedDates.has(c.id)"', activeActionsStart)
  : source.indexOf('<tr v-if="expandedDates.has(c.id)"', activeActionsStart);
const activeActions = source.slice(activeActionsStart, activeActionsEnd);
const legacyActionsStart = activeActions.indexOf('<template v-else>');
const legacyActions = legacyActionsStart >= 0 ? activeActions.slice(legacyActionsStart) : activeActions;

describe('CourseManagement action hierarchy', () => {
  it('keeps Course Manager primary entry when flag on, and the row uses the shared action model when off', () => {
    expect(activeActions).toContain('data-testid="course-manager-open"');
    expect(activeActions).toContain('管理課程');
    expect(activeActions).toContain('courseManagerEnabled');
    // 課程查找 PR2: one state-driven primary + ⋯ (ActionMenu) from courseActions(), 詳情 stays a disclosure.
    expect(legacyActions).toContain('course-primary-action');
    expect(legacyActions).toContain('@click="onRowAction(c, rowActions(c).primary.id)"');
    expect(legacyActions).toContain('<ActionMenu');
    expect(legacyActions).toContain('trigger-text="更多 ▾"');
    expect(legacyActions).toContain('btn-toggle');
    expect(legacyActions).not.toContain('course-settle-action');
    expect(legacyActions).not.toContain('class="action-dropdown"');
    expect(source).toContain('navigateToStudentCourse(hc)');
    expect(source).toContain('@edit-course="editManualSessionCourse"');
    expect(manualSessionModalSource).toContain('先設定月結結束日');
    expect(source).toContain('繼續轉成多科共用方案');
    expect(legacyActions).not.toContain('btn-invoices');
    expect(legacyActions).not.toContain('>+ 補課</button>');
  });

  it('feeds the row model the same capability rules the legacy More menu used', () => {
    const rowModel = source.slice(source.indexOf('function rowActions(c)'), source.indexOf('function onRowAction('));
    for (const rule of ['isManualOccurrenceCourse(c)', 'canQuickAddSession(c)', 'quickAddDisabledReason(c)', 'canCloseCourse(c)',
      'isPaymentNoticeAvailable(c)', 'isSessionMode(c) && !isPackageMember(c)', "effectiveClosedReason(c) === 'contract_amended'",
      'purchaseActionLabel(c)', 'purchaseActionIsRenew(c)']) expect(rowModel).toContain(rule);
    const handlers = source.slice(source.indexOf('function courseActionHandlers('), source.indexOf('function onCourseManagerAction('));
    for (const h of ['openTuitionLedger(c)', 'openContractAdjustmentModal(c)', 'duplicateCourseForTeacher(c)', 'openPaymentSlip(c)',
      'openPackageConversionPreview(c)', 'openCommercialPurchaseEntry(c)', 'requestCoursePause(c)', 'confirmDeleteTarget.value = c',
      'openContractRevertModal(c)', 'openCourseTransfer(c)', "openCourseSessionMode(c, 'reschedule')", "openCourseSessionMode(c, 'substitute')",
      'openManualSessionModal(c)', 'openQuickAddSessionModal(c)', 'openMonthlySessionModal(c)', 'editCourse(c)']) expect(handlers).toContain(h);
    expect(source).toContain("if (id === 'close') return closeCourseInPlace(c);");
    expect(source).toContain(':course-id="transferCourseId ?? editingId"');
  });

  it('does not let an earlier manual-session check overwrite the latest selection', () => {
    expect(source).toContain('let manualSessionCheckVersion = 0;');
    expect(source).toContain('const requestVersion = ++manualSessionCheckVersion;');
    expect(source).toContain('if (requestVersion !== manualSessionCheckVersion) return;');
    expect(source).toContain("import { nextManualSessionDate } from '../lib/manualSessionDate.js';");
    expect(source).toContain('session_date: prefillDate || nextManualSessionDate(course)');
    expect(source).toContain('function openManualSessionModal(course, prefill = null)');
    expect(source).toContain('let quickAddCheckVersion = 0;');
    expect(source).toContain('const requestVersion = ++quickAddCheckVersion;');
    expect(source).toContain('Disable submit during the debounce window');
    expect(source).toContain('let quickAddCheckController = null;');
    expect(source).toContain('quickAddCheckController?.abort();');
    expect(source).toContain('signal: controller.signal');
    expect(source).toContain("if (error?.name === 'AbortError') return;");
    expect(source).toContain('function closeManualSessionModal()');
    expect(source).toContain('let manualSessionCheckController = null;');
  });

  it('offers explicit settlement for unpaid courses and preserves reconciliation messaging', () => {
    expect(source).toContain("&& (isSessionMode(c) || isMonthlyMode(c))");
    expect(source).toContain("c.closed_reason !== 'settled_pending';");
    expect(courseActionsSource).toContain("label: '結束課程（不再續課）', confirm: true");
    expect(studentsSource).toContain("['session', 'monthly'].includes");
    expect(studentsSource).toContain("course?.closed_reason !== 'settled_pending'");
    expect(source).toContain("if (id === 'close') return closeCourseInPlace(c);");
    expect(source).toContain('function closeCourseInPlace(course)');
    expect(studentsSource).toContain('runCloseCourseNoRenew({');
    expect(closeCourseActionSource).toContain("reason: 'settled'");
    expect(source).toContain('settled_pending');
    expect(studentsSource).toContain('settled_pending');
    expect(closeCourseActionSource).toContain('forfeit_remaining: true');
    expect(closeCourseActionSource).toContain('放棄這 ${remaining} 堂剩餘額度');
  });

  it('normalizes legacy course IDs and gives monthly scheduling failures a visible result', () => {
    expect(source).toContain('id: Number(c?.id ?? c?.ID ?? 0)');
    expect(source).toContain('const courseIdForAction = (course) => Number(course?.id ?? course?.ID ?? 0);');
    expect(source).toContain("manualSessionCheck.value = { can_add: false, message: '課程資料不完整，請重新整理後再試' }");
    expect(source).toContain('/api/v1/student-classes/${courseId}/manual-sessions/check');
  });

});
