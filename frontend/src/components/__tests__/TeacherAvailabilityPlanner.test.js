import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TeacherAvailabilityPlanner from '../TeacherAvailabilityPlanner.vue';
import CourseEditForm from '../CourseEditForm.vue';

const __dirname = dirname(fileURLToPath(import.meta.url));

function ymd(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function futureDate() { const date = new Date(); date.setDate(date.getDate() + 1); return date; }

function plannerProps(fetchAvailability, overrides = {}) {
  const date = futureDate(); const weekday = date.getDay() || 7;
  return { teacherId: 7, teacher: { id: 7, name: '老師甲', branch_ids: [3] }, studentId: 42, branchId: 3, classType: 'one_on_one', paymentType: 'monthly', startDate: ymd(date), daysOfWeek: [weekday], dayTimeSlots: [{ day: weekday, start_time: '16:00', duration_hours: 1 }], durationHours: 1, fetchAvailability, ...overrides };
}

describe('TeacherAvailabilityPlanner', () => {
  it('queries availability and emits an applicable recurring candidate', async () => {
    const fetchAvailability = vi.fn(async () => ({ busy_slots: [] }));
    const props = plannerProps(fetchAvailability); const wrapper = mount(TeacherAvailabilityPlanner, { props });
    await wrapper.get('.coordination-search-button').trigger('click'); await flushPromises();
    expect(fetchAvailability).toHaveBeenCalledTimes(4);
    expect(fetchAvailability.mock.calls[0][0]).toBe(7);
    expect(fetchAvailability.mock.calls[0][2]).toEqual({ excludeStudentId: 42 });
    expect(wrapper.find('.coordination-candidate').exists()).toBe(true);
    await wrapper.get('.coordination-candidate').trigger('click');
    expect(wrapper.emitted('apply')?.[0]?.[0]).toMatchObject({ weekday: props.daysOfWeek[0], start_time: '16:00', end_time: '17:00' });
  });

  it('invalidates old results when teacher, date, or fixed time changes', async () => {
    const fetchAvailability = vi.fn(async () => ({ busy_slots: [] }));
    const wrapper = mount(TeacherAvailabilityPlanner, { props: plannerProps(fetchAvailability) });
    await wrapper.get('.coordination-search-button').trigger('click'); await flushPromises();
    expect(wrapper.find('.coordination-candidate').exists()).toBe(true);

    const nextDate = futureDate(); nextDate.setDate(nextDate.getDate() + 7);
    await wrapper.setProps({
      teacherId: 8,
      teacher: { id: 8, name: '老師乙', branch_ids: [3] },
      startDate: ymd(nextDate),
      dayTimeSlots: [{ day: nextDate.getDay() || 7, start_time: '18:00', duration_hours: 1.5 }],
      daysOfWeek: [nextDate.getDay() || 7],
    });
    expect(wrapper.find('[data-testid="teacher-availability-stale"]').exists()).toBe(true);
    expect(wrapper.find('.coordination-candidate').exists()).toBe(false);

    await wrapper.get('.coordination-search-button').trigger('click'); await flushPromises();
    const latestCall = fetchAvailability.mock.calls.at(-1); expect(latestCall[0]).toBe(8); expect(latestCall[2]).toEqual({ excludeStudentId: 42 });
  });

  it('is mounted by both create and edit scheduling forms', () => {
    for (const file of ['../UniversalClassScheduler.vue', '../CourseEditForm.vue']) expect(readFileSync(resolve(__dirname, file), 'utf8')).toContain('<TeacherAvailabilityPlanner');
    expect(readFileSync(resolve(__dirname, '../CourseEditForm.vue'), 'utf8')).toContain('default: fetchTeacherAvailability');
  });

  it('lets the edit form apply a current candidate back to its schedule model', async () => {
    const fetchAvailability = vi.fn(async () => ({ busy_slots: [] })); const date = futureDate(); const weekday = date.getDay() || 7;
    const wrapper = mount(CourseEditForm, {
      props: {
        modelValue: { student_id: 42, teacher_id: 7, subject: 'Math', class_type: 'one_on_one', first_class_date: ymd(date), days_of_week: [weekday], day_time_slots: [{ day: weekday, start_time: '16:00', duration_hours: 1 }], duration_hours: 1 },
        branchId: 3, teachers: [{ id: 7, username: '老師甲', branch_ids: [3] }],
        subjects: [{ value: 'Math', label: '數學' }],
        dayOptions: [{ value: weekday, label: weekday === 7 ? '日' : String(weekday) }],
        settlementDayOptions: [],
        fetchAvailability,
      },
    });

    await wrapper.get('.coordination-search-button').trigger('click'); await flushPromises();
    await wrapper.get('.coordination-candidate').trigger('click');
    const updates = wrapper.emitted('update:modelValue') || []; expect(updates.at(-1)?.[0]).toMatchObject({ student_id: 42, days_of_week: [weekday], start_time: '16:00' });
  });
});
