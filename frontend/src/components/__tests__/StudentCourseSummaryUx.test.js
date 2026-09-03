import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
const activeCourseSlice = source.slice(source.indexOf('<!-- Phase 2A:'), source.indexOf('<div v-else-if="getHistoryStudentCourses'));

describe('StudentsList course summary UX', () => {
  it('keeps the active course view task-first and progressively disclosed', () => {
    ['student-course-detail', '目前課程工作區', 'student-course-cards', 'student-course-card', '更多操作', '<details'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(activeCourseSlice).not.toContain('course-inner-table');
  });

  it('keeps history as an explicit accessible disclosure below active work', () => {
    ['sl-history-toggle', ':aria-expanded="expandedHistoryCourses.has(student.id)"', ':aria-controls="`student-history-${student.id}`"', ':id="`student-history-${student.id}`"']
      .forEach((marker) => expect(source).toContain(marker));
  });

  it('puts course count, attention count, and selectable next action before detail', () => {
    ['student-course-overview', '課程總覽', '需要處理', 'student-course-picker', 'aria-pressed', 'selectStudentCourse(student.id, course.id, $event)']
      .forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(source).toContain('const getFocusedStudentCourse = (studentId) =>');
    expect(source).toContain('const getStudentCourseAttentionCount = (studentId) =>');
  });

  it('uses existing data states for attention labels without inventing a score', () => {
    ['需要續報', '付款待確認', '資料待確認', '進行中', 'getCourseProgressSummary(course)']
      .forEach((marker) => expect(source).toContain(marker));
    expect(source).not.toContain('XP');
    expect(source).not.toContain('連續天數');
  });

  it('makes the primary next step explicit for each existing course state', () => {
    ['student-course-card__next-step', '現在先處理', 'getCoursePrimaryAction(course)', 'openCoursePrimaryAction(course, student.name)']
      .forEach((marker) => expect(activeCourseSlice).toContain(marker));
    ['查看繳費資訊', '補齊課程資料'].forEach((marker) => expect(source).toContain(marker));
    expect(source).toContain("if (action.key === 'renew') return openAddSessionsForCourse(course);");
    expect(source).toContain("if (action.key === 'payment') return goToTuitionBilling(course);");
  });

  it('shows honest session progress and a separate monthly cadence state', () => {
    ['role="progressbar"', ':aria-valuemax="courseProgress(course).total"', '堂數未設定，請編輯課程確認。', '月結'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(source).toContain('const courseProgress = (course) =>');
    expect(source).toContain("if (String(course?.payment_type || '').toLowerCase() === 'monthly') return null;");
  });

  it('preserves the existing course actions behind the disclosure', () => {
    ['goToTuitionBilling(course)', 'openAddSessionsForCourse(course)', 'openInvoiceModal(course)', 'editCourse(course)', 'closeCourseNoRenew(course, student.name)', 'deleteCourse(course)']
      .forEach((handler) => expect(activeCourseSlice).toContain(handler));
    expect(activeCourseSlice).not.toContain('togglePaymentStatus');
    expect(activeCourseSlice).not.toContain('openLatestPaymentInfo');
  });
});
