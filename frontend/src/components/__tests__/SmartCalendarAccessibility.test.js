import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/SmartCalendar.vue'), 'utf8');

describe('SmartCalendar accessibility contracts', () => {
  it('connects the calendar view tabs to labelled panels', () => {
    expect(source).toContain('id="calendar-tab-week"');
    expect(source).toContain('aria-controls="calendar-panel-week"');
    expect(source).toContain('id="calendar-tab-teacher"');
    expect(source).toContain('aria-controls="calendar-panel-teacher"');
    expect(source).toContain('role="tabpanel" aria-labelledby="calendar-tab-week"');
    expect(source).toContain('role="tabpanel" aria-labelledby="calendar-tab-teacher"');
  });

  it('gives date, filter, and view controls explicit names and state', () => {
    expect(source).toContain('role="group" aria-labelledby="calendar-month-label"');
    expect(source).toContain('aria-live="polite"');
    expect(source).toContain('<label class="toolbar-label" for="calendar-jump-date">跳至日期</label>');
    expect(source).toContain('id="calendar-jump-date"');
    expect(source).toContain('aria-label="依教室篩選"');
    expect(source).toContain('aria-label="搜尋老師"');
    expect(source).toContain('aria-label="搜尋學生"');
    expect(source).toContain('role="group" aria-label="日／週檢視"');
    expect(source).toContain(':aria-pressed="!isWeekOverview"');
    expect(source).toContain(':aria-pressed="isWeekOverview"');
  });

  it('uses a native teacher disclosure with an explicit content relationship', () => {
    expect(source).toContain('class="teacher-card-header"');
    expect(source).toContain(':aria-expanded="group.open"');
    expect(source).toContain(':aria-controls="`teacher-courses-${group.teacher_id}`"');
    expect(source).toContain(':id="`teacher-courses-${group.teacher_id}`"');
    expect(source).toContain('role="region"');
    expect(source).toContain(':aria-labelledby="`teacher-toggle-${group.teacher_id}`"');
    expect(source).toContain('aria-hidden="true"');
  });

  it('keeps the existing calendar data and capacity paths intact', () => {
    expect(source).toContain('mergeWeekCalendarOccurrences');
    expect(source).toContain('getSlotOccupancy');
    expect(source).toContain('onSlotClick');
  });
});
