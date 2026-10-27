import { expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useCourseSessionsDisplay } from '../useCourseSessionsDisplay.js';
import { sessionViewModelFromClassSessionsRow } from '../../../lib/classSessionsApi.js';
import { packageMemberSessionSummary } from '../../../lib/packageSessions.js';

// in-app #435 / #4515: real attended rows and backend entitlement usage are
// different quantities. Label them without rewriting either source of truth.
it('distinguishes nine attended rows from eight used purchased lessons', () => {
  const course = { id: 700001, ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, SessionCount: 8, remaining_sessions: 0 };
  const dates = ['2026-09-01', '2026-09-04', '2026-09-07', '2026-09-10', '2026-09-13', '2026-09-16', '2026-09-19', '2026-09-22', '2026-09-26'];
  const rows = dates.map((date, i) => sessionViewModelFromClassSessionsRow({
    id: i + 1, student_class_id: course.id, session_date: date,
    start_time: '10:00', end_time: '12:00', status: 'attended',
  }));
  const before = JSON.stringify({ course, rows });
  const fetchClassSessionsFn = vi.fn();
  const display = useCourseSessionsDisplay({
    sessionsByCourse: ref({ [course.id]: rows }), completedSessionDatesByCourse: ref({}),
    fetchClassSessionsFn, supabase: { auth: { getSession: vi.fn() } }, branchId: ref(1),
  });

  expect(display.getCompletedSessionCount(course)).toBe(9);
  const summary = packageMemberSessionSummary(course, { completed: display.getUsedSessions(course) });
  expect(summary.text).toBe('已用 8 / 購買 8 堂');
  expect(summary.used).toBe(8);
  expect(summary.remaining).toBe(0);
  expect(display.getSessionStateLabel(course, '2026-09-26', 9)).toBe('已上');
  expect(display.getSessionNumber(course, '2026-09-26', 9)).toBeNull();
  expect(JSON.stringify({ course, rows })).toBe(before);
  expect(fetchClassSessionsFn).not.toHaveBeenCalled();
});

it('keeps actual attendance labels for monthly and shared-package courses', () => {
  expect(packageMemberSessionSummary({ payment_type: 'monthly' }, { completed: 9 }).text).toBe('已上 9 堂');
  const pooled = { PackageID: 1, SessionCount: 8, package_total_sessions: 8, package_remaining_sessions: 0 };
  expect(packageMemberSessionSummary(pooled, { completed: 3 }).text).toBe('本科已上 3 堂｜方案共用 8 堂（已用 8 / 剩 0）');
});
