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

  // in-app #205: course-mgmt / calendar must pass student_id so availability
  // excludes this student's own occupancy (otherwise overlapping 1v2 classmate
  // + self schedule → false 已滿／衝堂).
  it('passes excludeStudentId from context.student_id to fetchAvailability', async () => {
    const fetchAvailability = vi.fn(async () => ({
      busy_slots: [{
        start_time: '18:00',
        end_time: '20:00',
        campus_id: 17,
        class_type: 'one_on_two',
        remaining_capacity: 1,
      }],
    }));

    const wrapper = mount(SubstituteTeacherPickerModal, {
      props: {
        modelValue: false,
        context: {
          student_id: 7,
          student_name: '測試學生',
          subject_label: '理化',
          session_date: '2026-07-14',
          start_time: '17:30',
          end_time: '19:30',
          original_teacher_id: 84,
          original_teacher_name: '正班老師',
          session_campus_id: 17,
        },
        teachers: [{ id: 67, name: '代課老師', branch_ids: [17] }],
        branchNameMap: { 17: '測試分校' },
        fetchAvailability,
      },
    });

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(fetchAvailability).toHaveBeenCalled();
    const call = fetchAvailability.mock.calls[0];
    expect(call[0]).toBe(67);
    expect(call[1]).toBe('2026-07-14');
    expect(call[2]).toMatchObject({ excludeStudentId: 7 });
    expect(wrapper.get('.stp-card').classes()).not.toContain('stp-card--conflict');
  });

  it('does not mark a mixed 1v2+1v3 slot full when covering 1v3', async () => {
    const wrapper = mount(SubstituteTeacherPickerModal, {
      props: {
        modelValue: false,
        context: {
          student_id: 341,
          student_name: '一對三學生',
          class_type: 'one_on_three',
          subject_label: '數學',
          session_date: '2026-08-20',
          start_time: '15:00',
          end_time: '17:00',
          original_teacher_id: 71,
          original_teacher_name: '正班老師',
          session_campus_id: 3,
        },
        teachers: [{ id: 80, name: '代課老師', branch_ids: [3] }],
        branchNameMap: { 3: '大直' },
        fetchAvailability: vi.fn(async () => ({
          busy_slots: [
            {
              start_time: '15:00',
              end_time: '17:00',
              campus_id: 3,
              class_type: 'one_on_two',
              remaining_capacity: 0,
            },
            {
              start_time: '15:00',
              end_time: '17:00',
              campus_id: 3,
              class_type: 'one_on_three',
              remaining_capacity: 1,
            },
          ],
        })),
      },
    });

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(wrapper.get('.stp-card').classes()).not.toContain('stp-card--conflict');
    expect(wrapper.get('.stp-card').classes()).toContain('stp-card--warn');
  });

  it('marks mixed slot full for 1v2 cover and says 本分校, not 其他分校', async () => {
    const wrapper = mount(SubstituteTeacherPickerModal, {
      props: {
        modelValue: false,
        context: {
          student_id: 346,
          student_name: '一對二學生',
          class_type: 'one_on_two',
          subject_label: '數學',
          session_date: '2026-08-20',
          start_time: '15:00',
          end_time: '17:00',
          original_teacher_id: 71,
          original_teacher_name: '正班老師',
          session_campus_id: 3,
        },
        teachers: [{ id: 80, name: '代課老師', branch_ids: [3] }],
        branchNameMap: { 3: '大直' },
        fetchAvailability: vi.fn(async () => ({
          busy_slots: [
            {
              start_time: '15:00',
              end_time: '17:00',
              campus_id: 3,
              class_type: 'one_on_two',
              remaining_capacity: 0,
            },
            {
              start_time: '15:00',
              end_time: '17:00',
              campus_id: 3,
              class_type: 'one_on_three',
              remaining_capacity: 1,
            },
          ],
        })),
      },
    });

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(wrapper.get('.stp-card').classes()).toContain('stp-card--conflict');
    const tip = wrapper.get('.stp-cap-tag--full').attributes('title');
    expect(tip).toMatch(/本分校/);
    expect(tip).not.toMatch(/其他分校/);
  });
});
