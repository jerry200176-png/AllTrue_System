import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');

describe('authoritative mutation ownership (slice 3: course-mgmt + calendar + binding)', () => {
  it('deep-links course-mgmt billing mutations to tuition-collect', () => {
    const courseMgmt = read('pages/CourseManagement.vue');
    expect(courseMgmt).not.toContain('PaymentEntryModal');
    expect(courseMgmt).toContain('goToTuitionBilling');
    expect(courseMgmt).toContain('前往帳務中心');
    expect(courseMgmt).not.toContain('>登記已回報</button>');
    expect(courseMgmt).not.toContain('submitInvoiceVoid');
    expect(courseMgmt).toContain('runCloseCourseNoRenew');
  });

  it('keeps course close in-place through the shared safeguarded action while keeping trial convert local', () => {
    const courseMgmt = read('pages/CourseManagement.vue');
    expect(courseMgmt).toContain('openCommercialPurchaseEntry');
    expect(courseMgmt).toContain('@click="closeCourseInPlace(c)"');
    expect(courseMgmt).toContain('runCloseCourseNoRenew({');
    expect(courseMgmt).toContain('openManualSessionModal');
    expect(courseMgmt).toContain('/api/v1/student-classes/${course.id}/convert-trial');
  });

  it('keeps an already-completed course eligible for the existing renewal entry, not a new mutation path', () => {
    const courseMgmt = read('pages/CourseManagement.vue');
    const historyStart = courseMgmt.indexOf('class="history-course-card__actions"');
    const historyEnd = courseMgmt.indexOf('<div v-if="expandedDates.has(hc.id)"', historyStart);
    const historyActions = courseMgmt.slice(historyStart, historyEnd);
    expect(historyActions).toContain("effectiveClosedReason(hc) === 'completed'");
    expect(historyActions).toContain('@click="openCommercialPurchaseEntry(hc); closeActionMenu()"');
    expect(historyActions).not.toContain('/api/v1/student-classes/${hc.id}/purchase-batch');
  });

  it('accepts binding-management student-name focus context', () => {
    const binding = read('pages/BindingManagementPage.vue');
    expect(binding).toContain('initialStudentName');
    expect(binding).toContain("emit('clear-initial-student')");
  });

  it('keeps calendar attendance as deep-link only', () => {
    const calendar = read('pages/SmartCalendar.vue');
    const modal = read('components/calendar/modals/CalendarSessionEditModal.vue');
    const guide = read('lib/pageGuideConfig.js');
    expect(modal).toContain('goto-attendance');
    expect(calendar).toContain('goToAttendanceFromSession');
    expect(calendar).not.toContain('/api/v1/attendance');
    expect(guide).toContain('出缺勤請至「出缺勤管理」登記');
  });

  it('does not let a calendar session delete its entire course', () => {
    const calendar = read('pages/SmartCalendar.vue');
    const modal = read('components/calendar/modals/CalendarSessionEditModal.vue');
    expect(calendar).not.toContain('@delete-course=');
    expect(calendar).not.toContain("const deleteCourse = async");
    expect(modal).not.toContain("'delete-course'");
    expect(modal).not.toContain('刪除整門課');
  });
});
