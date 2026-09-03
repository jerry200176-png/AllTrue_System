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
  });

  it('routes commercial renew/close to students while keeping trial convert local', () => {
    const courseMgmt = read('pages/CourseManagement.vue');
    expect(courseMgmt).toContain('openCommercialPurchaseEntry');
    expect(courseMgmt).toContain("goToStudentsCommercial(c, 'close')");
    expect(courseMgmt).toContain('openManualSessionModal');
    expect(courseMgmt).toContain('/api/v1/student-classes/${course.id}/convert-trial');
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
});
