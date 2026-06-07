import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarRescheduleModal from '../CalendarRescheduleModal.vue';

const form = { new_date: '2026-06-10', new_start: '17:00', original_day: 1, original_start: '16:00', original_end: '18:00' };

describe('CalendarRescheduleModal', () => {
  it('renders labels and new end time', () => {
    const w = mount(CalendarRescheduleModal, {
      props: { show: true, form, studentName: '小華', subjectLabel: '英文', originalSlotLabel: '週一 16:00~18:00', newEndTime: '19:00', timeOptions: ['16:00', '17:00'] },
    });
    expect(w.text()).toContain('小華');
    expect(w.text()).toContain('19:00');
  });

  it('hides when show=false', () => {
    expect(mount(CalendarRescheduleModal, { props: { show: false, form } }).find('.modal-overlay').exists()).toBe(false);
  });

  it('emits new-start-change on select change', async () => {
    const w = mount(CalendarRescheduleModal, { props: { show: true, form, timeOptions: ['16:00', '17:00'] } });
    await w.find('select').trigger('change');
    expect(w.emitted('new-start-change')).toHaveLength(1);
  });
});
