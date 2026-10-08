import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';
import CourseBlockContent from '../calendar/CourseBlockContent.vue';
import { lessonStatusBadge } from '../../lib/lessonStatusBadge.js';

// in-app #342: calendar lessons say what happened, and the contract's full lesson list is one click away.
const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const calendar = read('../../pages/SmartCalendar.vue');
const courseMgmt = read('../../pages/CourseManagement.vue');
const modal = read('../calendar/modals/CalendarSessionEditModal.vue');

describe('calendar lesson status (in-app #342)', () => {
  it('the cell badge carries the words for screen readers, not only the glyph', () => {
    const badge = lessonStatusBadge({ status: 'scheduled', session_date: '2099-01-01', end_time: '18:00' });
    const w = mount(CourseBlockContent, {
      props: { course: { id: 1, start_time: '16:00', end_time: '18:00' }, badges: { rollCall: badge, evalMissing: null, teacherTag: null } },
      global: { stubs: { teleport: true } },
    });
    const el = w.find('.rc-upcoming');
    expect(el.exists()).toBe(true);
    expect(el.attributes('aria-label')).toBe('還沒上');
  });

  it('the calendar uses the shared helper and legend', () => {
    expect(calendar).toContain('return lessonStatusBadge(findSessionRowForCell(course, ymd));');
    expect(calendar).toContain('v-for="b in LESSON_STATUS_LEGEND"');
  });

  it('「看全部堂次」 opens the course manager on 排課與堂次', () => {
    expect(modal).toContain("@click=\"$emit('all-lessons')\"");
    expect(calendar).toContain("emit('navigate', buildCourseMgmtOpsNav(c, { intent: 'lessons' }));");
    expect(courseMgmt).toContain("} else if (props.initialCourseIntent === 'lessons') {");
    expect(courseMgmt).toContain("if (course) openCourseManager(course, 'sessions');");
  });
});
