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
  reassignTargets: [], reassignState: { show: false, loading: false, error: '' },
  editingException: false, editingExceptionIsExtra: false,
  evalRecords: [], evalLoading: false,
};

const reassignTargets = [
  { id: 11, subject_id: 1, subject_name: '數學', teacher_id: 2, teacher_name: '王老師', remaining_sessions: 7 },
  { id: 12, subject_id: 1, subject_name: '數學', teacher_id: 3, teacher_name: '李老師', remaining_sessions: 3 },
];

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

  it('hides 改派合約 button when no reassign targets available', () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, session, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });
    expect(w.find('.action-btn.reassign').exists()).toBe(false);
  });

  it('shows 改派合約 button and opens the inline form when targets exist', async () => {
    const w = mount(CalendarSessionEditModal, {
      props: { show: true, form, session: { ...session, reassignTargets }, options: {} },
      global: { stubs: { SearchableSelect: true } },
    });
    const btn = w.find('.action-btn.reassign');
    expect(btn.exists()).toBe(true);
    await btn.trigger('click');
    expect(w.emitted('show-reassign-confirm')).toHaveLength(1);
  });

  it('renders target options and emits confirm-reassign with selected id + reason', async () => {
    const w = mount(CalendarSessionEditModal, {
      props: {
        show: true,
        form,
        session: { ...session, reassignTargets, reassignState: { show: true, loading: false, error: '' } },
        options: {},
      },
      global: { stubs: { SearchableSelect: true } },
    });
    const options = w.findAll('.reassign-confirm select option');
    // "請選擇" placeholder + 2 targets
    expect(options).toHaveLength(3);
    expect(w.text()).toContain('數學');
    expect(w.text()).toContain('王老師');
    expect(w.text()).toContain('剩 7 堂');

    await w.find('.reassign-confirm select').setValue('11');
    await w.find('.reassign-confirm input').setValue('合約續約，堂次改派');
    await w.find('.reassign-confirm-btns .action-btn.reassign').trigger('click');

    const emitted = w.emitted('confirm-reassign');
    expect(emitted).toHaveLength(1);
    expect(emitted[0][0]).toEqual({ newStudentClassId: 11, reason: '合約續約，堂次改派' });
  });

  it('surfaces backend error message inline', () => {
    const w = mount(CalendarSessionEditModal, {
      props: {
        show: true,
        form,
        session: {
          ...session,
          reassignTargets,
          reassignState: { show: true, loading: false, error: '新舊課程合約的學生或科目不一致，拒絕改派。' },
        },
        options: {},
      },
      global: { stubs: { SearchableSelect: true } },
    });
    expect(w.find('.reassign-error').text()).toBe('新舊課程合約的學生或科目不一致，拒絕改派。');
  });
});
