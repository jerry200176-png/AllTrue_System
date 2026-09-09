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
    expect(w.find('.primary').attributes('disabled')).toBeDefined();
    await w.find('.impact-confirm input').setValue(true);
    await w.find('.primary').trigger('click');
    expect(w.emitted('submit')).toHaveLength(1);
  });

  it('renders the authoritative impact preview and inline submit error', () => {
    const w = mount(CalendarLeaveModal, {
      props: {
        show: true, form, error: '堂次已變更',
        impactPreview: { title: '請假送出前影響預覽', summary: '小明｜數學', items: ['未來日期不變'] },
      },
    });
    expect(w.get('[aria-label="請假影響預覽"]').text()).toContain('未來日期不變');
    expect(w.get('[role="alert"]').text()).toContain('堂次已變更');
  });
});
