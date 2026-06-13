import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarReschedule } from '../useCalendarReschedule.js';

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
    allStudents: ref([{ id: 10, name: '小明' }]),
    courses: ref([]),
    exceptions: ref([]),
    getSubjectLabel: (v) => (v === 'Math' ? '數學' : v),
    ...overrides,
  };
}

describe('useCalendarReschedule', () => {
  it('openRescheduleModal initializes form and opens modal', () => {
    const showModal = ref(true);
    const { openRescheduleModal, rescheduleForm, showRescheduleModal } = useCalendarReschedule(
      makeDeps({ showModal }),
    );
    openRescheduleModal();
    expect(showModal.value).toBe(false);
    expect(showRescheduleModal.value).toBe(true);
    expect(rescheduleForm.value.student_id).toBe(10);
    expect(rescheduleForm.value.course_id).toBe(99);
    expect(rescheduleForm.value.new_date).toBe('2026-06-11');
  });

  it('rescheduleDisplay shows student and original slot', () => {
    const { rescheduleForm, rescheduleDisplay } = useCalendarReschedule(makeDeps());
    rescheduleForm.value = {
      student_id: 10,
      subject: 'Math',
      original_day: 3,
      original_start: '16:00',
      original_end: '18:00',
    };
    expect(rescheduleDisplay.value.studentName).toBe('小明');
    expect(rescheduleDisplay.value.subjectLabel).toBe('數學');
    expect(rescheduleDisplay.value.originalSlot).toBe('週三 16:00~18:00');
  });
});
