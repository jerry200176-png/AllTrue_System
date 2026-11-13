import { describe, expect, it } from 'vitest';
import {
  addDays, barSpan, buildTimeline, dayNumber, examMarkers, formatMd, markerPercent, summaryText, unitAriaText, unitState,
} from './learningPlanGantt';

const u = (s, e, extra = {}) => ({ title: '分數', planned_start: s, planned_end: e, completed_at: null, dates_manual: false, ...extra });

describe('date-only math', () => {
  it('counts days across DST changes and month ends without drift', () => {
    expect(dayNumber('2026-03-09') - dayNumber('2026-03-08')).toBe(1);
    expect(dayNumber('2026-11-02') - dayNumber('2026-11-01')).toBe(1);
    expect(addDays('2026-12-30', 3)).toBe('2027-01-02');
    expect(addDays('2028-02-28', 1)).toBe('2028-02-29');
    expect(formatMd('2026-12-01')).toBe('12/01');
    expect(Number.isNaN(dayNumber('bad'))).toBe(true);
  });
});

describe('buildTimeline', () => {
  it('makes one column per started week and labels each month once', () => {
    const t = buildTimeline('2026-11-23', '2026-12-20'); // 28 days = exactly 4 weeks
    expect(t.weeks).toBe(4);
    expect(t.columns.map((c) => c.label)).toEqual(['11/23', '11/30', '12/07', '12/14']);
    expect(t.months).toEqual([{ month: 11, label: '11月', startColumn: 1, span: 2 }, { month: 12, label: '12月', startColumn: 3, span: 2 }]);
    expect(buildTimeline('2026-11-23', '2026-12-21').weeks).toBe(5); // 29th day starts a new week
  });
  it('keeps a plan shorter than a week as one column, and a one-day plan too', () => {
    expect(buildTimeline('2026-11-23', '2026-11-26').weeks).toBe(1);
    expect(buildTimeline('2026-11-23', '2026-11-23').weeks).toBe(1);
  });
});

describe('barSpan and markers', () => {
  const t = buildTimeline('2026-11-23', '2026-12-20');
  it('spans the weeks a unit touches, ending inside the last column on plan_end', () => {
    expect(barSpan(t, u('2026-11-23', '2026-11-29'))).toEqual({ colStart: 1, colEnd: 2 });
    expect(barSpan(t, u('2026-11-28', '2026-12-08'))).toEqual({ colStart: 1, colEnd: 4 });
    expect(barSpan(t, u('2026-12-14', '2026-12-20'))).toEqual({ colStart: 4, colEnd: 5 });
  });
  it('clamps dates outside the range and never lets the end precede the start', () => {
    expect(barSpan(t, u('2026-11-01', '2027-01-30'))).toEqual({ colStart: 1, colEnd: 5 });
    expect(barSpan(t, u('2026-12-10', '2026-12-01'))).toEqual({ colStart: 3, colEnd: 4 });
  });
  it('places markers inside the range only', () => {
    expect(markerPercent(t, '2026-11-23')).toBeCloseTo((0.5 / 28) * 100);
    expect(markerPercent(t, '2026-12-20')).toBeCloseTo((27.5 / 28) * 100);
    expect(markerPercent(t, '2026-11-22')).toBeNull();
    expect(markerPercent(t, '2026-12-21')).toBeNull();
  });
  it('shows only the API next_exam, and drops it when it is outside the chart', () => {
    const exam = { label: '期末', start_date: '2026-12-10', days_left: 5 };
    expect(examMarkers(t, { next_exam: exam })).toHaveLength(1);
    expect(examMarkers(t, { next_exam: { ...exam, start_date: '2027-02-01' } })).toEqual([]);
    expect(examMarkers(t, { next_exam: null })).toEqual([]);
  });
});

describe('unitState', () => {
  it('classifies by today, with done winning over behind', () => {
    expect(unitState(u('2026-11-01', '2026-11-10'), '2026-11-13')).toBe('behind');
    expect(unitState(u('2026-11-01', '2026-11-10', { completed_at: '2026-11-12 10:00:00' }), '2026-11-13')).toBe('done');
    expect(unitState(u('2026-11-10', '2026-11-20'), '2026-11-13')).toBe('in_progress');
    expect(unitState(u('2026-11-13', '2026-11-13'), '2026-11-13')).toBe('in_progress'); // ends today is not behind
    expect(unitState(u('2026-11-14', '2026-11-20'), '2026-11-13')).toBe('upcoming');
  });
  it('handles today before and after the whole plan', () => {
    expect(unitState(u('2026-11-23', '2026-12-01'), '2026-11-01')).toBe('upcoming');
    expect(unitState(u('2026-11-23', '2026-12-01'), '2027-01-01')).toBe('behind');
    expect(markerPercent(buildTimeline('2026-11-23', '2026-12-20'), '2026-11-01')).toBeNull();
  });
});

describe('texts', () => {
  it('builds the screen-reader sentence and the header summary', () => {
    expect(unitAriaText(u('2026-12-01', '2026-12-14', { title: '因式分解' }), 2, '2026-12-20')).toBe('第 3 單元 因式分解：12/01–12/14，落後');
    expect(unitAriaText(u('2026-12-01', '2026-12-14', { dates_manual: true }), 0, '2026-12-05')).toContain('進行中，手動排程');
    expect(summaryText({ percent_done: 40, units_behind: 2, next_exam: { label: '段考二', days_left: 7 } }))
      .toEqual({ percent: '完成 40%', behind: '落後 2 個單元', exam: '距離段考二還有 7 天' });
    expect(summaryText({ percent_done: 0, units_behind: 0, next_exam: null }).exam).toBe('計畫期間內沒有後續段考');
    expect(summaryText({ next_exam: { label: '期末', days_left: 0 } }).exam).toBe('期末 就是今天');
  });
});
