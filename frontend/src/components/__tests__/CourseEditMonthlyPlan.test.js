import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { monthlyScheduleEstimate } from '../../lib/monthlyScheduleEstimate.js';
import CourseEditForm from '../CourseEditForm.vue';

vi.mock('../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));

const course = {
  subject: 'Math', teacher_id: '', payment_type: 'monthly', scheduling_policy: 'auto_recurrence',
  first_class_date: '2026-04-01', end_date: '2026-05-31', days_of_week: [5],
  day_time_slots: [{ day: 5, start_time: '16:00', duration_hours: 2 }], monthly_sessions: null,
};

describe('monthly course planning estimate', () => {
  it('counts the selected calendar month and handles four and five Fridays', () => {
    expect(monthlyScheduleEstimate({ startDate: course.first_class_date, endDate: course.end_date, slots: course.day_time_slots, asOf: new Date(2026, 3, 15) })).toEqual({ month: '2026-04', count: 4 });
    expect(monthlyScheduleEstimate({ startDate: course.first_class_date, endDate: course.end_date, slots: course.day_time_slots, asOf: new Date(2026, 4, 15) })).toEqual({ month: '2026-05', count: 5 });
    expect(monthlyScheduleEstimate({ startDate: course.first_class_date, endDate: course.end_date, slots: [], weekdays: [], asOf: new Date(2026, 4, 15) })).toBeNull();
    expect(monthlyScheduleEstimate({ startDate: '2026-05-04', endDate: '2026-05-04', weekdays: [5], asOf: new Date(2026, 4, 4) })).toBeNull();
  });

  it('auto fills a recurring edit and keeps an explicit exception until reset', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 4, 15));
    try {
      const wrapper = mount(CourseEditForm, { props: { modelValue: { ...course } }, global: { stubs: { TeacherAvailabilityPlanner: true, CoursePaymentDateField: true } } });
      await flushPromises();
      expect(wrapper.text()).toContain('2026-05 固定時段估計 5 堂');
      expect(wrapper.vm.form.monthly_sessions).toBe(5);
      expect(wrapper.emitted('update:modelValue').at(-1)[0].monthly_sessions).toBe(5);
      expect(wrapper.find('input[aria-label="本月規劃堂數"]').exists()).toBe(false);
      wrapper.vm.form.day_time_slots = [{ day: 3, start_time: '16:00', duration_hours: 2 }];
      await flushPromises();
      expect(wrapper.vm.form.monthly_sessions).toBe(4);
      await wrapper.find('.monthly-plan-action').trigger('click');
      await wrapper.find('input[aria-label="手動修正本月規劃堂數"]').setValue('7');
      expect(wrapper.vm.form.monthly_sessions).toBe(7);
      expect(wrapper.emitted('update:modelValue').at(-1)[0].monthly_sessions).toBe(7);
      await wrapper.find('.monthly-plan-action').trigger('click');
      expect(wrapper.vm.form.monthly_sessions).toBe(4);
      wrapper.unmount();
    } finally {
      vi.useRealTimers();
    }
  });

  it('preserves a stored exception and requires input for manual scheduling', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 4, 15));
    try {
      const wrapper = mount(CourseEditForm, { props: { modelValue: { ...course, monthly_sessions: 7 } }, global: { stubs: { TeacherAvailabilityPlanner: true, CoursePaymentDateField: true } } });
      await flushPromises();
      expect(wrapper.vm.form.monthly_sessions).toBe(7);
      expect(wrapper.find('input[aria-label="手動修正本月規劃堂數"]').exists()).toBe(true);
      await wrapper.setProps({ modelValue: { ...course, scheduling_policy: 'manual_occurrence', monthly_sessions: 7 } });
      await flushPromises();
      expect(wrapper.vm.form.monthly_sessions).toBe(7);
      expect(wrapper.text()).toContain('無法依固定時段估計');
      wrapper.unmount();
    } finally {
      vi.useRealTimers();
    }
  });
});
