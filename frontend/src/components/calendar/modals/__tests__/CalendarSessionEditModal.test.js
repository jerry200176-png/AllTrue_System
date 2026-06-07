import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarSessionEditModal from '../CalendarSessionEditModal.vue';

const form = {
  student_id: 1, subject: 'Math', teacher_id: 2, class_type: 'one_on_one',
  start_time: '16:00', duration_hours: 2, payment_type: 'session', remaining_sessions: 5,
  day_of_week: 1,
};
const session = {
  actionDate: '2026-06-07', dayName: '週一', endTime: '18:00', chargeDisplay: { value: 2000, isAdjusted: false },
  conflictWarning: null, isTeacher: false, featureSubstituteV2: true, canCancelSession: true,
  cancelState: { show: false, loading: false }, editingException: false, editingExceptionIsExtra: false,
  evalRecords: [], evalLoading: false,
};

describe('CalendarSessionEditModal', () => {
  it('renders session info card when actionDate present', () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, ratePer2h: 2000, session, options: { studentSelectOptions: [], subjectOptions: [], teachers: [], settlementDayOptions: [] } },
      global: { stubs: { SearchableSelect: true } },
    });
    expect(w.text()).toContain('2026-06-07');
    expect(w.text()).toContain('NT$ 2,000');
  });

  it('shows conflict warning when provided', () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, session: { ...session, conflictWarning: '教室已滿' }, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });
    expect(w.text()).toContain('衝堂警告');
    expect(w.text()).toContain('教室已滿');
  });

  it('emits leave and reschedule from action buttons', async () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, session, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });
    const btns = w.findAll('.action-btn');
    await btns[0].trigger('click');
    expect(w.emitted('leave')).toHaveLength(1);
    await btns[1].trigger('click');
    expect(w.emitted('reschedule')).toHaveLength(1);
  });

  it('emits substitute-v2 when feature flag on', async () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, session: { ...session, featureSubstituteV2: true }, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });
    await w.find('.action-btn.substitute').trigger('click');
    expect(w.emitted('substitute-v2')).toHaveLength(1);
  });
});
