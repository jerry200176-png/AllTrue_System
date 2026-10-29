import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ManualSessionModal from '../ManualSessionModal.vue';

const props = () => ({ show: true, today: '2026-09-29', isMonthly: true,
  form: { session_date: '2026-10-01', start_time: '18:00' },
  result: { can_add: false, error_code: 'AFTER_COURSE_END',
    next_period: { source_end: '2026-09-30', candidates: [] } } });

describe('monthly cross-period booking', () => {
  it('guides next unpaid contract preview without an old-course extension action', async () => {
    const wrapper = mount(ManualSessionModal, { props: props() });
    expect(wrapper.text()).toContain('預覽建立下一期未繳費合約');
    expect(wrapper.find('.manual-session-edit-course').exists()).toBe(false);
    await wrapper.find('.manual-session-next-period button').trigger('click');
    expect(wrapper.emitted('next-period')).toEqual([[null]]);
    expect(wrapper.emitted('submit')).toBeUndefined();
    wrapper.unmount();
  });
  it('selects an existing next contract and keeps booking disabled until a new check', async () => {
    const input = props();
    input.result.next_period.candidates = [{ id: 2, start_date: '2026-10-01', end_date: '2026-10-31' }];
    const wrapper = mount(ManualSessionModal, { props: input });
    await wrapper.find('.manual-session-next-period button').trigger('click');
    expect(wrapper.emitted('next-period')).toEqual([[2]]);
    expect(wrapper.find('.actions .at-submit').attributes('disabled')).toBeDefined();
    await wrapper.setProps({ submitting: true });
    expect(wrapper.find('input').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });
});

describe('schedule conflict notice', () => {
  const conflictProps = () => ({ show: true, today: '2026-09-29', isMonthly: false,
    form: { session_date: '2026-10-14', start_time: '16:00' },
    result: { can_add: false, message: '老師或教室在這個時段已有安排，請改選其他時段',
      conflict_detail: { type: 'teacher_capacity' },
      conflicts: [{ overlap_details: [
        { source: 'class_session', source_id: 5, course_id: 77, student_name: '王小明', subject_name: '數學', start_time: '16:00', end_time: '18:00' },
        { source: 'class_session', source_id: 5, course_id: 77, student_name: '王小明', subject_name: '數學', start_time: '16:00', end_time: '18:00' },
      ] }] } });
  it('names the blocking student, subject and date once and offers both actions', async () => {
    const wrapper = mount(ManualSessionModal, { props: conflictProps(), attachTo: document.body });
    const items = wrapper.findAll('.manual-session-blockers li');
    expect(items).toHaveLength(1);
    expect(items[0].text()).toContain('王小明・數學，2026-10-14 16:00–18:00');
    await wrapper.find('.manual-session-open-blocker').trigger('click');
    expect(wrapper.emitted('open-course')[0][0].course_id).toBe(77);
    await wrapper.find('.manual-session-change-slot').trigger('click');
    expect(document.activeElement).toBe(wrapper.find('input[type="date"]').element);
    expect(wrapper.emitted('submit')).toBeUndefined();
    wrapper.unmount();
  });
  it('falls back to the plain detail when the server sends no overlap rows', () => {
    const input = conflictProps();
    input.result.conflicts = [];
    const wrapper = mount(ManualSessionModal, { props: input });
    expect(wrapper.find('.manual-session-blockers').exists()).toBe(false);
    expect(wrapper.text()).toContain('衝堂詳情');
    wrapper.unmount();
  });
});
