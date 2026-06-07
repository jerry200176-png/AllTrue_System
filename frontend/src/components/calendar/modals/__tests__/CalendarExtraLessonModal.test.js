import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarExtraLessonModal from '../CalendarExtraLessonModal.vue';

const form = { student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one', schedule_date: '', start_time: '16:00', duration_hours: 2 };

describe('CalendarExtraLessonModal', () => {
  it('shows monthly hint when isMonthly', () => {
    const w = mount(CalendarExtraLessonModal, { props: { show: true, form, isMonthly: true } });
    expect(w.text()).toContain('月結制加課');
  });

  it('shows session hint when not monthly', () => {
    const w = mount(CalendarExtraLessonModal, { props: { show: true, form, isMonthly: false } });
    expect(w.text()).toContain('堂數制加課');
  });

  it('emits duration-change and submit', async () => {
    const w = mount(CalendarExtraLessonModal, { props: { show: true, form, newEndTime: '18:00' } });
    const selects = w.findAll('select');
    await selects[3].trigger('change'); // 時長 select（subject/teacher/class_type 之後）
    expect(w.emitted('duration-change')).toHaveLength(1);
    await w.find('.primary').trigger('click');
    expect(w.emitted('submit')).toHaveLength(1);
  });
});
