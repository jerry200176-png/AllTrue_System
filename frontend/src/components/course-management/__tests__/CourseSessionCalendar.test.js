import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CourseSessionCalendar from '../CourseSessionCalendar.vue';

function mountCalendar(overrides = {}) {
  return mount(CourseSessionCalendar, {
    props: {
      course: {
        id: 101,
        payment_type: 'session',
        status: 'active',
        scheduling_policy: 'auto_recurrence',
        start_time: '16:00',
        ...(overrides.course || {}),
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
  it('renders materialized and projected labels without a cancel control', () => {
    const wrapper = mountCalendar();
    expect(wrapper.find('[data-testid="course-session-calendar"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="csc-day-2026-09-10"]').text()).toContain('已排');
    expect(wrapper.find('[data-testid="csc-day-2026-09-24"]').text()).toContain('預排');
    expect(wrapper.text()).toMatch(/取消.*尚未開放/);
    expect(wrapper.text()).not.toMatch(/取消這一堂|確認取消|status=cancelled/);
    expect(wrapper.findAll('button').every((b) => !/取消堂次|取消排課/.test(b.text()))).toBe(true);
  });

  it('emits create-day for an empty future date and routes through existing writers only', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-day-2026-09-25"]').trigger('click');
    expect(wrapper.emitted('create-day')).toBeTruthy();
    expect(wrapper.emitted('create-day')[0][0]).toEqual({ date: '2026-09-25' });
  });

  it('does not emit create-day for past empty days or occupied days', async () => {
    const wrapper = mountCalendar();
    await wrapper.find('[data-testid="csc-day-2026-09-11"]').trigger('click');
    await wrapper.find('[data-testid="csc-day-2026-09-10"]').trigger('click');
    await wrapper.find('[data-testid="csc-day-2026-09-24"]').trigger('click');
    expect(wrapper.emitted('create-day')).toBeFalsy();
  });

  it('hides create affordance when createEnabled is false (read-only / flag-off path)', async () => {
    const wrapper = mountCalendar({ createEnabled: false });
    expect(wrapper.find('[data-testid="csc-day-2026-09-25"]').attributes('disabled')).toBeDefined();
    await wrapper.find('[data-testid="csc-day-2026-09-25"]').trigger('click');
    expect(wrapper.emitted('create-day')).toBeFalsy();
  });

  it('offers quick-add secondary action for count-mode courses', async () => {
    const wrapper = mountCalendar();
    expect(wrapper.find('[data-testid="csc-quick-add"]').exists()).toBe(true);
    await wrapper.find('[data-testid="csc-quick-add"]').trigger('click');
    expect(wrapper.emitted('quick-add')).toBeTruthy();
  });
});
