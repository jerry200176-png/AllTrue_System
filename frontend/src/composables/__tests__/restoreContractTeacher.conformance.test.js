/**
 * Cross-surface conformance for RestoreContractTeacher (ADR-005).
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { ref, computed } from 'vue';

const restoreMock = vi.fn(async () => ({
  restored_teacher_id: 70,
  restored_teacher_name: 'Coco',
  code: 'restore_contract_teacher',
}));

vi.mock('../../lib/schedulingCommands.js', () => ({
  restoreContractTeacher: (...args) => restoreMock(...args),
  schedulingCommands: { restoreContractTeacher: (...args) => restoreMock(...args) },
  default: { restoreContractTeacher: (...args) => restoreMock(...args) },
}));

import { useCalendarSubstitute } from '../calendar/useCalendarSubstitute.js';

describe('RestoreContractTeacher cross-surface conformance', () => {
  beforeEach(() => {
    restoreMock.mockClear();
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'tok' }));
  });
  afterEach(() => localStorage.removeItem('alltrue_session'));

  it('Calendar restore uses named client without teacher identity', async () => {
    const loadCourses = vi.fn(async () => {});
    const {
      substituteV2SessionId, onRestoreContractTeacher, showSubstituteV2Modal,
    } = useCalendarSubstitute({
      branchId: computed(() => 9),
      showModal: ref(false),
      modalForm: ref({}),
      editingCourseId: ref(2366),
      loadCourses,
      teachers: ref([{ id: 70, name: 'Coco' }]),
      sessionDatesByCourseId: ref({ 2366: [{ id: 22359, session_date: '2026-07-28', start_time: '14:00' }] }),
      allStudents: ref([{ id: 41, name: '游喨鈞' }]),
      getSubjectLabel: (v) => v,
      courses: ref([{ id: 2366, teacher_id: 70, teacher_name: 'Coco' }]),
    });
    substituteV2SessionId.value = 22359;
    showSubstituteV2Modal.value = true;
    await onRestoreContractTeacher({ reason: '回復正班老師' });
    expect(restoreMock).toHaveBeenCalledWith(22359, { reason: '回復正班老師' });
    expect(restoreMock.mock.calls[0][1]).not.toHaveProperty('teacher_id');
    expect(showSubstituteV2Modal.value).toBe(false);
    expect(loadCourses).toHaveBeenCalled();
  });
});
