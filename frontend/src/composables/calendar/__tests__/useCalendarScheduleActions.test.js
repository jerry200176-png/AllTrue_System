import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useCalendarScheduleActions } from '../useCalendarScheduleActions.js';

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
    contextMenu: ref({ show: true, x: 0, y: 0, course: null, date: null }),
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

function setup(overrides = {}) {
  return useCalendarScheduleActions(makeDeps(overrides));
}

describe('useCalendarScheduleActions', () => {
  it('leaveDisplay reflects student, subject, and original slot', () => {
    const { leaveForm, leaveDisplay } = setup();
    leaveForm.value = {
      student_id: 10,
      subject: 'Math',
      day_of_week: 3,
      start_time: '16:00',
      end_time: '18:00',
    };
    expect(leaveDisplay.value.studentName).toBe('小明');
    expect(leaveDisplay.value.subjectLabel).toBe('數學');
    expect(leaveDisplay.value.originalSlot).toBe('週三 16:00~18:00');
  });

  it('openLeaveModal initializes leaveForm from modalForm and closes session edit modal', () => {
    const showModal = ref(true);
    const { openLeaveModal, leaveForm, showLeaveModal } = setup({ showModal });
    openLeaveModal();
    expect(showModal.value).toBe(false);
    expect(showLeaveModal.value).toBe(true);
    expect(leaveForm.value.student_id).toBe(10);
    expect(leaveForm.value.schedule_date).toBe('2026-06-11');
    expect(leaveForm.value.course_id).toBe(99);
    expect(leaveForm.value.start_time).toBe('16:00');
  });

  it('onContextLeave fills leaveForm from context menu course and opens leave modal', () => {
    const contextMenu = ref({
      show: true,
      x: 1,
      y: 2,
      course: {
        student_id: 10,
        subject: 'Math',
        teacher_id: 5,
        day_of_week: 1,
        start_time: '14:00',
        end_time: '16:00',
        duration_hours: 2,
        class_type: 'one_on_one',
        id: 42,
        is_exception: false,
      },
      date: '2026-06-09',
    });
    const { onContextLeave, leaveForm, showLeaveModal } = setup({ contextMenu });
    onContextLeave();
    expect(contextMenu.value.show).toBe(false);
    expect(showLeaveModal.value).toBe(true);
    expect(leaveForm.value.course_id).toBe(42);
    expect(leaveForm.value.schedule_date).toBe('2026-06-09');
  });

  it('openRescheduleModal seeds rescheduleForm with normalized start/end times', () => {
    const showModal = ref(true);
    const { openRescheduleModal, rescheduleForm, showRescheduleModal } = setup({ showModal });
    openRescheduleModal();
    expect(showModal.value).toBe(false);
    expect(showRescheduleModal.value).toBe(true);
    expect(rescheduleForm.value.student_id).toBe(10);
    expect(rescheduleForm.value.original_date).toBe('2026-06-11');
    expect(rescheduleForm.value.new_start).toBe('16:00');
    expect(rescheduleForm.value.new_end).toBe('18:00');
  });
});
