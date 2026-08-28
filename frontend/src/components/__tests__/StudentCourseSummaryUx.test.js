import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
const activeCourseSlice = source.slice(source.indexOf('<!-- Active courses: task-first cards'), source.indexOf('<div v-else-if="getHistoryStudentCourses'));

describe('StudentsList course summary UX', () => {
  it('keeps the active course view task-first and progressively disclosed', () => {
    ['student-course-cards', 'student-course-card', '更多操作', '<details'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(activeCourseSlice).not.toContain('course-inner-table');
  });

  it('shows honest session progress and a separate monthly cadence state', () => {
    ['role="progressbar"', ':aria-valuemax="courseProgress(course).total"', '堂數未設定，請編輯課程確認。', '月結'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(source).toContain('const courseProgress = (course) =>');
    expect(source).toContain("if (String(course?.payment_type || '').toLowerCase() === 'monthly') return null;");
  });

  it('preserves the existing course actions behind the disclosure', () => {
    ['togglePaymentStatus(course, student.name)', 'openAddSessionsForCourse(course)', 'openInvoiceModal(course)', 'openLatestPaymentInfo(course, student.name)', 'editCourse(course)', 'closeCourseNoRenew(course, student.name)', 'deleteCourse(course)']
      .forEach((handler) => expect(activeCourseSlice).toContain(handler));
  });
});
