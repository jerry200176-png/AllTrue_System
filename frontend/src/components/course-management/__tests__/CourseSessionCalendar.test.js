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
    expect(wrapper.text()).toMatch(/點空白的未來日期可新增堂次/);
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

  it('offers quick-add for count-mode courses by default', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-quick-add"]').trigger('click');
    expect(wrapper.emitted('quick-add')).toBeTruthy();
  });

  it('hides quick-add when showQuickAdd is false (Course Manager)', () => {
    const wrapper = mount(CourseSessionCalendar, {
      props: {
        course: { id: 101, payment_type: 'session', status: 'active', scheduling_policy: 'auto_recurrence' },
        sessions: [],
        createEnabled: true,
        showQuickAdd: false,
        todayYmd: '2026-09-17',
        initialYear: 2026,
        initialMonth: 9,
      },
    });
    expect(wrapper.find('[data-testid="csc-quick-add"]').exists()).toBe(false);
  });

  it('emits select-day for occupied dates without mutations', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-day-2026-09-10"]').trigger('click');
    expect(wrapper.emitted('select-day')[0][0]).toEqual({ date: '2026-09-10' });
    expect(wrapper.emitted('create-day')).toBeFalsy();
  });
});

describe('CourseSessionCalendar times and drag', () => {
  const sessions = [
    { date: '2026-09-24', id: 7, startTime: '19:00:00', status: 'scheduled' },
    { date: '2026-09-10', id: 1, startTime: '16:00', status: 'attended' },
    { date: '2026-09-29', id: 8, startTime: '18:00' },
    { date: '2026-09-29', id: 9, startTime: '10:00' },
    { date: '2026-09-30', id: 10, startTime: '17:00' },
  ];
  const mountWith = (extra = {}) => {
    const w = mountCalendar({ sessions });
    return { w, set: (p) => w.setProps(p) , ...extra };
  };

  it('shows the start time, and first time plus count on a multi-lesson day', () => {
    const { w } = mountWith();
    expect(w.find('[data-testid="csc-time-2026-09-24"]').text()).toBe('19:00');
    expect(w.find('[data-testid="csc-time-2026-09-29"]').text()).toBe('10:00 +1');
  });

  it('shows a non-normal state word from stateLabel', async () => {
    const { w } = mountWith();
    await w.setProps({ stateLabel: (s) => (s.id === 7 ? '請假' : '已上') });
    expect(w.find('[data-testid="csc-day-2026-09-24"]').text()).toContain('請假');
    expect(w.find('[data-testid="csc-day-2026-09-10"]').text()).not.toContain('已上');
  });

  it('drag emits move-session; past dates and locked lessons are not draggable or droppable', async () => {
    const { w } = mountWith();
    await w.setProps({ canMoveSession: (s) => s.id !== 10 });
    const src = w.find('[data-testid="csc-day-2026-09-24"]');
    expect(src.attributes('draggable')).toBe('true');
    expect(w.find('[data-testid="csc-day-2026-09-30"]').attributes('draggable')).toBeUndefined(); // locked
    expect(w.find('[data-testid="csc-day-2026-09-10"]').attributes('draggable')).toBeUndefined(); // past
    expect(w.find('[data-testid="csc-day-2026-09-29"]').attributes('draggable')).toBeUndefined(); // 2 lessons
    await src.trigger('dragstart');
    await w.find('[data-testid="csc-day-2026-09-11"]').trigger('drop'); // past
    expect(w.emitted('move-session')).toBeFalsy();
    await w.find('[data-testid="csc-day-2026-09-25"]').trigger('drop');
    expect(w.emitted('move-session')[0][0]).toEqual({ sessionId: 7, fromDate: '2026-09-24', toDate: '2026-09-25' });
  });

  it('has no drag at all unless the parent passes canMoveSession', () => {
    const { w } = mountWith();
    expect(w.find('[data-testid="csc-day-2026-09-24"]').attributes('draggable')).toBeUndefined();
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
