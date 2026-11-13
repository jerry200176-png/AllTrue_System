import { describe, expect, it } from 'vitest';
import { KIND_OPTIONS, dayLabel, groupByDay, unitsInWeek, weekDays, weekRangeText, weekStartOf } from './studyWeek';

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
