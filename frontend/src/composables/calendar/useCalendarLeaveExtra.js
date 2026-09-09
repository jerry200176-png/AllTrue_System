import { ref, computed, watch } from 'vue';
import { dayLabel, normalizeTimeTo30, computeEndTime } from '../../lib/calendarFormat.js';

/** #740 Step 7b1：請假 + 加課 modal 流程 */
export function useCalendarLeaveExtra({
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  contextMenu,
  loadCourses,
  getToken,
  allStudents,
  getSubjectLabel,
  toastRef,
}) {
  const getStudentName = (sid) => {
    const s = allStudents.value.find((x) => x.id === sid);
    return s ? s.name : '—';
  };

  const showLeaveModal = ref(false);
  const leaveCascadePlan = ref(null);
  const leaveCascadePlanLoading = ref(false);
  const leavePreviewRequestKey = ref('');
  const leaveSubmitError = ref('');
  const leaveSubmitting = ref(false);
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
    leaveSubmitError.value = '';
    showLeaveModal.value = true;
  };

  const formatPreviewDate = (value) => String(value || '').slice(5, 10).replace('-', '/');
  const leaveImpactPreview = computed(() => {
    if (!leaveForm.value.schedule_date) return null;
    const plan = leaveCascadePlan.value;
    const isDateMode = String(modalForm.value?.payment_type || '').toLowerCase() === 'monthly'
      || plan?.leave_mode === 'monthly_bounded';
    const items = [
      '本堂會標記為請假，不扣堂數',
      isDateMode
        ? '未來日期與合約結束日不變，不補尾'
        : '未來既有上課日不變，僅於尾端補上堂次',
      '該堂不需要填寫學習評量',
    ];
    if (plan?.next_billable_session?.date) {
      const ordinal = plan.next_billable_session.ordinal != null ? `第 ${plan.next_billable_session.ordinal} 堂` : '下一堂';
      items.push(`下一堂：${formatPreviewDate(plan.next_billable_session.date)}（${ordinal}）`);
    }
    if (plan?.append) {
      items.push(`尾堂補上：${formatPreviewDate(plan.append)}`);
    }
    if (leaveCascadePlanLoading.value) items.push('正在計算請假影響…');
    return {
      title: '請假送出前影響預覽',
      summary: `${getStudentName(leaveForm.value.student_id)}｜${getSubjectLabel(leaveForm.value.subject)}｜${leaveForm.value.schedule_date}`,
      items,
    };
  });

  const refreshLeaveCascadePreview = async () => {
    const courseId = Number(leaveForm.value.course_id || 0);
    const date = String(leaveForm.value.schedule_date || '').slice(0, 10);
    if (!showLeaveModal.value || !courseId || !date) {
      leaveCascadePlan.value = null;
      return;
    }
    const requestKey = `${courseId}:${date}`;
    if (leaveCascadePlanLoading.value && leavePreviewRequestKey.value === requestKey) return;
    leavePreviewRequestKey.value = requestKey;
    leaveCascadePlanLoading.value = true;
    try {
      const token = await getToken();
      if (!token) return;
      const res = await fetch('/api/v1/schedules/leave-cascade-preview', {
        method: 'POST', credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ student_course_id: courseId, schedule_date: date }),
      });
      leaveCascadePlan.value = res.ok ? await res.json() : null;
    } catch (_) {
      leaveCascadePlan.value = null;
    } finally {
      leaveCascadePlanLoading.value = false;
    }
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
    const token = await getToken();
    if (!token) {
      leaveSubmitError.value = '請假登記失敗：請重新登入後再試';
      return;
    }
    leaveSubmitError.value = '';
    leaveSubmitting.value = true;
    try {
      const res = await fetch('/api/v1/schedules', {
        method: 'POST',
        credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        leaveSubmitError.value = body.message || res.statusText || '請稍後再試';
        return;
      }
      const undoScheduleId = Number(body?.undo?.schedule_id || 0);
      const undoWindowSec = Number(body?.undo?.undo_window_seconds || 30);
      const dateMode = body?.leave_mode === 'monthly_bounded';
      if (undoScheduleId > 0 && undoWindowSec > 0) {
        toastRef?.value?.show?.({
          title: '請假已送出',
          description: dateMode
            ? `本堂已請假（未來日期與合約結束日不變，不補尾），${undoWindowSec} 秒內可復原`
            : `本堂已請假（未來日期不變，已補尾堂），${undoWindowSec} 秒內可復原`,
          variant: 'success',
          durationMs: undoWindowSec * 1000,
          undoDescription: '已撤銷請假，尾堂已回復',
          onUndo: async () => {
            const undoRes = await fetch(`/api/v1/schedules/${undoScheduleId}/undo-leave`, {
              method: 'POST', credentials: 'include',
              headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            });
            const undoBody = await undoRes.json().catch(() => ({}));
            if (!undoRes.ok) throw new Error(undoBody.message || '撤銷請假失敗');
            await loadCourses();
          },
        });
      }
    } catch (error) {
      leaveSubmitError.value = error?.message || '請稍後再試';
      return;
    } finally {
      leaveSubmitting.value = false;
    }
    showLeaveModal.value = false;
    contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
    await loadCourses();
    if (!toastRef?.value) alert('請假登記完成');
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
    leaveSubmitError.value = '';
    showLeaveModal.value = true;
  };

  watch(() => leaveForm.value.schedule_date, () => {
    void refreshLeaveCascadePreview();
  });
  watch(showLeaveModal, (open) => {
    if (open) {
      void refreshLeaveCascadePreview();
    } else {
      leaveCascadePlan.value = null;
      leaveCascadePlanLoading.value = false;
      leavePreviewRequestKey.value = '';
      leaveSubmitError.value = '';
    }
  });

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
    const courseId = Number(editingCourseId.value || 0);
    if (!courseId) { alert('加課失敗：請從既有課程的單堂操作開啟'); return; }
    const token = await getToken();
    if (!token) { alert('加課失敗：請重新登入後再試'); return; }
    const res = await fetch(`/api/v1/student-classes/${courseId}/add-session`, {
      method: 'POST', credentials: 'include',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        session_date: extraForm.value.schedule_date,
        start_time: normalizeTimeTo30(extraForm.value.start_time),
        duration_minutes: Math.round(Number(extraForm.value.duration_hours || 0) * 60),
        teacher_id: extraForm.value.teacher_id || null,
        note: 'Calendar 加課／補登',
        auto_approve: true,
      }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      const detail = body?.errors ? Object.values(body.errors).flat().join(' ') : '';
      alert('加課失敗：' + (detail || body.message || res.statusText || '請稍後再試'));
      return;
    }

    showExtraModal.value = false;
    alert(body?.message || '加課建立完成，老師上課後需填寫評量表');
    await loadCourses();
  };

  return {
    showLeaveModal, leaveForm, leaveDisplay, leaveImpactPreview, leaveSubmitError, leaveSubmitting,
    openLeaveModal, submitLeave, onContextLeave,
    showExtraModal, extraForm, computedExtraEndTime, extraParentPaymentType,
    onExtraFormStartTimeChange, onExtraFormTimeChange, openExtraLesson, submitExtraLesson,
  };
}
