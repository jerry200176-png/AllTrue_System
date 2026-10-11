import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const studentsPagePath = resolve(__dirname, '../../pages/StudentsList.vue');
const rowActionsPath = resolve(__dirname, '../../composables/course-management/useCourseRowActions.js');
const courseActionsSource = readFileSync(resolve(__dirname, '../../lib/courseActions.js'), 'utf8');
const closeCourseActionPath = resolve(__dirname, '../../lib/closeCourseNoRenew.js');
const manualSessionModalPath = resolve(__dirname, '../course-management/ManualSessionModal.vue');
const source = readFileSync(pagePath, 'utf8');
const studentsSource = readFileSync(studentsPagePath, 'utf8');
const closeCourseActionSource = readFileSync(closeCourseActionPath, 'utf8');
const manualSessionModalSource = readFileSync(manualSessionModalPath, 'utf8');
const activeActionsStart = source.indexOf('<td class="cell-actions">');
const activeActionsEnd = source.indexOf('<tr v-if="!courseManagerEnabled && expandedDates.has(c.id)"', activeActionsStart) >= 0
  ? source.indexOf('<tr v-if="!courseManagerEnabled && expandedDates.has(c.id)"', activeActionsStart)
  : source.indexOf('<tr v-if="expandedDates.has(c.id)"', activeActionsStart);
const activeActions = source.slice(activeActionsStart, activeActionsEnd);
const legacyActionsStart = activeActions.indexOf('<template v-else>');
const legacyActions = legacyActionsStart >= 0 ? activeActions.slice(legacyActionsStart) : activeActions;

describe('CourseManagement action hierarchy', () => {
  it('keeps Course Manager primary entry when flag on; flag-off rows render one primary + the shared ⋯ menu', () => {
    expect(activeActions).toContain('data-testid="course-manager-open"');
    expect(activeActions).toContain('管理課程');
    expect(activeActions).toContain('courseManagerEnabled');
    expect(legacyActions).toContain('course-primary-action');
    expect(legacyActions).toContain('data-testid="course-row-primary"');
    expect(legacyActions).toContain('@click="runRowAction(c, rowModelFor(c).primary.id)"');
    expect(legacyActions).toContain('<ActionMenu');
    expect(legacyActions).toContain('@select="(id) => runRowAction(c, id)"');
    // one primary + ⋯: no stray row buttons (結束課程 / 排課 / 詳情 / 更多 ▾ live in ⋯ now)
    expect(legacyActions.match(/<button/g)).toHaveLength(1);
    expect(legacyActions).not.toContain('btn-toggle');
    expect(legacyActions).not.toContain('更多 ▾');
    expect(legacyActions).not.toContain('btn-invoices');
    expect(source).toContain('navigateToStudentCourse(hc)');
    expect(source).toContain('@edit-course="editManualSessionCourse"');
    expect(manualSessionModalSource).toContain('先設定月結結束日');
    expect(courseActionsSource).toContain('轉多科方案預檢');
    expect(source).toContain('繼續轉成多科共用方案');
  });

  it('routes every ⋯ item to the handler the old row buttons used', () => {
    const rowActions = readFileSync(rowActionsPath, 'utf8');
    for (const h of ['editCourse', 'toggleDatesAndMakeups', 'requestCoursePause', 'closeCourseInPlace', 'openManualSessionModal',
      'openMonthlySessionModal', 'openQuickAddSessionModal', 'duplicateCourseForTeacher', 'openCommercialPurchaseEntry',
      'openContractAdjustmentModal', 'openContractRevertModal', 'openPackageConversionPreview', 'openPaymentSlip', 'openTuitionLedger']) {
      expect(rowActions).toContain(`d.${h}(c)`);
    }
    expect(rowActions).toContain("'contract-adjust'");
    expect(source).toContain('useCourseRowActions({');
    expect(source).toContain('openCourseTransfer: (c) => { editCourse(c, { openModal: false }); showCourseTransfer.value = true; }');
    expect(source).toContain('requestDelete: (c) => { confirmDeleteTarget.value = c; }');
    expect(source).toContain('isPaymentNoticeAvailable,');
  });

  it('drawer header shares the row model and lesson rows move in one click', () => {
    expect(source).toContain(':action-model="courseManagerActionModel(courseManagerCourse)"');
    expect(source).toContain("rowModelFor(c, { fallback: 'manage', details: false })");
    expect(source).toContain('@move-lesson="onCourseManagerMoveLesson"');
    expect(source).toContain("reschedule: () => runRowAction(c, 'reschedule')");
    expect(source).toContain("transfer: () => runRowAction(c, 'transfer')");
    expect(source).toContain('<template #meta-extra><LessonPickerSelect');
  });

  it('lesson picker selection is derived from the open lesson, never stored (P1 #3828)', () => {
    expect(source).toContain('key: lessonPickerKey(lessonPicker.value, currentLesson())');
    expect(source).toContain(':lesson-picker="lessonPickerView"');
    expect(source).toContain('<LessonPickerSelect :picker="lessonPickerView"');
    expect(source).not.toMatch(/lessonPicker\.value = \{[^}]*key:/);
    expect(source).toContain('canOpenLesson');
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
    expect(courseActionsSource).toContain('結束課程（不再續課）');
    expect(studentsSource).toContain("['session', 'monthly'].includes");
    expect(studentsSource).toContain("course?.closed_reason !== 'settled_pending'");
    expect(source.match(/closeCourseInPlace,/g)).toHaveLength(1);
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
