import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { courseLessonRows, courseLessonView, formatLessonDate, LESSON_PREVIEW_LIMIT } from '../../lib/parentCourseLessons';

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
    expect(formatLessonDate(null)).toBe('日期未記錄');
  });

  it('never repeats 已上 in the summary and caps the preview (review #4354)', () => {
    const lessons = Array.from({ length: 12 }, (_, i) => ({ date: `2026-09-${String(30 - i).padStart(2, '0')}` }));
    const view = courseLessonView({ sessions_purchased: 10, attended_lessons: lessons });
    expect(view.summary).toBe('查看上課日期（12 筆）');
    expect(view.summary).not.toContain('已上');
    expect(view.rows).toHaveLength(LESSON_PREVIEW_LIMIT);
    expect(view.hiddenCount).toBe(2);
    expect(view.overPurchasedNote).toBe('另有 2 堂超出購買堂數，請洽分校確認');
    expect(courseLessonView({ sessions_purchased: 10, attended_lessons: lessons }, true).rows).toHaveLength(12);
    const pkg = courseLessonView({ is_package: true, sessions_purchased: 4, attended_lessons: lessons.slice(0, 5) });
    expect(pkg.summary).toBe('本科上課日期（5 筆）');
    expect(pkg.overPurchasedNote).toBe('');
  });

  it('renders the list under count-mode course cards only', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const source = readFileSync(resolve(here, '../../pages/ParentPortal.vue'), 'utf8');
    const countBlock = source.slice(source.indexOf('<!-- 堂數制 -->'), source.indexOf('<!-- 月結制 -->'));
    expect(countBlock).toContain('lessonViews[c.id]');
    expect(countBlock).toContain('pp-lesson-dates');
    expect(countBlock).toContain('完整出缺勤紀錄');
    expect(source).toContain("document.getElementById('pp-attendance-section')");
  });
});
