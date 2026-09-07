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
  cancelState: { show: false, loading: false },
  editingException: false, editingExceptionIsExtra: false,
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

  it('shows a guarded recovery action only for a server-approved candidate', async () => {
    const w = mount(CalendarSessionEditModal, {
      props: {
        show: true,
        form,
        session: {
          ...session,
          recovery: {
            loading: false,
            available: true,
            previousStatus: 'scheduled',
            previousStatusLabel: '排程中',
            reason: '主任誤取消',
            submitting: false,
          },
        },
        options: {},
      },
      global: { stubs: { SearchableSelect: true } },
    });
    expect(w.text()).toContain('這堂課可安全復原');
    await w.find('#session-recovery-reason').setValue('主任誤取消');
    await w.find('.restore-session').trigger('click');
    expect(w.emitted('restore-session')).toHaveLength(1);
  });

  it('adapts modal for teacher: title, room/branch, roll call, hides financials & delete buttons', async () => {
    const teacherSession = {
      ...session,
      isTeacher: true,
      branchName: '台北分校',
      roomName: '教室 A',
      rollCallStatus: { kind: 'done', label: '✓', text: '已點名' },
    };
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, ratePer2h: 2000, session: teacherSession, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });

    // Teacher-facing title
    expect(w.text()).toContain('單堂詳細資訊');

    // Branch / room and roll call status display
    expect(w.text()).toContain('台北分校 · 教室 A');
    expect(w.text()).toContain('點名狀態');
    expect(w.text()).toContain('已點名');

    // Sensitive financial/billing fields are hidden for teachers
    expect(w.text()).not.toContain('本堂費用');
    expect(w.text()).not.toContain('一堂課費用');
    expect(w.text()).not.toContain('時段與費用');
    expect(w.text()).not.toContain('繳費狀態（僅供參考）');

    // Forbidden director/admin actions remain strictly unavailable for teachers
    expect(w.find('.action-btn.leave').exists()).toBe(false);
    expect(w.find('.action-btn.reschedule').exists()).toBe(false);
    expect(w.find('.action-btn.substitute').exists()).toBe(false);
    expect(w.find('.action-btn.cancel-session').exists()).toBe(false);
    expect(w.find('.restore-session').exists()).toBe(false);
    expect(w.find('.actions button.danger').exists()).toBe(false);
    expect(w.text()).not.toContain('刪除整門課');
    expect(w.text()).not.toContain('刪除此調課');
    expect(w.text()).not.toContain('取消補課');

    // Only safe high-frequency navigation and close actions remain available
    const attendanceBtn = w.find('[data-testid="calendar-goto-attendance"]');
    expect(attendanceBtn.exists()).toBe(true);
    await attendanceBtn.trigger('click');
    expect(w.emitted('goto-attendance')).toHaveLength(1);

    const learningBtn = w.find('[data-testid="calendar-goto-learning"]');
    expect(learningBtn.exists()).toBe(true);
    await learningBtn.trigger('click');
    expect(w.emitted('goto-learning')).toHaveLength(1);

    expect(w.find('button.ghost').text()).toBe('關閉');
  });
});
