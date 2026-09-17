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

  it('builds month cells for materialized, projected, and future create slots', () => {
    const cells = buildCourseSessionCalendarCells({
      year: 2026, month: 9, todayYmd: '2026-09-17',
      sessions: [
        { date: '2026-09-10', isProjected: false, id: 11 },
        { date: '2026-09-24', isProjected: true, id: null },
      ],
    });
    expect(cells.find((c) => c.date === '2026-09-10').hasMaterialized).toBe(true);
    expect(cells.find((c) => c.date === '2026-09-24').hasProjected).toBe(true);
    expect(cells.find((c) => c.date === '2026-09-25').canCreate).toBe(true);
    expect(cells.find((c) => c.date === '2026-09-11').canCreate).toBe(false);
  });

  it('routes create to manual-sessions and builds existing-writer payloads only', () => {
    expect(resolveCourseSessionCreateWriter({ id: 1 })).toBe('manual-sessions');
    expect(resolveCourseSessionCreateWriter({ id: 1, status: 'inactive' })).toBe('none');
    expect(canOfferQuickAddFromCalendar({ payment_type: 'session' })).toBe(true);
    expect(canOfferQuickAddFromCalendar({ payment_type: 'monthly' })).toBe(false);
    expect(buildManualSessionCreatePayload({ session_date: '2026-09-20', start_time: '16:00:00' }))
      .toEqual({ session_date: '2026-09-20', start_time: '16:00' });
    const add = buildAddSessionCreatePayload({
      session_date: '2026-09-20', start_time: '16:00', duration_minutes: 120, note: '補課',
    });
    expect(Object.keys(add).sort()).toEqual(['auto_approve', 'duration_minutes', 'note', 'session_date', 'start_time']);
    expect(add).not.toHaveProperty('status');
    expect(add).not.toHaveProperty('teacher_id');
  });
});
