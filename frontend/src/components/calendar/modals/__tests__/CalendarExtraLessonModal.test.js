import { afterEach, describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarExtraLessonModal from '../CalendarExtraLessonModal.vue';

const form = { student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one', schedule_date: '', start_time: '16:00', duration_hours: 2 };

describe('CalendarExtraLessonModal', () => {
  afterEach(() => vi.restoreAllMocks());
  it('shows monthly hint when isMonthly', () => {
    const w = mount(CalendarExtraLessonModal, { props: { show: true, form, isMonthly: true } });
    expect(w.text()).toContain('月結制加課');
  });

  it('shows session hint when not monthly', () => {
    const w = mount(CalendarExtraLessonModal, { props: { show: true, form, isMonthly: false } });
    expect(w.text()).toContain('堂數制加課');
  });

  it('emits duration-change and submit', async () => {
    const w = mount(CalendarExtraLessonModal, {
      props: { show: true, form, newEndTime: '18:00', check: { can_add: true, is_ended: false } },
    });
    const selects = w.findAll('select');
    await selects[3].trigger('change'); // 時長 select（subject/teacher/class_type 之後）
    expect(w.emitted('duration-change')).toHaveLength(1);
    await w.find('.primary').trigger('click');
    expect(w.emitted('submit')).toHaveLength(1);
  });

  it('blocks writing until the authoritative check succeeds and exposes retry on failure', async () => {
    const w = mount(CalendarExtraLessonModal, {
      props: { show: true, form, checkError: '無法檢查加課時段' },
    });
    expect(w.find('.primary').attributes('disabled')).toBeDefined();
    await w.find('.check-retry').trigger('click');
    expect(w.emitted('check')).toHaveLength(1);
  });

  it('requires an explicit second confirmation before auto-approving a past session', async () => {
    const localForm = { ...form, schedule_date: '2026-06-07', auto_approve: true };
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const w = mount(CalendarExtraLessonModal, {
      props: { show: true, form: localForm, check: { can_add: true, is_ended: true } },
    });
    await w.find('.primary').trigger('click');
    expect(confirm).toHaveBeenCalled();
    expect(w.emitted('submit')).toBeUndefined();
    confirm.mockReturnValue(true);
    await w.find('.primary').trigger('click');
    expect(w.emitted('submit')).toHaveLength(1);
  });
});
