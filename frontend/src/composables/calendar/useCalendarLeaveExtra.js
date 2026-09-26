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
  const leavePreviewRequestVersion = ref(0);
  const leavePreviewSuccessKey = ref('');
  const leavePreviewError = ref('');
  const leaveSubmitError = ref('');
  const leaveSubmitting = ref(false);
  const leaveForm = ref({
    student_id: '', subject: '', day_of_week: 1,
    start_time: '', end_time: '', schedule_date: '', course_id: '',
    teacher_id: '', duration_hours: 2, class_type: 'one_on_one',
  });

  const leaveRequestKey = computed(() => JSON.stringify([Number(branchId.value ?? branchId), leaveForm.value]));
  const closeLeaveModal = () => { if (!leaveSubmitting.value) showLeaveModal.value = false; };
  const openLeaveModal = () => {
    if (leaveSubmitting.value) return;
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
    leavePreviewError.value = '';
    leavePreviewSuccessKey.value = '';
    showLeaveModal.value = true;
  };

  const formatPreviewDate = (value) => String(value || '').slice(5, 10).replace('-', '/');
  const leaveImpactPreview = computed(() => {
    const plan = leaveCascadePlan.value;
    if (!plan || !leavePreviewSuccessKey.value) return null;
    const isDateMode = Object.hasOwn(plan, 'contract_end_date');
    const items = [
      '本堂會標記為請假，不扣堂數',
      isDateMode ? '未來日期與合約結束日不變，不補尾'
        : plan.append ? '未來既有上課日不變，僅於尾端補上堂次' : '未來既有上課日不變，無新增尾堂',
      '該堂不需要填寫學習評量',
    ];
    if (plan?.next_billable_session?.date) {
      const ordinal = plan.next_billable_session.ordinal != null ? `第 ${plan.next_billable_session.ordinal} 堂` : '下一堂';
      items.push(`下一堂：${formatPreviewDate(plan.next_billable_session.date)}（${ordinal}）`);
    }
    if (plan?.append) {
      items.push(`尾堂補上：${formatPreviewDate(plan.append)}`);
    }
    return {
      title: '請假送出前影響預覽',
      summary: `${getStudentName(leaveForm.value.student_id)}｜${getSubjectLabel(leaveForm.value.subject)}｜${leaveForm.value.schedule_date}`,
      items,
    };
  });

  const leavePreviewReady = computed(() => {
    const courseId = Number(leaveForm.value.course_id || 0);
    const date = String(leaveForm.value.schedule_date || '').slice(0, 10);
    return Boolean(courseId && date && leavePreviewSuccessKey.value === leaveRequestKey.value
      && leaveCascadePlan.value && !leaveCascadePlanLoading.value && !leavePreviewError.value);
  });

  const refreshLeaveCascadePreview = async () => {
    const courseId = Number(leaveForm.value.course_id || 0);
    const date = String(leaveForm.value.schedule_date || '').slice(0, 10);
    if (!showLeaveModal.value || !courseId || !date) {
      leaveCascadePlan.value = null;
      leavePreviewSuccessKey.value = '';
      return;
    }
    if (leaveSubmitting.value) return;
    const requestKey = leaveRequestKey.value;
    // Both the date and modal-open watchers can fire for the same form
    // transition. One authoritative request is enough; do not invalidate it.
    if (leaveCascadePlanLoading.value && leavePreviewRequestKey.value === requestKey) return;
    leavePreviewRequestKey.value = requestKey;
    const requestVersion = ++leavePreviewRequestVersion.value;
    leaveCascadePlanLoading.value = true;
    leaveCascadePlan.value = null;
    leavePreviewSuccessKey.value = '';
    leavePreviewError.value = '';
    try {
      const token = await getToken();
      if (!token) throw new Error('請重新登入後再試');
      if (!showLeaveModal.value || requestKey !== leaveRequestKey.value || requestVersion !== leavePreviewRequestVersion.value) return;
      const res = await fetch('/api/v1/schedules/leave-cascade-preview', {
        method: 'POST', credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ student_course_id: courseId, schedule_date: date }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body?.message || res.statusText || '無法取得請假影響預覽');
      if (requestVersion !== leavePreviewRequestVersion.value || requestKey !== leaveRequestKey.value || !showLeaveModal.value) return;
      const dateOrNull = value => value === null || (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value));
      if (body?.policy !== 'KEEP_FUTURE_DATES_APPEND_TAIL' || body.leave_session_date !== date
        || body.future_dates_unchanged !== true || !Array.isArray(body.moves) || body.moves.length
        || !Array.isArray(body.vacated) || body.vacated.length || !dateOrNull(body.append)
        || !dateOrNull(body.extended_end_date)
        || (Object.hasOwn(body, 'contract_end_date') && (!dateOrNull(body.contract_end_date) || body.append !== null || body.extended_end_date !== null))
        || (body.next_billable_session !== null && (!body.next_billable_session || !dateOrNull(body.next_billable_session.date) || !body.next_billable_session.date))) {
        throw new Error('請假影響預覽資料不完整，請重試');
      }
      leaveCascadePlan.value = body;
      leavePreviewSuccessKey.value = requestKey;
    } catch (error) {
      if (requestVersion !== leavePreviewRequestVersion.value || requestKey !== leaveRequestKey.value || !showLeaveModal.value) return;
      leaveCascadePlan.value = null;
      leavePreviewSuccessKey.value = '';
      leavePreviewError.value = error?.message || '無法取得請假影響預覽，請重試';
    } finally {
      if (requestVersion === leavePreviewRequestVersion.value) leaveCascadePlanLoading.value = false;
    }
  };

  const submitLeave = async () => {
    if (leaveSubmitting.value) return;
    if (!leaveForm.value.schedule_date) { alert('請選擇日期'); return; }
    const studentId = Number(leaveForm.value.student_id) || 0;
    const courseId = Number(leaveForm.value.course_id) || null;
    const teacherId = Number(leaveForm.value.teacher_id) || null;
    const bid = Number(branchId.value ?? branchId) || 0;
    if (!studentId || !bid) { alert('請假登記失敗：缺少學生或分校資訊'); return; }
    if (!leavePreviewReady.value) {
      leaveSubmitError.value = leavePreviewError.value || '請先取得最新請假影響預覽後再送出';
      return;
    }
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
    const requestKey = leaveRequestKey.value;
    const requestVersion = leavePreviewRequestVersion.value;
    const ownsRequest = () => showLeaveModal.value && requestKey === leaveRequestKey.value && requestVersion === leavePreviewRequestVersion.value;
    leaveSubmitError.value = '';
    leaveSubmitting.value = true;
    try {
      const token = await getToken();
      if (!token) throw new Error('請重新登入後再試');
      if (!ownsRequest() || !leavePreviewReady.value) return;
      const res = await fetch('/api/v1/schedules', {
        method: 'POST',
        credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (!ownsRequest()) return;
        leaveSubmitError.value = body.message || res.statusText || '請稍後再試';
        return;
      }
      const undoScheduleId = Number(body?.undo?.schedule_id || 0);
      const undoWindowSec = Number(body?.undo?.undo_window_seconds ?? 0);

      if (!ownsRequest()) { await loadCourses(); return; }
      if (undoScheduleId > 0 && Number.isFinite(undoWindowSec) && undoWindowSec > 0) {
        toastRef?.value?.show?.({
          title: '請假已送出',
          description: `${body?.message || '請假已登記'}，${undoWindowSec} 秒內可復原`,
          variant: 'success',
          durationMs: undoWindowSec * 1000,
          undoDescription: '已撤銷請假，請確認重新整理後的課堂狀態',
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
      showLeaveModal.value = false;
      contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
      await loadCourses();
      if (!toastRef?.value) alert('請假登記完成');
    } catch (error) {
      if (ownsRequest()) leaveSubmitError.value = error?.message || '請稍後再試';
    } finally { leaveSubmitting.value = false; }
  };

  const onContextLeave = () => {
    if (leaveSubmitting.value) return;
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
    leavePreviewError.value = '';
    leavePreviewSuccessKey.value = '';
    showLeaveModal.value = true;
  };

  watch(leaveRequestKey, () => {
    void refreshLeaveCascadePreview();
  }, { flush: 'sync' });
  watch(showLeaveModal, (open) => {
    if (open) {
      void refreshLeaveCascadePreview();
    } else {
      leavePreviewRequestVersion.value += 1;
      leaveCascadePlan.value = null;
      leaveCascadePlanLoading.value = false;
      leavePreviewRequestKey.value = '';
      leavePreviewSuccessKey.value = '';
      leavePreviewError.value = '';
      leaveSubmitError.value = '';
    }
  }, { flush: 'sync' });

  const leaveDisplay = computed(() => ({
    studentName: getStudentName(leaveForm.value.student_id),
    subjectLabel: getSubjectLabel(leaveForm.value.subject),
    originalSlot: `${dayLabel(leaveForm.value.day_of_week)} ${leaveForm.value.start_time}~${leaveForm.value.end_time}`,
  }));

  const showExtraModal = ref(false);
  const extraForm = ref({
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    schedule_date: '', start_time: '16:00', end_time: '18:00', duration_hours: 2, auto_approve: false,
  });
  const extraSessionCheck = ref(null);
  const extraSessionChecking = ref(false);
  const extraSessionCheckError = ref('');
  let extraSessionCheckVersion = 0;
  const extraSubmitting = ref(false);
  let extraModalGeneration = 0;
  watch(showExtraModal, () => { extraModalGeneration += 1; }, { flush: 'sync' });
  const extraSuccessKey = ref('');
  let extraOpenedTarget = null;
  const extraTuple = computed(() => ({ courseId: Number(editingCourseId.value || 0), branchId: Number(branchId.value ?? branchId),
    studentId: extraForm.value.student_id, subject: extraForm.value.subject, classType: extraForm.value.class_type,
    session_date: String(extraForm.value.schedule_date || '').slice(0,10), start_time: normalizeTimeTo30(extraForm.value.start_time),
    duration_minutes: Math.round(Number(extraForm.value.duration_hours ?? 0)*60), teacher_id: extraForm.value.teacher_id || null, auto_approve: !!extraForm.value.auto_approve }));
  const extraRequestKey = computed(() => JSON.stringify(extraTuple.value));
  const extraSessionReady = computed(() => extraSuccessKey.value === extraRequestKey.value && !!extraSessionCheck.value && !extraSessionChecking.value && !extraSessionCheckError.value);
  const closeExtraModal = () => { if (!extraSubmitting.value) showExtraModal.value = false; };

  const refreshExtraSessionCheck = async () => {
    if (extraSubmitting.value) return;
    const tuple = { ...extraTuple.value };
    const requestKey = extraRequestKey.value;
    const courseId = tuple.courseId;
    const date = tuple.session_date;
    const startTime = tuple.start_time;
    if (!showExtraModal.value || !courseId || !date || !startTime) {
      extraSessionCheckVersion += 1;
      extraSuccessKey.value = '';
      extraSessionCheck.value = null;
      extraSessionChecking.value = false;
      return;
    }
    if (!extraOpenedTarget || tuple.courseId !== extraOpenedTarget.courseId || tuple.studentId !== extraOpenedTarget.studentId
      || tuple.subject !== extraOpenedTarget.subject || tuple.teacher_id !== extraOpenedTarget.teacher_id || tuple.classType !== extraOpenedTarget.classType) {
      extraSessionCheckVersion += 1; extraSuccessKey.value = ''; extraSessionCheck.value = null; extraSessionChecking.value = false;
      extraSessionCheckError.value = '課程目標已變更，請從目標課程重新開啟加課'; return;
    }
    const requestVersion = ++extraSessionCheckVersion;
    extraSuccessKey.value = '';
    extraSessionChecking.value = true;
    extraSessionCheck.value = null;
    extraSessionCheckError.value = '';
    try {
      const token = await getToken();
      if (!token) throw new Error('請重新登入後再試');
      if (!showExtraModal.value || requestKey !== extraRequestKey.value || requestVersion !== extraSessionCheckVersion) return;
      const res = await fetch(`/api/v1/student-classes/${courseId}/add-session/check`, {
        method: 'POST', credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          session_date: date,
          start_time: startTime,
          duration_minutes: tuple.duration_minutes,
          teacher_id: tuple.teacher_id,
        }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body?.message || res.statusText || '無法檢查加課時段');
      if (requestVersion !== extraSessionCheckVersion || requestKey !== extraRequestKey.value || !showExtraModal.value) return;
      if (typeof body?.can_add !== 'boolean' || typeof body?.is_ended !== 'boolean' || typeof body?.conflict_type !== 'string') throw new Error('加課檢查資料不完整，請重試');
      extraSessionCheck.value = body;
      extraSuccessKey.value = requestKey;
    } catch (error) {
      if (requestVersion !== extraSessionCheckVersion || requestKey !== extraRequestKey.value || !showExtraModal.value) return;
      extraSessionCheckError.value = error?.message || '無法檢查加課時段，請重試';
    } finally {
      if (requestVersion === extraSessionCheckVersion) extraSessionChecking.value = false;
    }
  };

  const onExtraFormStartTimeChange = () => {
    extraForm.value.start_time = normalizeTimeTo30(extraForm.value.start_time);
    extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
    // The complete tuple watcher refreshes the check.
  };
  const onExtraFormTimeChange = () => {
    extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
    // The complete tuple watcher refreshes the check.
  };
  const computedExtraEndTime = computed(() =>
    computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours),
  );
  const extraParentPaymentType = computed(() => modalForm.value?.payment_type || 'session');

  const openExtraLesson = () => {
    if (extraSubmitting.value) return;
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
      auto_approve: false,
    };
    extraOpenedTarget = { ...extraTuple.value };
    extraSessionCheck.value = null;
    extraSessionCheckError.value = '';
    showModal.value = false;
    showExtraModal.value = true;
  };

  watch([extraRequestKey, showExtraModal], () => { void refreshExtraSessionCheck(); }, { flush: 'sync' });

  const submitExtraLesson = async () => {
    if (extraSubmitting.value) return;
    if (!extraForm.value.student_id) { alert('請選擇學生'); return; }
    if (!extraForm.value.schedule_date) { alert('請選擇日期'); return; }
    const courseId = Number(editingCourseId.value || 0);
    if (!courseId) { alert('加課失敗：請從既有課程的單堂操作開啟'); return; }
    if (!extraSessionReady.value) {
      alert(extraSessionCheckError.value || '請先完成加課時段檢查後再送出');
      return;
    }
    if (!extraSessionCheck.value.can_add) {
      alert(extraSessionCheck.value.message || '此時段無法加課，請重新檢查');
      return;
    }
    const tuple = { ...extraTuple.value };
    const requestKey = extraRequestKey.value;
    const generation = extraModalGeneration;
    const ownsRequest = () => showExtraModal.value && requestKey === extraRequestKey.value && generation === extraModalGeneration;
    extraSubmitting.value = true;
    try {
    const token = await getToken();
    if (!token) throw new Error('請重新登入後再試');
    if (!ownsRequest() || !extraSessionReady.value) return;
    const res = await fetch(`/api/v1/student-classes/${courseId}/add-session`, {
      method: 'POST', credentials: 'include',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        session_date: tuple.session_date,
        start_time: tuple.start_time,
        duration_minutes: tuple.duration_minutes,
        teacher_id: tuple.teacher_id,
        note: 'Calendar 加課／補登',
        // Preserve the backend's false default and send only the operator's
        // explicit choice after the matching /check has returned.
        auto_approve: tuple.auto_approve,
      }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      if (!ownsRequest()) return;
      const detail = body?.errors ? Object.values(body.errors).flat().join(' ') : '';
      alert('加課失敗：' + (detail || body.message || res.statusText || '請稍後再試'));
      return;
    }

    if (!ownsRequest()) { await loadCourses(); return; }
    showExtraModal.value = false;
    alert(body?.message || '加課建立完成，老師上課後需填寫評量表');
    await loadCourses();
    } catch (error) { if (ownsRequest()) alert('加課失敗：' + (error?.message || '請稍後再試')); }
    finally { extraSubmitting.value = false; }
  };

  return {
    showLeaveModal, closeLeaveModal, leaveCascadePlan, leaveForm, leaveDisplay, leaveImpactPreview, leavePreviewReady, leaveCascadePlanLoading, leavePreviewError, leaveSubmitError, leaveSubmitting,
    openLeaveModal, refreshLeaveCascadePreview, submitLeave, onContextLeave,
    showExtraModal, closeExtraModal, extraSubmitting, extraSessionReady, extraForm, computedExtraEndTime, extraParentPaymentType,
    extraSessionCheck, extraSessionChecking, extraSessionCheckError,
    onExtraFormStartTimeChange, onExtraFormTimeChange, refreshExtraSessionCheck, openExtraLesson, submitExtraLesson,
  };
}
