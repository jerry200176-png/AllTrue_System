import { describe, it, expect } from 'vitest';
import { scheduleBlockerLabel, uniqueScheduleBlockers } from './scheduleDisplay.js';

describe('schedule blockers (#4991 line 3)', () => {
  it('keeps one entry per source row across conflicts', () => {
    const a = { source: 'class_session', source_id: 1 };
    const b = { source: 'schedule', source_id: 1 };
    expect(uniqueScheduleBlockers([{ overlap_details: [a, b] }, { overlap_details: [a] }, {}])).toEqual([a, b]);
    expect(uniqueScheduleBlockers(undefined)).toEqual([]);
  });

  it('labels the blocker with the date, time and contract', () => {
    expect(scheduleBlockerLabel({ student_name: '甲', course_period: '9/1期', start_time: '16:00:00', end_time: '17:00:00', same_contract: false }, '2026-11-20'))
      .toBe('甲（9/1 開始），2026-11-20 16:00–17:00（另一份合約的課）');
  });
});
