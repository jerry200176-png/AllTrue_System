import { describe, expect, it } from 'vitest';
import {
  calendarViewLabel,
  dayViewScrollTop,
  formatCalendarRange,
  scheduleDiscrepancyActionLabel,
} from '../../../lib/calendarViewDisplay.js';

describe('calendarViewDisplay', () => {
  it('formats a local calendar range without UTC date drift', () => {
    expect(formatCalendarRange('2026-08-03', '2026-08-09')).toBe('2026/8/3–2026/8/9');
  });

  it('formats a single-day range and missing dates safely', () => {
    expect(formatCalendarRange('2026-08-03', '2026-08-03')).toBe('2026/8/3');
    expect(formatCalendarRange('', '')).toBe('尚未選定日期');
  });

  it('labels the active calendar surface in user language', () => {
    expect(calendarViewLabel({ viewMode: 'week', isWeekOverview: false })).toBe('日檢視');
    expect(calendarViewLabel({ viewMode: 'week', isWeekOverview: true })).toBe('週檢視');
    expect(calendarViewLabel({ viewMode: 'teacher' })).toBe('老師清單');
  });

  it('maps discrepancy status to an imperative next step', () => {
    expect(scheduleDiscrepancyActionLabel('pending')).toBe('接手處理');
    expect(scheduleDiscrepancyActionLabel('acknowledged')).toBe('繼續處理');
    expect(scheduleDiscrepancyActionLabel('resolved')).toBe('查看處理結果');
    expect(scheduleDiscrepancyActionLabel('withdrawn')).toBe('查看回報');
  });

  it('scrolls to one hour above the earliest lesson, default 14:00 when empty', () => {
    expect(dayViewScrollTop([19, 17, 18], 8)).toBe(8 * 56); // 16:00
    expect(dayViewScrollTop([], 8)).toBe(6 * 56); // 14:00
    expect(dayViewScrollTop([8, 9], 8)).toBe(0); // clamp at first hour
    expect(dayViewScrollTop([NaN], 8)).toBe(6 * 56);
  });
});
