import { ref, computed } from 'vue';
import { dayLabel, normalizeTimeTo30, computeEndTime } from '../../lib/calendarFormat.js';

/** #740 Step 7b1：請假 + 加課 modal 流程 */
export function useCalendarLeaveExtra({
  supabase,
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  contextMenu,
  loadCourses,
  getToken,
  allStudents,
  getSubjectLabel,
}) {
  const getStudentName = (sid) => {
    const s = allStudents.value.find((x) => x.id === sid);
    return s ? s.name : '—';
  };

  const showLeaveModal = ref(false);
  const leaveForm = ref({
    student_id: '', subject: '', day_of_week: 1,
    start_time: '', end_time: '', schedule_date: '', course_id: '',
    teacher_id: '', duration_hours: 2, class_type: 'one_on_one',
  });

  const openLeaveModal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    leaveForm.value = {
      student_id: modalForm.value.student_id,
      subject: modalForm.value.subject,
      day_of_week: modalForm.value.day_of_week,
      start_time: modalForm.value.start_time,
      end_time: modalForm.value.end_time,
      schedule_date: exactDate,
      course_id: editingCourseId.value,
      teacher_id: modalForm.value.teacher_id || '',
      duration_hours: modalForm.value.duration_hours || 2,
      class_type: modalForm.value.class_type || 'one_on_one',
    };
    showModal.value = false;
    showLeaveModal.value = true;
  };

  const submitLeave = async () => {
    if (!leaveForm.value.schedule_date) { alert('請選擇日期'); return; }
    const studentId = Number(leaveForm.value.student_id) || 0;
    const courseId = Number(leaveForm.value.course_id) || null;
    const teacherId = Number(leaveForm.value.teacher_id) || null;
    const bid = Number(branchId.value ?? branchId) || 0;
    if (!studentId || !bid) { alert('請假登記失敗：缺少學生或分校資訊'); return; }
    const payload = {
      student_id: studentId,
      teacher_id: teacherId,
      subject: leaveForm.value.subject,
      day_of_week: Number(leaveForm.value.day_of_week) || 1,
      start_time: leaveForm.value.start_time,
      end_time: leaveForm.value.end_time,
      duration_hours: leaveForm.value.duration_hours || 2,
      class_type: leaveForm.value.class_type || 'one_on_one',
      status: 'leave',
      type: 'normal',
      deduction: 0,
      branch_id: bid,
      schedule_date: leaveForm.value.schedule_date,
      student_course_id: courseId,
    };
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    if (!token) {
      alert('請假登記失敗：請重新登入後再試');
      return;
    }
    try {
      const res = await fetch(`${baseUrl}/v1/schedules`, {
        method: 'POST',
        credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert('請假登記失敗：' + (body.message || res.statusText || '請稍後再試'));
        return;
      }
    } catch (error) {
      alert('請假登記失敗：' + (error?.message || '請稍後再試'));
      return;
    }
    showLeaveModal.value = false;
    contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
    await loadCourses();
    alert('請假登記完成');
  };

  const onContextLeave = () => {
    const { course, date } = contextMenu.value;
    const baseId = course.is_exception ? course.student_course_id : course.id;
    leaveForm.value = {
      student_id: course.student_id,
      subject: course.subject,
      teacher_id: course.teacher_id || '',
      day_of_week: course.day_of_week,
      start_time: course.start_time,
      end_time: course.end_time,
      duration_hours: course.duration_hours || 2,
      class_type: course.class_type || 'one_on_one',
      schedule_date: date,
      course_id: baseId,
    };
    contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
    showLeaveModal.value = true;
  };

  const leaveDisplay = computed(() => ({
    studentName: getStudentName(leaveForm.value.student_id),
    subjectLabel: getSubjectLabel(leaveForm.value.subject),
    originalSlot: `${dayLabel(leaveForm.value.day_of_week)} ${leaveForm.value.start_time}~${leaveForm.value.end_time}`,
  }));

  const showExtraModal = ref(false);
  const extraForm = ref({
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    schedule_date: '', start_time: '16:00', end_time: '18:00', duration_hours: 2,
  });

  const onExtraFormStartTimeChange = () => {
    extraForm.value.start_time = normalizeTimeTo30(extraForm.value.start_time);
    extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
  };
  const onExtraFormTimeChange = () => {
    extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
  };
  const computedExtraEndTime = computed(() =>
    computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours),
  );
  const extraParentPaymentType = computed(() => modalForm.value?.payment_type || 'session');

  const openExtraLesson = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    const start = normalizeTimeTo30(modalForm.value.start_time || '16:00');
    const dur = modalForm.value.duration_hours || 2;
    extraForm.value = {
      student_id: modalForm.value.student_id,
      subject: modalForm.value.subject,
      teacher_id: modalForm.value.teacher_id || '',
      class_type: modalForm.value.class_type || 'one_on_one',
      schedule_date: exactDate,
      start_time: start,
      end_time: computeEndTime(start, dur),
      duration_hours: dur,
    };
    showModal.value = false;
    showExtraModal.value = true;
  };

  const submitExtraLesson = async () => {
    if (!extraForm.value.student_id) { alert('請選擇學生'); return; }
    if (!extraForm.value.schedule_date) { alert('請選擇日期'); return; }
    const endTime = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
    const date = new Date(extraForm.value.schedule_date);
    let dow = date.getDay();
    if (dow === 0) dow = 7;

    await supabase.from('schedules').insert([{
      student_id: extraForm.value.student_id,
      teacher_id: extraForm.value.teacher_id || null,
      subject: extraForm.value.subject,
      day_of_week: dow,
      start_time: normalizeTimeTo30(extraForm.value.start_time),
      end_time: endTime,
      duration_hours: extraForm.value.duration_hours,
      class_type: extraForm.value.class_type,
      status: 'scheduled',
      type: 'extra',
      deduction: 1,
      branch_id: branchId.value ?? branchId,
      schedule_date: extraForm.value.schedule_date,
      student_course_id: editingCourseId.value || null,
    }]);

    if (editingCourseId.value) {
      try {
        const token = await getToken();
        await fetch('/api/v1/learning-records/reschedule-session', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            student_class_id: editingCourseId.value,
            new_date: extraForm.value.schedule_date,
            start_time: normalizeTimeTo30(extraForm.value.start_time),
            end_time: endTime,
          }),
        });
      } catch (_) { /* non-critical */ }
    }

    showExtraModal.value = false;
    alert('加課建立完成，老師上課後需填寫評量表');
    await loadCourses();
  };

  return {
    showLeaveModal, leaveForm, leaveDisplay, openLeaveModal, submitLeave, onContextLeave,
    showExtraModal, extraForm, computedExtraEndTime, extraParentPaymentType,
    onExtraFormStartTimeChange, onExtraFormTimeChange, openExtraLesson, submitExtraLesson,
  };
}
