import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import perfFlags from '../../../lib/perfFlags.js';
import { isCourseSessionCalendarEnabled } from '../../../composables/course-management/useCourseSessionCalendar.js';

/**
 * Flag-off rollback + Course Management host wiring (PRODUCT_LOOP_DOGFOOD_001).
 */
describe('CourseSessionCalendar flag gate + CourseManagement host', () => {
  let original;

  beforeEach(() => {
    original = perfFlags.COURSE_SESSION_CALENDAR_V1;
  });

  afterEach(() => {
    perfFlags.COURSE_SESSION_CALENDAR_V1 = original;
  });

  it('defaults OFF so prior Course Management entry is restored', () => {
    expect(perfFlags.COURSE_SESSION_CALENDAR_V1).toBe(false);
    expect(isCourseSessionCalendarEnabled(perfFlags)).toBe(false);
  });

  it('enables only when explicitly set true', () => {
    perfFlags.COURSE_SESSION_CALENDAR_V1 = true;
    expect(isCourseSessionCalendarEnabled(perfFlags)).toBe(true);
    perfFlags.COURSE_SESSION_CALENDAR_V1 = false;
    expect(isCourseSessionCalendarEnabled(perfFlags)).toBe(false);
  });

  it('gates the Course Management calendar entry on the flag and reuses existing writers', () => {
    const source = readFileSync(
      resolve(__dirname, '../../../pages/CourseManagement.vue'),
      'utf8',
    );
    expect(source).toContain('isCourseSessionCalendarEnabled');
    expect(source).toContain('courseSessionCalendarEnabled');
    expect(source).toContain('data-testid="course-session-calendar-toggle"');
    expect(source).toContain('v-if="courseSessionCalendarEnabled"');
    expect(source).not.toMatch(/calendar-sessions|course-session-calendar\/create/);
    expect(source).toContain('openManualSessionModal(course, date ? { date } : null)');
    expect(source).not.toMatch(/openCourseSessionCalendarCancel|calendar.*status=cancelled/);
  });
});
