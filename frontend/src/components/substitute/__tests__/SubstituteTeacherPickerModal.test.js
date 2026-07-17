import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SubstituteTeacherPickerModal from '../SubstituteTeacherPickerModal.vue';

describe('SubstituteTeacherPickerModal drag prefill', () => {
  it('prefills the target teacher and allows a same-date historical time correction', async () => {
    const wrapper = mount(SubstituteTeacherPickerModal, {
      props: {
        modelValue: false,
        context: {
          student_name: '測試學生',
          subject_label: '數學',
          session_date: '2020-01-01',
          start_time: '17:30',
          end_time: '19:30',
          original_teacher_id: 5,
          original_teacher_name: '正班老師',
          session_campus_id: 2,
          prefill_substitute_teacher_id: 8,
          prefill_new_date: '2020-01-01',
          prefill_new_start_time: '18:00',
          prefill_new_end_time: '20:00',
          allow_past_same_date: true,
        },
        teachers: [{ id: 8, name: '代課老師', branch_ids: [2] }],
        branchNameMap: { 2: '測試分校' },
        fetchAvailability: vi.fn(async () => ({
          busy_slots: [{
            start_time: '18:00',
            end_time: '20:00',
            campus_id: 2,
            remaining_capacity: 0,
          }],
        })),
      },
    });

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    const dateInput = wrapper.get('input[type="date"]');
    expect(dateInput.element.value).toBe('2020-01-01');
    expect(dateInput.attributes('min')).toBe('2020-01-01');
    expect(wrapper.get('.stp-card').classes()).toContain('stp-card--selected');
    expect(wrapper.get('.stp-card').classes()).not.toContain('stp-card--conflict');
    expect(wrapper.get('.stp-actions__summary').text()).toContain('2020-01-01 18:00~20:00');

    await wrapper.get('.stp-btn--primary').trigger('click');
    expect(wrapper.emitted('submit')?.[0]?.[0]).toMatchObject({
      substitute_teacher_id: 8,
      new_date: '2020-01-01',
      new_start_time: '18:00',
      new_end_time: '20:00',
    });
  });
});
