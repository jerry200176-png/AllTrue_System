import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarSubstitute } from '../useCalendarSubstitute.js';

function makeDeps(overrides = {}) {
  return {
    branchId: computed(() => 2),
    showModal: ref(true),
    modalForm: ref({
      student_id: 10,
      subject: 'Math',
      teacher_id: 5,
      start_time: '16:00',
      end_time: '18:00',
      action_date: '2026-06-11',
    }),
    editingCourseId: ref(99),
    loadCourses: vi.fn(async () => {}),
    teachers: ref([{ id: 5, name: '王老師' }]),
    sessionDatesByCourseId: ref({}),
    allStudents: ref([{ id: 10, name: '小明' }]),
    getSubjectLabel: (v) => (v === 'Math' ? '數學' : v),
    ...overrides,
  };
}

describe('useCalendarSubstitute', () => {
  it('substituteDisplay reflects student and session slot', () => {
    const { substituteForm, substituteDisplay } = useCalendarSubstitute(makeDeps());
    substituteForm.value = {
      student_id: 10,
      subject: 'Math',
      session_date: '2026-06-11',
      start_time: '16:00',
      end_time: '18:00',
    };
    expect(substituteDisplay.value.studentName).toBe('小明');
    expect(substituteDisplay.value.subjectLabel).toBe('數學');
    expect(substituteDisplay.value.sessionSlot).toBe('2026-06-11 16:00~18:00');
  });

  it('openSubstituteModal closes session edit modal and opens substitute modal', () => {
    const showModal = ref(true);
    const { openSubstituteModal, substituteForm, showSubstituteModal } = useCalendarSubstitute(
      makeDeps({ showModal }),
    );
    openSubstituteModal();
    expect(showModal.value).toBe(false);
    expect(showSubstituteModal.value).toBe(true);
    expect(substituteForm.value.student_id).toBe(10);
    expect(substituteForm.value.session_date).toBe('2026-06-11');
  });
});
