import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/SmartCalendar.vue'), 'utf8');

// In-app #364: 「只看有課老師」 must use the same predicate as the day grid,
// otherwise a course outside the rendered hours leaves an empty teacher column.
describe('SmartCalendar has-course filter', () => {
  it('derives has-course from the renderable grid hours', () => {
    const fn = source.slice(source.indexOf('const teacherHasCourseToday'), source.indexOf('const withBusyFlag'));
    expect(fn).toContain('hours.some(');
    expect(fn).toContain('getCoursesForAliasSetAt(aliasSet, h)');
  });

  it('keeps the grid cell and the filter on one shared matcher', () => {
    expect(source).toContain('const getCoursesForTeacherAt = (teacherId, hour) => getCoursesForAliasSetAt(getTeacherAliasIdSet(teacherId), hour);');
  });
});
