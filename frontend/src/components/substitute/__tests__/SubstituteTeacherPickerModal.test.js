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

  // in-app #247: mirror the production payload for 2026-08-29 13:00–15:00.
  // One occupied one-on-three slot with two seats remaining must be selectable.
  it('keeps a one-on-three candidate selectable for the #247 production payload', async () => {
    const wrapper = mount(SubstituteTeacherPickerModal, {
      props: {
        modelValue: false,
        context: {
          student_id: 271,
          student_name: '回歸測試學生',
          class_type: 'one_on_three',
          subject_label: '英文',
          session_date: '2026-08-29',
          start_time: '13:00',
          end_time: '15:00',
          original_teacher_id: 146,
          original_teacher_name: '原授課老師',
          session_campus_id: 9,
        },
        teachers: [{ id: 30, name: '代課老師', branch_ids: [9, 16, 15] }],
        branchNameMap: { 9: '分校#9', 16: '分校#16', 15: '分校#15' },
        fetchAvailability: vi.fn(async () => ({
          busy_slots: [{
            start_time: '13:00',
            end_time: '15:00',
            campus_id: 9,
            class_type: 'one_on_three',
            student_count: 1,
            remaining_capacity: 2,
          }],
        })),
      },
    });

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    const card = wrapper.get('.stp-card');
    expect(card.classes()).not.toContain('stp-card--conflict');
    expect(card.classes()).toContain('stp-card--warn');
    expect(card.attributes('aria-disabled')).toBe('false');

    await card.trigger('click');
    expect(wrapper.get('.stp-btn--primary').element.disabled).toBe(false);
  });
});
