import { ref, computed } from 'vue';
import {
  dayLabel,
  normalizeTimeTo30,
  computeEndTime,
} from '../../lib/calendarFormat.js';
import {
  formatRescheduleSuccessMessage,
  formatRescheduleConflictStudents,
  humanizeRescheduleFailure,
} from '../../lib/scheduleDisplay.js';
import { commitReschedule } from '../../lib/rescheduleApi.js';
import { buildReschedulePreview } from '../../lib/reschedulePreview.js';

export function findExactRescheduleAnchor(exceptions, courseId, date, startTime) {
  const startHm = String(startTime || '').slice(0, 5);
  return (exceptions || []).find((ex) =>
    ex.status === 'rescheduled'
    && String(ex.student_course_id) === String(courseId)
    && ex.schedule_date === date
    && String(ex.start_time || '').slice(0, 5) === startHm,
  ) || null;
}

/** #740 Step 7b2b：調課 modal + submit（拖曳 handler 留 SmartCalendar） */
export function useCalendarReschedule({
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  getToken,
  allStudents,
  getSubjectLabel,
  courses,
}) {
  const getStudentName = (sid) => {
    const s = allStudents.value.find((x) => x.id === sid);
    return s ? s.name : '—';
  };

  const showRescheduleModal = ref(false);
  const rescheduleSubmitting = ref(false);
  const rescheduleError = ref('');
  const rescheduleForm = ref({
    student_id: '', subject: '', course_id: '',
    original_day: 1, original_start: '', original_end: '',
    new_date: '', new_day_of_week: 1, new_start: '', new_end: '',
    teacher_id: '', class_type: '', duration_hours: 2,
  });

  const onRescheduleNewStartChange = () => {
    rescheduleError.value = '';
    rescheduleForm.value.new_start = normalizeTimeTo30(rescheduleForm.value.new_start);
    rescheduleForm.value.new_end = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);
  };
  const computedRescheduleNewEnd = computed(() =>
    computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
  );
  const reschedulePreview = computed(() => buildReschedulePreview({
    courses: courses?.value || [],
    currentCourseId: rescheduleForm.value.course_id,
    studentId: rescheduleForm.value.student_id,
    teacherId: rescheduleForm.value.teacher_id,
    targetDate: rescheduleForm.value.new_date,
    startTime: rescheduleForm.value.new_start,
    endTime: computedRescheduleNewEnd.value,
    classType: rescheduleForm.value.class_type,
  }));

  const openRescheduleModal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    const newStart = normalizeTimeTo30(modalForm.value.start_time);
    const dur = modalForm.value.duration_hours || 2;
    rescheduleError.value = '';
    rescheduleForm.value = {
      student_id: modalForm.value.student_id,
      subject: modalForm.value.subject,
      course_id: editingCourseId.value,
      original_day: modalForm.value.day_of_week,
      original_start: modalForm.value.start_time,
      original_end: modalForm.value.end_time,
      original_date: exactDate,
      new_date: exactDate,
      new_day_of_week: modalForm.value.day_of_week,
      new_start: newStart,
      new_end: computeEndTime(newStart, dur),
      teacher_id: modalForm.value.teacher_id,
      class_type: modalForm.value.class_type,
      duration_hours: dur,
    };
    showModal.value = false;
    showRescheduleModal.value = true;
  };


  const submitReschedule = async () => {
    if (rescheduleSubmitting.value) return;
    if (!rescheduleForm.value.new_date) {
      rescheduleError.value = '請選擇新日期';
      return;
    }
    const bid = Number(branchId.value ?? branchId) || 0;
    if (!bid) {
      rescheduleError.value = '請先選擇分校';
      return;
    }
    if (reschedulePreview.value.blocked) {
      rescheduleError.value = reschedulePreview.value.message;
      return;
    }
    const newEnd = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);
    rescheduleError.value = '';
    rescheduleSubmitting.value = true;

    try {
      const token = await getToken();
      await commitReschedule({
        token,
        payload: {
          student_class_id: rescheduleForm.value.course_id,
          old_date: rescheduleForm.value.original_date,
          old_start_time: rescheduleForm.value.original_start,
          new_date: rescheduleForm.value.new_date,
          start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
          end_time: newEnd,
          subject: rescheduleForm.value.subject || null,
        },
      });

      showRescheduleModal.value = false;
      await loadCourses();
      alert(formatRescheduleSuccessMessage({
        studentName: getStudentName(rescheduleForm.value.student_id),
        subject: getSubjectLabel(rescheduleForm.value.subject),
        originalDate: rescheduleForm.value.original_date,
        originalStart: rescheduleForm.value.original_start,
        originalEnd: rescheduleForm.value.original_end,
        newDate: rescheduleForm.value.new_date,
        newStart: normalizeTimeTo30(rescheduleForm.value.new_start),
        newEnd,
      }));
    } catch (error) {
      let message = humanizeRescheduleFailure(error?.message || '調課未完成，資料沒有變更');
      const conflictLine = formatRescheduleConflictStudents(error?.conflicts?.[0]?.overlap_details);
      if (conflictLine) message = `${message} ${conflictLine}`;
      rescheduleError.value = message;
    } finally {
      rescheduleSubmitting.value = false;
    }
  };

  const rescheduleDisplay = computed(() => ({
    studentName: getStudentName(rescheduleForm.value.student_id),
    subjectLabel: getSubjectLabel(rescheduleForm.value.subject),
    originalSlot: `${dayLabel(rescheduleForm.value.original_day)} ${rescheduleForm.value.original_start}~${rescheduleForm.value.original_end}`,
  }));

  return {
    showRescheduleModal,
    rescheduleForm,
    rescheduleDisplay,
    computedRescheduleNewEnd,
    reschedulePreview,
    rescheduleSubmitting,
    rescheduleError,
    onRescheduleNewStartChange,
    openRescheduleModal,
    submitReschedule,
  };
}
