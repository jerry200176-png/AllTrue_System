import { describe, it, expect, vi } from 'vitest';
import { ref, nextTick } from 'vue';
import { useCalendarLeaveExtra } from '../useCalendarLeaveExtra.js';

describe('useCalendarLeaveExtra', () => {
  const baseDeps = () => ({
    supabase: { from: vi.fn(() => ({ insert: vi.fn().mockResolvedValue({}) })) },
    branchId: ref(1),
    showModal: ref(true),
    modalForm: ref({
      student_id: 10, subject: 'Math', day_of_week: 3,
      start_time: '16:00', end_time: '18:00', teacher_id: 5,
      duration_hours: 2, class_type: 'one_on_one', action_date: '2026-06-10',
    }),
    editingCourseId: ref(99),
    contextMenu: ref({ show: false, x: 0, y: 0, course: null, date: null }),
    loadCourses: vi.fn().mockResolvedValue(undefined),
    getToken: vi.fn().mockResolvedValue('tok'),
    allStudents: ref([{ id: 10, name: '小明' }]),
    getSubjectLabel: (s) => s,
  });

  it('leaveDisplay reflects student name', async () => {
    const { leaveForm, leaveDisplay, openLeaveModal } = useCalendarLeaveExtra(baseDeps());
    openLeaveModal();
    leaveForm.value.student_id = 10;
    await nextTick();
    expect(leaveDisplay.value.studentName).toBe('小明');
  });

  it('openLeaveModal closes session edit modal', () => {
    const deps = baseDeps();
    const { openLeaveModal, showLeaveModal } = useCalendarLeaveExtra(deps);
    openLeaveModal();
    expect(deps.showModal.value).toBe(false);
    expect(showLeaveModal.value).toBe(true);
  });

  it('onContextLeave fills form from context menu course', () => {
    const deps = baseDeps();
    deps.contextMenu.value = {
      show: true, x: 1, y: 2,
      course: {
        id: 1, student_course_id: 2, student_id: 10, subject: 'Eng',
        teacher_id: 5, day_of_week: 4, start_time: '09:00', end_time: '11:00',
        duration_hours: 2, class_type: 'one_on_one', is_exception: false,
      },
      date: '2026-06-12',
    };
    const { onContextLeave, leaveForm, showLeaveModal } = useCalendarLeaveExtra(deps);
    onContextLeave();
    expect(leaveForm.value.schedule_date).toBe('2026-06-12');
    expect(leaveForm.value.course_id).toBe(1);
    expect(showLeaveModal.value).toBe(true);
  });
});
