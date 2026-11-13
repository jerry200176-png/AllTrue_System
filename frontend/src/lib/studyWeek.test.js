import { describe, expect, it } from 'vitest';
import { KIND_OPTIONS, attendanceThisMonth, dayLabel, groupByDay, planProgress, unitsInWeek, weekDays, weekRangeText, weekStartOf, weekTaskProgress } from './studyWeek';

const sign = (date, attendance, extra = {}) => ({ type: 'attendance', id: Math.random(), date, attendance, ...extra });

describe('progress strip measures (issue 5269 step 3)', () => {
  it('week tasks: done / total, and no figure at all for an empty week', () => {
    expect(weekTaskProgress([])).toEqual({ status: 'none' });
    expect(weekTaskProgress([{ done_at: 'x' }, { done_at: null }, { done_at: null }])).toEqual({ status: 'ok', done: 1, total: 3, percent: 33 });
  });
  it('plan: the API value as is for one plan, unit-weighted for several, and nothing for a plan without units', () => {
    const plan = (percent_done, n, extra = {}) => ({ status: 'active', percent_done, units_behind: 0, units: Array.from({ length: n }, (_, i) => ({ id: i })), ...extra });
    expect(planProgress([])).toEqual({ status: 'none' });
    expect(planProgress([plan(0, 0)])).toEqual({ status: 'no-units' }); // the API says 0 for no units: not real data
    expect(planProgress([plan(0, 0, { status: 'done' })])).toEqual({ status: 'none' });
    expect(planProgress([plan(40, 5, { units_behind: 2 })])).toEqual({ status: 'ok', percent: 40, behind: 2, plans: 1 });
    expect(planProgress([plan(100, 1), plan(20, 4, { units_behind: 1 }), plan(0, 0)])).toEqual({ status: 'ok', percent: 36, behind: 1, plans: 2 });
  });
  it('attendance: this month only, 到班/遲到/補課 came, 缺席/請假 missed, the rest ignored', () => {
    const items = [sign('2026-11-02', '到班'), sign('2026-11-05', '遲到'), sign('2026-11-09', '請假'), sign('2026-11-10', '缺席'), sign('2026-11-11', '試聽有到'),
      sign('2026-11-12', '到班', { session_status: 'cancelled' }), sign('2026-10-30', '缺席'), sign('2026-11-20', '缺席')];
    expect(attendanceThisMonth(items, '2026-11-13')).toEqual({ status: 'ok', came: 2, total: 4, percent: 50 });
    expect(attendanceThisMonth([sign('2026-10-30', '到班')], '2026-11-13')).toEqual({ status: 'none' });
    expect(attendanceThisMonth(null, '2026-11-13')).toEqual({ status: 'unavailable' });
  });
  it('attendance: refuses to guess when a capped source may have cut into the month', () => {
    const items = [sign('2026-11-02', '到班')];
    expect(attendanceThisMonth(items, '2026-11-13', ['attendance']).status).toBe('partial');
    expect(attendanceThisMonth([sign('2026-10-02', '到班'), ...items], '2026-11-13', ['attendance']).status).toBe('ok'); // older rows exist, the month is complete
    expect(attendanceThisMonth(items, '2026-11-13', ['exam_score']).status).toBe('ok');
  });
});

describe('studyWeek date math (Monday start, date-only strings)', () => {
  it('finds the Monday of any day, including Sunday and a year boundary', () => {
    expect(weekStartOf('2026-11-13')).toBe('2026-11-09'); // Friday
    expect(weekStartOf('2026-11-09')).toBe('2026-11-09'); // Monday
    expect(weekStartOf('2026-11-15')).toBe('2026-11-09'); // Sunday belongs to the week that started Monday
    expect(weekStartOf('2027-01-01')).toBe('2026-12-28');
  });
  it('lists seven consecutive days and labels them', () => {
    const days = weekDays('2026-11-09');
    expect(days).toHaveLength(7);
    expect(days[0]).toBe('2026-11-09');
    expect(days[6]).toBe('2026-11-15');
    expect(dayLabel('2026-11-09')).toBe('週一 11/09');
    expect(dayLabel('2026-11-15')).toBe('週日 11/15');
    expect(weekRangeText('2026-11-09')).toBe('11/09 – 11/15');
  });
});

describe('studyWeek grouping', () => {
  it('groups tasks by due date and keeps every day key', () => {
    const days = weekDays('2026-11-09');
    const g = groupByDay([{ id: 1, due_date: '2026-11-10' }, { id: 2, due_date: '2026-11-10' }, { id: 3, due_date: '2026-11-20' }], days);
    expect(g['2026-11-10'].map((t) => t.id)).toEqual([1, 2]);
    expect(g['2026-11-09']).toEqual([]);
    expect(Object.keys(g)).toHaveLength(7);
  });
  it('picks the plan units that overlap the week, active plans only', () => {
    const plans = [
      { id: 1, status: 'active', title: '數學', units: [{ id: 1, title: 'A', planned_start: '2026-11-02', planned_end: '2026-11-08' }, { id: 2, title: 'B', planned_start: '2026-11-08', planned_end: '2026-11-12' }] },
      { id: 2, status: 'archived', title: '英文', units: [{ id: 3, title: 'C', planned_start: '2026-11-09', planned_end: '2026-11-15' }] },
    ];
    expect(unitsInWeek(plans, '2026-11-09').map((u) => u.id)).toEqual([2]);
  });
  it('offers the five kinds the API accepts', () => {
    expect(KIND_OPTIONS.map((k) => k.value)).toEqual(['preview', 'homework', 'review', 'correction', 'other']);
  });
});
