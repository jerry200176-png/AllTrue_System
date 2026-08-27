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

  it('shows in-dialog error and disables submit while submitting', () => {
    const w = mount(CalendarRescheduleModal, {
      props: {
        show: true,
        form,
        timeOptions: ['16:00', '17:00'],
        error: '此時段已有：小華',
        submitting: true,
      },
    });
    expect(w.find('[role="alert"]').text()).toContain('此時段已有：小華');
    expect(w.find('button.primary').attributes('disabled')).toBeDefined();
    expect(w.find('button.primary').text()).toContain('調課中');
  });

  it('shows the preflight result and disables a known conflicting target', () => {
    const w = mount(CalendarRescheduleModal, {
      props: {
        show: true,
        form,
        timeOptions: ['16:00', '17:00'],
        preview: { status: 'ready', blocked: true, message: '已有一對一課程，請改選日期或時間。', conflicts: ['英文（16:00～18:00）'] },
      },
    });
    expect(w.text()).toContain('送出前檢查');
    expect(w.text()).toContain('英文（16:00～18:00）');
    expect(w.find('button.primary').attributes('disabled')).toBeDefined();
  });
});
