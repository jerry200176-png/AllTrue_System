import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CourseEditForm from '../CourseEditForm.vue';
vi.mock('../TeacherAvailabilityPlanner.vue', () => ({ default: { template: '<div />' } }));
const model = () => ({ subject: 'Math', teacher_id: 1, original_teacher_id: 1, class_type: 'one_on_one',
  rate_per_30min: 1600, rate_unit: 'session', sessions_purchased: 8, remaining_sessions: 5,
  duration_hours: 2, days_of_week: [1], day_time_slots: [{ day: 1, start_time: '20:00', duration_hours: 2 }],
  start_time: '20:00', payment_type: 'session', first_class_date: '2030-01-07', paid_at: '2026-01-01' });
const props = () => ({ modelValue: model(), branchId: 1, teachers: [{ id: 1, username: '合成老師' }],
  dayOptions: [{ value: 1, label: '一' }, { value: 3, label: '三' }], timeOptions: ['18:00', '20:00'],
  subjects: [{ value: 'Math', label: '數學' }] });
describe('paid schedule-only editor', () => {
  it('shows scheduling controls while removing identity, money, quantity and payment editors', () => {
    const wrapper = mount(CourseEditForm, { props: { ...props(), scheduleOnly: true } });
    expect(wrapper.findAll('.form-section-title').map(n => n.text())).toEqual(['上課時段']);
    expect(wrapper.find('select[aria-label="科目"]').exists()).toBe(false);
    expect(wrapper.find('textarea').exists()).toBe(false);
    expect(wrapper.find('input[aria-label="第1天時數"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('select[aria-label="第1天開始時間"]').exists()).toBe(true);
  });
  it('preserves the existing rate unit when changing time on a mixed-duration paid contract', async () => {
    const input = props(); input.modelValue.days_of_week = [1, 3];
    input.modelValue.day_time_slots.push({ day: 3, start_time: '20:00', duration_hours: 1 });
    const wrapper = mount(CourseEditForm, { props: { ...input, scheduleOnly: true } });
    await flushPromises(); // Let the existing parent-model initialization finish before a user edit.
    await wrapper.find('select[aria-label="第1天開始時間"]').setValue('18:00'); await flushPromises();
    const update = wrapper.emitted('update:modelValue').at(-1)[0];
    expect(update.start_time).toBe('18:00');
    expect(update.rate_unit).toBe('session'); expect(update.sessions_purchased).toBe(8);
    expect(update.rate_per_30min).toBe(1600); expect(update.paid_at).toBe('2026-01-01');
  });
  it('retains the ordinary editor when schedule-only mode is absent', () => {
    const wrapper = mount(CourseEditForm, { props: props() });
    expect(wrapper.findAll('.form-section-title').map(n => n.text())).toEqual(['基本資料', '費用與繳費', '上課時段']);
    expect(wrapper.find('select[aria-label="科目"]').exists()).toBe(true);
  });
  it('in-app #613: the rows are the days (no day chips) and adding a row adds its day', async () => {
    const wrapper = mount(CourseEditForm, { props: props() });
    await flushPromises();
    expect(wrapper.find('.day-chip').exists()).toBe(false);
    expect(wrapper.get('[data-testid="course-slot-summary"]').text()).toBe('週一 20:00–22:00（2 小時）');
    await wrapper.find('select[aria-label="第1天星期"]').setValue('3'); await flushPromises();
    expect(wrapper.emitted('update:modelValue').at(-1)[0].days_of_week).toEqual([3]);
    await wrapper.get('[data-testid="course-slot-add"]').trigger('click'); await flushPromises();
    const update = wrapper.emitted('update:modelValue').at(-1)[0];
    expect(update.days_of_week).toEqual([1, 3]);
    expect(update.day_time_slots.map((s) => s.day)).toEqual([3, 1]);
  });
  it('in-app #613: a save clash shows under its row and 換時間 focuses that row', async () => {
    const message = '1/7 週一 20:00–22:00 老師此時段本分校已有 1 位學生，上限為 1 位學生。';
    const wrapper = mount(CourseEditForm, { attachTo: document.body, props: { ...props(), slotConflicts: [
      { type: 'teacher_capacity', day_of_week: 1, start_time: '20:00', message },
      { type: 'teacher_capacity', day_of_week: 3, start_time: '20:00', message: '別的時段' },
    ] } });
    await flushPromises();
    const alerts = wrapper.findAll('[data-testid="course-slot-conflict"]');
    expect(alerts).toHaveLength(1);
    expect(alerts[0].attributes('role')).toBe('alert');
    expect(alerts[0].text()).toContain(message);
    await alerts[0].get('[data-testid="course-slot-change-time"]').trigger('click');
    expect(document.activeElement).toBe(wrapper.find('select[aria-label="第1天開始時間"]').element);
    await wrapper.find('select[aria-label="第1天開始時間"]').setValue('18:00'); await flushPromises();
    expect(wrapper.find('[data-testid="course-slot-conflict"]').exists()).toBe(false);
    wrapper.unmount();
  });
});
