import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const studentsPagePath = resolve(__dirname, '../../pages/StudentsList.vue');
const manualSessionModalPath = resolve(__dirname, '../course-management/ManualSessionModal.vue');
const source = readFileSync(pagePath, 'utf8');
const studentsSource = readFileSync(studentsPagePath, 'utf8');
const manualSessionModalSource = readFileSync(manualSessionModalPath, 'utf8');
const activeActionsStart = source.indexOf('<td class="cell-actions">');
const activeActionsEnd = source.indexOf('<tr v-if="expandedDates.has(c.id)"', activeActionsStart);
const activeActions = source.slice(activeActionsStart, activeActionsEnd);
const activeMoreMenu = activeActions.slice(activeActions.indexOf('class="action-dropdown"'));

describe('CourseManagement action hierarchy', () => {
  it('keeps core course work visible and groups secondary operations in More', () => {
    expect(activeActions).toContain('course-primary-action');
    expect(activeActions).toContain('@click="editCourse(c)"');
    expect(source).toContain('navigateToStudentCourse(hc)');
    expect(activeActions).toContain('manual-occurrence-action');
    expect(source).toContain('@edit-course="editManualSessionCourse"');
    expect(manualSessionModalSource).toContain('先設定月結結束日');
    expect(activeActions).toContain('btn-toggle');
    expect(activeActions).toContain('更多 ▾');
    expect(activeActions).toContain('排課與課堂');
    expect(activeActions).toContain('帳務與合約');
    expect(activeActions).toContain('轉多科方案預檢');
    expect(source).toContain('繼續轉成多科共用方案');
    expect(activeActions).toContain('course-payment-slip-action');
    expect(activeActions).toContain('isPaymentNoticeAvailable(c)');
    expect(activeActions).toContain('合約／堂次調整');
    expect(activeActions).not.toContain('btn-invoices');
    expect(activeActions).not.toContain('>+ 補課</button>');
  });

  it('keeps the More menu accessible and preserves existing advanced handlers', () => {
    expect(activeActions).toContain('aria-haspopup="menu"');
    expect(activeActions).toContain('role="menu"');
    expect(activeActions).toContain('role="menuitem"');
    expect(activeActions.match(/openManualSessionModal\(c\)/g)).toHaveLength(2);
    expect(activeMoreMenu).not.toContain('openManualSessionModal(c)');
    expect(activeActions).toContain('@click="openInvoiceModal(c); closeActionMenu()"');
    expect(activeActions).toContain('@click="openContractAdjustmentModal(c); closeActionMenu()"');
    expect(activeActions).toContain('@click="duplicateCourseForTeacher(c); closeActionMenu()"');
  });

  it('does not let an earlier manual-session check overwrite the latest selection', () => {
    expect(source).toContain('let manualSessionCheckVersion = 0;');
    expect(source).toContain('const requestVersion = ++manualSessionCheckVersion;');
    expect(source).toContain('if (requestVersion !== manualSessionCheckVersion) return;');
    expect(source).toContain("import { nextManualSessionDate } from '../lib/manualSessionDate.js';");
    expect(source).toContain('session_date: nextManualSessionDate(course)');
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
    expect(source).toContain('>結案（不續報）</button>');
    expect(studentsSource).toContain("['session', 'monthly'].includes");
    expect(studentsSource).toContain("course?.closed_reason !== 'settled_pending'");
    // course-mgmt deep-links commercial settle to students; mutation stays authoritative there
    expect(source).toContain("goToStudentsCommercial(c, 'close')");
    expect(studentsSource).toContain("reason: 'settled'");
    expect(source).toContain('settled_pending');
    expect(studentsSource).toContain('settled_pending');
    expect(studentsSource).toContain('forfeit_remaining: true');
    expect(studentsSource).toContain('放棄這 ${remaining} 堂剩餘額度');
  });

  it('normalizes legacy course IDs and gives monthly scheduling failures a visible result', () => {
    expect(source).toContain('id: Number(c?.id ?? c?.ID ?? 0)');
    expect(source).toContain('const courseIdForAction = (course) => Number(course?.id ?? course?.ID ?? 0);');
    expect(source).toContain("manualSessionCheck.value = { can_add: false, message: '課程資料不完整，請重新整理後再試' }");
    expect(source).toContain('/api/v1/student-classes/${courseId}/manual-sessions/check');
  });
});
