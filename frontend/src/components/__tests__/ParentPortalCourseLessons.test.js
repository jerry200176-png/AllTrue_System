import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { courseLessonRows, formatLessonDate } from '../../lib/parentCourseLessons';

describe('parent course lesson dates (in-app #418)', () => {
  it('numbers lessons from the first one so the newest matches the 已上 count', () => {
    const course = {
      used_sessions: 3,
      attended_lessons: [
        { date: '2026-10-14', time: '16:00-17:30', note: '缺席（已扣堂）' },
        { date: '2026-10-07', time: '16:00-17:30', note: null },
        { date: '2026-09-30', time: null, note: null },
      ],
    };
    const rows = courseLessonRows(course);
    expect(rows.map((r) => r.number)).toEqual([3, 2, 1]);
    expect(rows[0].number).toBe(course.used_sessions);
    expect(rows[0]).toMatchObject({ dateLabel: '10/14（三）', time: '16:00-17:30', note: '缺席（已扣堂）' });
    expect(rows[2]).toMatchObject({ dateLabel: '9/30（三）', time: '', note: '' });
  });

  it('keeps each package member to its own dates', () => {
    const math = { is_package: true, used_sessions: 3, attended_lessons: [{ date: '2026-10-19' }, { date: '2026-10-12' }] };
    const sci = { is_package: true, used_sessions: 3, attended_lessons: [{ date: '2026-10-16' }] };
    expect(courseLessonRows(math)).toHaveLength(2);
    expect(courseLessonRows(sci)).toHaveLength(1);
  });

  it('is empty for monthly courses or older payloads without the field', () => {
    expect(courseLessonRows({ attended_lessons: null })).toEqual([]);
    expect(courseLessonRows({})).toEqual([]);
    expect(formatLessonDate('bad')).toBe('bad');
  });

  it('renders the list under count-mode course cards only', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const source = readFileSync(resolve(here, '../../pages/ParentPortal.vue'), 'utf8');
    const countBlock = source.slice(source.indexOf('<!-- 堂數制 -->'), source.indexOf('<!-- 月結制 -->'));
    expect(countBlock).toContain('courseLessonRows(c)');
    expect(countBlock).toContain('pp-lesson-dates');
  });
});
