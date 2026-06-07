import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarSubstituteReschedule } from '../useCalendarSubstituteReschedule.js';

function makeDeps(overrides = {}) {
  return {
    supabase: { from: vi.fn(() => ({ insert: vi.fn() })) },
    branchId: computed(() => 2),
    showModal: ref(true),
    modalForm: ref({
      student_id: 10,
      subject: 'Math',
      teacher_id: 5,
      day_of_week: 3,
      start_time: '16:00',
      end_time: '18:00',
      duration_hours: 2,
      class_type: 'one_on_one',
      action_date: '2026-06-11',
    }),
    editingCourseId: ref(99),
    loadCourses: vi.fn(async () => {}),
    getToken: vi.fn(async () => 'tok'),
    teachers: ref([{ id: 5, name: '王老師' }]),
    sessionDatesByCourseId: ref({}),
    allStudents: ref([{ id: 10, name: '小明' }]),
    courses: ref([]),
    exceptions: ref([]),
    getSubjectLabel: (v) => (v === 'Math' ? '數學' : v),
    ...overrides,
  };
}

describe('useCalendarSubstituteReschedule', () => {
  it('substituteDisplay reflects student and session slot', () => {
    const { substituteForm, substituteDisplay } = useCalendarSubstituteReschedule(makeDeps());
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
    const { openSubstituteModal, substituteForm, showSubstituteModal } = useCalendarSubstituteReschedule(
      makeDeps({ showModal }),
    );
    openSubstituteModal();
    expect(showModal.value).toBe(false);
    expect(showSubstituteModal.value).toBe(true);
    expect(substituteForm.value.student_id).toBe(10);
    expect(substituteForm.value.session_date).toBe('2026-06-11');
  });

  it('openRescheduleModal initializes rescheduleForm from modalForm', () => {
    const showModal = ref(true);
    const { openRescheduleModal, rescheduleForm, showRescheduleModal } = useCalendarSubstituteReschedule(
      makeDeps({ showModal }),
    );
    openRescheduleModal();
    expect(showModal.value).toBe(false);
    expect(showRescheduleModal.value).toBe(true);
    expect(rescheduleForm.value.student_id).toBe(10);
    expect(rescheduleForm.value.course_id).toBe(99);
    expect(rescheduleForm.value.new_date).toBe('2026-06-11');
  });
});
