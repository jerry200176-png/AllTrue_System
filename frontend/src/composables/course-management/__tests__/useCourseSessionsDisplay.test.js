import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useCourseSessionsDisplay } from '../useCourseSessionsDisplay.js';
import { sessionViewModelFromClassSessionsRow } from '../../../lib/classSessionsApi.js';

/**
 * Regression for 2026-07-29: useCourseSessionsDisplay() referenced an
 * undeclared SESSION_NOT_OCCUPYING_QUOTA in its return object, throwing
 * ReferenceError on every CourseManagement.vue mount (blank page, #1409
 * follow-up). No prior test actually called the real composable — the
 * "occurrence" test file only mirrors the filtering logic — so CI never
 * executed this function body.
 */
describe('useCourseSessionsDisplay', () => {
  it('constructs without throwing and exposes the expected API', () => {
    let display;
    expect(() => {
      display = useCourseSessionsDisplay({
        sessionsByCourse: ref({}),
        completedSessionDatesByCourse: ref({}),
        fetchClassSessionsFn: vi.fn(),
        supabase: { auth: { getSession: vi.fn() } },
        branchId: ref(1),
      });
    }).not.toThrow();

    expect(typeof display.sessionUnits).toBe('function');
    expect(typeof display.primarySessionUnits).toBe('function');
    expect(display.primarySessionUnits({ id: 1 })).toEqual([]);
  });

  /**
   * Production incident 2026-08-08, 木柵吳艾潼 SC#2688 (月結/monthly billing):
   * StudentClassController always sets `sessions_purchased = SessionCount`
   * regardless of billing mode, so a monthly course with SessionCount=4 still
   * looks "over quota" once more than 4 non-leave ClassSession rows exist —
   * which monthly courses have no cap on. isOverQuotaSession() only excluded
   * PackageID courses, not monthly (payment_type !== 'session') ones.
   */
  it('does not flag a 月結 (monthly) course as over-quota even though sessions_purchased is set', () => {
    const monthlyCourse = {
      id: 2688,
      ScheduleMode: 'date',
      payment_type: 'monthly',
      sessions_purchased: 4,
      SessionCount: 4,
    };
    const rawRows = [
      { id: 24001, student_class_id: 2688, session_date: '2026-07-05', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 24002, student_class_id: 2688, session_date: '2026-07-12', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 24003, student_class_id: 2688, session_date: '2026-07-19', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 26045, student_class_id: 2688, session_date: '2026-08-02', start_time: '10:00', end_time: '12:00', status: 'leave' },
      { id: 24169, student_class_id: 2688, session_date: '2026-08-08', start_time: '14:30', end_time: '16:30', status: 'scheduled', is_contract_exception: true },
      { id: 27920, student_class_id: 2688, session_date: '2026-08-09', start_time: '10:00', end_time: '12:00', status: 'scheduled' },
    ];

    const display = useCourseSessionsDisplay({
      sessionsByCourse: ref({ 2688: rawRows.map(sessionViewModelFromClassSessionsRow) }),
      completedSessionDatesByCourse: ref({}),
      fetchClassSessionsFn: vi.fn(),
      supabase: { auth: { getSession: vi.fn() } },
      branchId: ref(16),
    });

    expect(display.isSessionMode(monthlyCourse)).toBe(false);

    // The 5th non-leave row (08-09) is the one that would push quotaIndex (5) past
    // purchased (4) under the old code — this is exactly the row that must NOT be
    // labeled 超排 for a monthly course.
    const state = display.getSessionState(monthlyCourse, '2026-08-09');
    expect(state?.label).not.toBe('超排');
  });

  it('counts 已上 from the same chips the date panel shows (#1834 / in-app #237)', () => {
    const monthlyCourse = {
      id: 2688,
      ScheduleMode: 'date',
      payment_type: 'monthly',
      sessions_purchased: 4,
      SessionCount: 4,
    };
    const rawRows = [
      { id: 1, student_class_id: 2688, session_date: '2026-07-05', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 2, student_class_id: 2688, session_date: '2026-07-12', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 3, student_class_id: 2688, session_date: '2026-07-19', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 4, student_class_id: 2688, session_date: '2026-07-26', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 5, student_class_id: 2688, session_date: '2026-08-02', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 6, student_class_id: 2688, session_date: '2026-08-09', start_time: '10:00', end_time: '12:00', status: 'attended' },
      { id: 7, student_class_id: 2688, session_date: '2026-08-16', start_time: '10:00', end_time: '12:00', status: 'attended' },
    ];

    const display = useCourseSessionsDisplay({
      sessionsByCourse: ref({ 2688: rawRows.map(sessionViewModelFromClassSessionsRow) }),
      completedSessionDatesByCourse: ref({}),
      fetchClassSessionsFn: vi.fn(),
      supabase: { auth: { getSession: vi.fn() } },
      branchId: ref(16),
    });

    const attendedChips = display.primarySessionUnits(monthlyCourse)
      .filter((u) => display.getSessionStateLabel(monthlyCourse, u.date, u.id) === '已上');
    expect(attendedChips).toHaveLength(7);
    expect(display.getCompletedSessionCount(monthlyCourse)).toBe(7);
    expect(display.getSessionStateLabel(monthlyCourse, '2026-08-16', 7)).toBe('已上');
  });
});
