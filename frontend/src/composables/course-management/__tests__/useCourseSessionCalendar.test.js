import { describe, expect, it } from 'vitest';
import {
  buildAddSessionCreatePayload,
  buildCourseSessionCalendarCells,
  buildManualSessionCreatePayload,
  canOfferQuickAddFromCalendar,
  groupSessionsByDate,
  isCourseSessionCalendarEnabled,
  resolveCourseSessionCreateWriter,
} from '../useCourseSessionCalendar.js';

describe('useCourseSessionCalendar', () => {
  it('defaults the feature flag off', () => {
    expect(isCourseSessionCalendarEnabled({ COURSE_SESSION_CALENDAR_V1: false })).toBe(false);
    expect(isCourseSessionCalendarEnabled({ COURSE_SESSION_CALENDAR_V1: true })).toBe(true);
    expect(isCourseSessionCalendarEnabled({})).toBe(false);
  });

  it('groups materialized and projected occurrences by date', () => {
    const byDate = groupSessionsByDate([
      { date: '2026-09-20', isProjected: false, id: 1 },
      { date: '2026-09-20T00:00:00', isProjected: true, id: null },
      { date: '2026-09-27', kind: 'projected' },
    ]);
    expect(byDate.get('2026-09-20')).toHaveLength(2);
    expect(byDate.get('2026-09-27')).toHaveLength(1);
  });

  it('builds month cells distinguishing materialized vs projected and future create slots', () => {
    const cells = buildCourseSessionCalendarCells({
      year: 2026,
      month: 9,
      todayYmd: '2026-09-17',
      sessions: [
        { date: '2026-09-10', isProjected: false, id: 11, status: 'attended' },
        { date: '2026-09-24', isProjected: true, id: null, status: 'projected' },
      ],
    });

    const past = cells.find((c) => c.date === '2026-09-10');
    const projected = cells.find((c) => c.date === '2026-09-24');
    const emptyFuture = cells.find((c) => c.date === '2026-09-25');
    const emptyPast = cells.find((c) => c.date === '2026-09-11');

    expect(past.hasMaterialized).toBe(true);
    expect(past.canCreate).toBe(false);
    expect(projected.hasProjected).toBe(true);
    expect(projected.hasMaterialized).toBe(false);
    expect(projected.canCreate).toBe(false);
    expect(emptyFuture.canCreate).toBe(true);
    expect(emptyPast.canCreate).toBe(false);
  });

  it('routes create to existing manual-sessions writer and never invents a parallel writer', () => {
    expect(resolveCourseSessionCreateWriter({ id: 1, payment_type: 'session' })).toBe('manual-sessions');
    expect(resolveCourseSessionCreateWriter({ id: 1, payment_type: 'monthly' })).toBe('manual-sessions');
    expect(resolveCourseSessionCreateWriter({ id: 1, scheduling_policy: 'manual_occurrence' })).toBe('manual-sessions');
    expect(resolveCourseSessionCreateWriter({ id: 1, status: 'inactive' })).toBe('none');
  });

  it('offers quick-add only for active count-mode non-manual courses', () => {
    expect(canOfferQuickAddFromCalendar({ payment_type: 'session', status: 'active' })).toBe(true);
    expect(canOfferQuickAddFromCalendar({ payment_type: 'monthly' })).toBe(false);
    expect(canOfferQuickAddFromCalendar({ payment_type: 'session', scheduling_policy: 'manual_occurrence' })).toBe(false);
  });

  it('build create payloads with only existing writer fields (no cancel/status/teacher)', () => {
    expect(buildManualSessionCreatePayload({ session_date: '2026-09-20', start_time: '16:00:00' }))
      .toEqual({ session_date: '2026-09-20', start_time: '16:00' });
    expect(Object.keys(buildManualSessionCreatePayload({ session_date: '2026-09-20', start_time: '16:00' })))
      .toEqual(['session_date', 'start_time']);

    const addPayload = buildAddSessionCreatePayload({
      session_date: '2026-09-20',
      start_time: '16:00',
      duration_minutes: 120,
      note: '補課',
      auto_approve: true,
    });
    expect(addPayload).toEqual({
      session_date: '2026-09-20',
      start_time: '16:00',
      duration_minutes: 120,
      note: '補課',
      auto_approve: true,
    });
    expect(addPayload).not.toHaveProperty('status');
    expect(addPayload).not.toHaveProperty('end_time');
    expect(addPayload).not.toHaveProperty('teacher_id');
  });
});
