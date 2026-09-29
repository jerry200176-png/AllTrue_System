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
    expect(wrapper.find('.actions .primary').attributes('disabled')).toBeDefined();
    await wrapper.setProps({ submitting: true });
    expect(wrapper.find('input').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });
});
