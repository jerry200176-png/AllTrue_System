import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarLeaveModal from '../CalendarLeaveModal.vue';

const form = { schedule_date: '2026-06-07', day_of_week: 1, start_time: '16:00', end_time: '18:00' };

describe('CalendarLeaveModal', () => {
  it('renders student/subject and original slot when show=true', () => {
    const w = mount(CalendarLeaveModal, {
      props: { show: true, form, studentName: '小明', subjectLabel: '數學', originalSlotLabel: '週一 16:00~18:00' },
    });
    expect(w.text()).toContain('小明');
    expect(w.text()).toContain('數學');
    expect(w.text()).toContain('週一 16:00~18:00');
  });

  it('does not render when show=false', () => {
    const w = mount(CalendarLeaveModal, { props: { show: false, form } });
    expect(w.find('.modal-overlay').exists()).toBe(false);
  });

  it('emits close and submit', async () => {
    const w = mount(CalendarLeaveModal, { props: { show: true, form } });
    await w.find('.ghost').trigger('click');
    expect(w.emitted('close')).toHaveLength(1);
    await w.find('.primary').trigger('click');
    expect(w.emitted('submit')).toHaveLength(1);
  });
});
