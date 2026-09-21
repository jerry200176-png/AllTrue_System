import { describe, expect, it } from 'vitest';
import {
  buildStudentCourseSessionPreview,
  formatStudentCourseSessionDate,
  sessionDateKey,
  studentCourseSessionStatusLabel,
} from './studentCourseSessionDisplay.js';

const sessions = (courseId, count) => Array.from({ length: count }, (_, index) => ({
  id: courseId * 100 + index + 1,
  studentClassId: courseId,
  date: `2026-09-${String(index + 1).padStart(2, '0')}`,
  startTime: '16:00',
  endTime: '18:00',
  status: index === 0 ? 'attended' : 'scheduled',
}));

describe('student course session display', () => {
  it('previews three dates and exposes the remaining four for a seven-session contract', () => {
    const rows = sessions(101, 7);
    expect(buildStudentCourseSessionPreview(rows)).toMatchObject({ total: 7, overflow: 4 });
    expect(buildStudentCourseSessionPreview(rows).visible).toHaveLength(3);
    expect(buildStudentCourseSessionPreview(rows, true).visible).toHaveLength(7);
  });

  it('previews three dates and exposes the remaining one for a four-session contract', () => {
    const rows = sessions(102, 4);
    expect(buildStudentCourseSessionPreview(rows)).toMatchObject({ total: 4, overflow: 1 });
    expect(buildStudentCourseSessionPreview(rows, true).visible).toHaveLength(4);
  });

  it('keeps same-subject contracts keyed independently', () => {
    expect(sessionDateKey(7, 101)).not.toBe(sessionDateKey(7, 102));
    expect(sessions(101, 2)[0].studentClassId).toBe(101);
    expect(sessions(102, 2)[0].studentClassId).toBe(102);
  });

  it('uses the required full date/time shape and canonical status labels', () => {
    expect(formatStudentCourseSessionDate(sessions(101, 1)[0])).toBe('2026-09-01（週二） 16:00–18:00');
    expect(studentCourseSessionStatusLabel({ status: 'attended' })).toBe('已上');
    expect(studentCourseSessionStatusLabel({ status: 'cancelled' })).toBe('已取消');
    expect(studentCourseSessionStatusLabel({ status: 'unknown_future_status' })).toBe('');
  });
});
