// In-app #409 / GitHub #4151: 課程設定 and 合約／堂次調整 route forward-only subject／teacher／slot
// changes on a 堂數制 course to the existing 轉課 flow, prefilled. No backend behaviour changes.
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { FORWARD_CHANGE_TRANSFER_HINT, detectForwardChange, isForwardTransferCandidate } from '../../../lib/forwardChangeTransfer.js';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import CourseTransferModal from '../CourseTransferModal.vue';

vi.mock('../../../supabase', () => ({ supabase: { auth: { getSession: async () => ({ data: { session: null } }) } } }));

const countCourse = { id: 3904, payment_type: 'session', sessions_purchased: 8, remaining_sessions: 5 };
const form = (over = {}) => ({
  subject: 'english', teacher_id: 7, days_of_week: [3], start_time: '16:00', duration_hours: 2,
  day_time_slots: [{ day: 3, start_time: '16:00', duration_hours: 2 }], ...over,
});

describe('forwardChangeTransfer helper', () => {
  it('uses the exact Founder-facing explanation', () => {
    expect(FORWARD_CHANGE_TRANSFER_HINT).toBe('已上過的課不會變動；要從某日起改科目／老師／時段，請用轉課');
  });

  it('only targets 堂數制, non-package courses with remaining lessons', () => {
    expect(isForwardTransferCandidate(countCourse)).toBe(true);
    expect(isForwardTransferCandidate({ ...countCourse, remaining_sessions: 0 })).toBe(false);
    expect(isForwardTransferCandidate({ ...countCourse, payment_type: 'monthly' })).toBe(false);
    expect(isForwardTransferCandidate({ ...countCourse, package_id: 9 })).toBe(false);
    expect(isForwardTransferCandidate(null)).toBe(false);
  });

  it('returns null when nothing forward-only changed', () => {
    expect(detectForwardChange(countCourse, form(), form({ memo: 'x' }))).toBeNull();
  });

  it('detects subject, teacher and slot changes and builds a 轉課 prefill', () => {
    const result = detectForwardChange(countCourse, form(), form({
      subject: 'math', teacher_id: 12, day_time_slots: [{ day: 5, start_time: '18:30', duration_hours: 1.5 }], days_of_week: [5],
    }));
    expect(result.changed).toEqual(['科目', '老師', '時段']);
    expect(result.prefill).toEqual({ subject: 'math', teacher_id: 12, weekday: 5, time: '18:30', duration_minutes: 90 });
  });

  it('stays silent for courses that are not candidates even when fields change', () => {
    expect(detectForwardChange({ ...countCourse, remaining_sessions: 0 }, form(), form({ subject: 'math' }))).toBeNull();
    expect(detectForwardChange({ ...countCourse, payment_type: 'monthly' }, form(), form({ teacher_id: 12 }))).toBeNull();
  });
});

describe('合約／堂次調整 → 轉課', () => {
  it('offers the forward change as its own choice and emits forward-transfer', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, { props: { show: true, paymentStatus: 'paid' } });
    const hint = wrapper.find('[data-testid="contract-adjust-forward-transfer-hint"]');
    expect(hint.text()).toContain('從某日起改科目／老師／時段');
    expect(hint.text()).toContain('已上過的課不會變動');
    await wrapper.find('[data-testid="contract-adjust-forward-transfer"]').trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['forward-transfer']]);
  });
});

describe('CourseTransferModal prefill', () => {
  const props = {
    courseId: 3904,
    subjects: [{ value: 'math', label: '數學' }],
    teachers: [{ id: 12, username: '新老師' }],
  };

  it('opens with the attempted change already filled in', async () => {
    const wrapper = mount(CourseTransferModal, { props: { ...props, show: false } });
    await wrapper.setProps({ show: true, prefill: { subject: 'math', teacher_id: 12, weekday: 5, time: '18:30', duration_minutes: 90 } });
    await flushPromises();
    expect(wrapper.find('#ct-subject').element.value).toBe('math');
    expect(wrapper.find('#ct-teacher').element.value).toBe('12');
    expect(wrapper.find('#ct-weekday').element.value).toBe('5');
    expect(wrapper.find('#ct-time').element.value).toBe('18:30');
    expect(wrapper.find('#ct-start').element.value).toBe('');
  });

  it('opens blank without a prefill', async () => {
    const wrapper = mount(CourseTransferModal, { props: { ...props, show: false } });
    await wrapper.setProps({ show: true });
    await flushPromises();
    expect(wrapper.find('#ct-subject').element.value).toBe('');
    expect(wrapper.find('#ct-teacher').element.value).toBe('');
  });
});

describe('CourseManagement wiring (source contract)', () => {
  it('shows the hint in both 課程設定 surfaces and routes every entry to the prefilled 轉課 modal', async () => {
    const { readFileSync } = await import('node:fs');
    const { resolve, dirname } = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../pages/CourseManagement.vue'), 'utf8');
    expect(source.match(/data-testid="forward-change-transfer-hint"/g)).toHaveLength(2);
    expect(source.match(/openForwardTransfer\(forwardChange\?\.prefill\)/g)).toHaveLength(2);
    expect(source).toContain(':prefill="courseTransferPrefill"');
    expect(source).toContain("if (action === 'forward-transfer') { editCourse(course, { openModal: false }); openForwardTransfer({}); }");
    expect(source).toContain('detectForwardChange(editingCourseRaw.value, JSON.parse(editFormSnapshot.value), editForm.value)');
  });
});
