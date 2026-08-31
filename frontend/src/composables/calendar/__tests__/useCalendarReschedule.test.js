import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { findExactRescheduleAnchor, useCalendarReschedule } from '../useCalendarReschedule.js';
import { buildReschedulePreview } from '../../../lib/reschedulePreview.js';

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
  it('matches a reschedule anchor by exact course, date, and start time', () => {
    const rows = [
      { id: 1, status: 'leave', student_course_id: 99, schedule_date: '2026-06-11', start_time: '16:00:00' },
      { id: 2, status: 'rescheduled', student_course_id: 99, schedule_date: '2026-06-11', start_time: '14:00:00' },
      { id: 3, status: 'rescheduled', student_course_id: 99, schedule_date: '2026-06-11', start_time: '16:00:00' },
    ];

    expect(findExactRescheduleAnchor(rows, 99, '2026-06-11', '16:00')).toMatchObject({ id: 3 });
    expect(findExactRescheduleAnchor(rows, 99, '2026-06-11', '18:00')).toBeNull();
  });

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

  it('previews a known target conflict before submitting', () => {
    const { openRescheduleModal, reschedulePreview } = useCalendarReschedule(makeDeps({
      courses: ref([{ id: 1, student_id: 20, teacher_id: 5, day_of_week: 4, start_time: '16:00', end_time: '18:00', class_type: 'one_on_one' }]),
    }));
    openRescheduleModal();
    expect(reschedulePreview.value.blocked).toBe(true);
    expect(reschedulePreview.value.message).toContain('已有一對一');
  });

  it('keeps the preview aligned with capacity and recurring-day rules', () => {
    const full = buildReschedulePreview({
      currentCourseId: 99,
      studentId: 10,
      teacherId: 7,
      targetDate: '2026-08-27',
      startTime: '16:00',
      endTime: '18:00',
      classType: 'one_on_three',
      courses: [
        { id: 1, student_id: 1, teacher_id: 7, day_of_week: 1, days_of_week: [1, 4], start_time: '16:00', end_time: '18:00', class_type: 'one_on_two' },
        { id: 2, student_id: 2, teacher_id: 7, day_of_week: 4, start_time: '16:30', end_time: '17:30', class_type: 'one_on_two' },
        { id: 3, student_id: 3, teacher_id: 7, day_of_week: 4, start_time: '17:00', end_time: '19:00', class_type: 'one_on_two' },
      ],
    });
    expect(full.blocked).toBe(true);
    expect(full.message).toContain('3 位學生');
    expect(buildReschedulePreview({ targetDate: '' }).status).toBe('incomplete');
  });

  it.each(['leave', 'leave_adjusted', 'excused', 'cancelled'])
    ('does not block a target slot when the existing course is %s on that date', (status) => {
      const result = buildReschedulePreview({
        currentCourseId: 99,
        studentId: 10,
        teacherId: 5,
        targetDate: '2026-09-01',
        startTime: '18:00',
        endTime: '20:00',
        classType: 'one_on_one',
        courses: [{ id: 20, student_id: 21, teacher_id: 5, day_of_week: 2, start_time: '18:00', end_time: '20:00', class_type: 'one_on_one' }],
        sessionDatesByCourseId: {
          20: [{ session_date: '2026-09-01', start_time: '18:00', status }],
        },
      });

      expect(result.blocked).toBe(false);
      expect(result.message).toContain('沒有發現衝堂');
    });

  it('uses a schedule exception when the session projection is not loaded yet', () => {
    const result = buildReschedulePreview({
      currentCourseId: 99,
      studentId: 10,
      teacherId: 5,
      targetDate: '2026-09-01',
      startTime: '18:00',
      endTime: '20:00',
      classType: 'one_on_one',
      courses: [{ id: 20, student_id: 21, teacher_id: 5, day_of_week: 2, start_time: '18:00', end_time: '20:00', class_type: 'one_on_one' }],
      exceptions: [{ student_course_id: 20, schedule_date: '2026-09-01', status: 'leave_adjusted' }],
    });

    expect(result.blocked).toBe(false);
  });

  it('still blocks a genuinely active course on the target date', () => {
    const result = buildReschedulePreview({
      currentCourseId: 99,
      studentId: 10,
      teacherId: 5,
      targetDate: '2026-09-01',
      startTime: '18:00',
      endTime: '20:00',
      classType: 'one_on_one',
      courses: [{ id: 20, student_id: 21, teacher_id: 5, day_of_week: 2, start_time: '18:00', end_time: '20:00', class_type: 'one_on_one' }],
      sessionDatesByCourseId: {
        20: [{ session_date: '2026-09-01', start_time: '18:00', status: 'scheduled' }],
      },
    });

    expect(result.blocked).toBe(true);
    expect(result.message).toContain('已有一對一');
  });
});
