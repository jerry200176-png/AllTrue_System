import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import CourseSessionCalendar from '../CourseSessionCalendar.vue';
import perfFlags from '../../../lib/perfFlags.js';
import { isCourseSessionCalendarEnabled } from '../../../composables/course-management/useCourseSessionCalendar.js';

function mountCalendar(overrides = {}) {
  return mount(CourseSessionCalendar, {
    props: {
      course: {
        id: 101, payment_type: 'session', status: 'active',
        scheduling_policy: 'auto_recurrence', start_time: '16:00', ...(overrides.course || {}),
      },
      sessions: overrides.sessions || [
        { date: '2026-09-10', isProjected: false, id: 1, status: 'attended' },
        { date: '2026-09-24', isProjected: true, id: null, status: 'projected' },
      ],
      createEnabled: overrides.createEnabled ?? true,
      todayYmd: overrides.todayYmd || '2026-09-17',
      initialYear: 2026,
      initialMonth: 9,
    },
  });
}

describe('CourseSessionCalendar', () => {
  it('renders materialized/projected labels and has no cancel control', () => {
    const wrapper = mountCalendar();
    expect(wrapper.find('[data-testid="csc-day-2026-09-10"]').text()).toContain('已排');
    expect(wrapper.find('[data-testid="csc-day-2026-09-24"]').text()).toContain('預排');
    expect(wrapper.text()).toMatch(/取消.*尚未開放/);
    expect(wrapper.findAll('button').every((b) => !/取消堂次|取消排課/.test(b.text()))).toBe(true);
  });

  it('emits create-day only for empty future dates', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-day-2026-09-25"]').trigger('click');
    expect(wrapper.emitted('create-day')[0][0]).toEqual({ date: '2026-09-25' });
    await wrapper.find('[data-testid="csc-day-2026-09-11"]').trigger('click');
    await wrapper.find('[data-testid="csc-day-2026-09-10"]').trigger('click');
    expect(wrapper.emitted('create-day')).toHaveLength(1);
  });

  it('hides create when createEnabled is false', async () => {
    const wrapper = mountCalendar({ createEnabled: false });
    expect(wrapper.find('[data-testid="csc-day-2026-09-25"]').attributes('disabled')).toBeDefined();
    await wrapper.find('[data-testid="csc-day-2026-09-25"]').trigger('click');
    expect(wrapper.emitted('create-day')).toBeFalsy();
  });

  it('offers quick-add for count-mode courses', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-quick-add"]').trigger('click');
    expect(wrapper.emitted('quick-add')).toBeTruthy();
  });
});

describe('CourseSessionCalendar flag gate + CourseManagement host', () => {
  let original;
  beforeEach(() => { original = perfFlags.COURSE_SESSION_CALENDAR_V1; });
  afterEach(() => { perfFlags.COURSE_SESSION_CALENDAR_V1 = original; });

  it('defaults OFF', () => {
    expect(perfFlags.COURSE_SESSION_CALENDAR_V1).toBe(false);
    expect(isCourseSessionCalendarEnabled(perfFlags)).toBe(false);
  });

  it('gates CM entry and reuses existing writers (no cancel calendar path)', () => {
    const source = readFileSync(resolve(__dirname, '../../../pages/CourseManagement.vue'), 'utf8');
    expect(source).toContain('isCourseSessionCalendarEnabled');
    expect(source).toContain('v-if="courseSessionCalendarEnabled"');
    expect(source).toContain('openManualSessionModal(course, date ? { date } : null)');
    expect(source).not.toMatch(/calendar-sessions|openCourseSessionCalendarCancel/);
  });
});
