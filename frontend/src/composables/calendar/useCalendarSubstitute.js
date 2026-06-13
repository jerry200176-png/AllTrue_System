import { ref, computed } from 'vue';
import { resolveSessionIdForSubstitute } from '../../lib/classSessionPick.js';
import { undoSubstitute } from '../../lib/substituteApi.js';

const FEATURE_SUBSTITUTE_V2 = ((import.meta?.env?.VITE_FEATURE_SUBSTITUTE_V2 ?? '1') + '') !== '0';

/** #740 Step 7b2a：代課 modal 流程（legacy + V2 + batch） */
export function useCalendarSubstitute({
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  teachers,
  sessionDatesByCourseId,
  allStudents,
  getSubjectLabel,
}) {
  const getStudentName = (sid) => {
    const s = allStudents.value.find((x) => x.id === sid);
    return s ? s.name : '—';
  };

  const teacherDisplayName = (tid) => {
    if (tid == null || tid === '') return '—';
    const t = (teachers.value || []).find((x) => String(x.id) === String(tid));
    return t?.name || t?.username || '—';
  };

  const showSubstituteModal = ref(false);
  const substituteSubmitting = ref(false);
  const substituteForm = ref({
    student_id: '', subject: '', session_date: '', start_time: '', end_time: '',
    original_teacher_name: '', substitute_teacher_id: '', reason: '',
    session_id: null, course_id: null,
  });

  const openSubstituteFromDrag = (course, dateStr, dropTeacherId) => {
    const baseId = course.is_exception ? course.student_course_id : course.id;
    substituteForm.value = {
      student_id: course.student_id,
      subject: course.subject,
      session_date: dateStr,
      start_time: course.start_time || '',
      end_time: course.end_time || '',
      original_teacher_name: teacherDisplayName(course.teacher_id),
      substitute_teacher_id: dropTeacherId != null && dropTeacherId !== '' ? String(dropTeacherId) : '',
      reason: '行事曆拖曳至代課老師',
      session_id: null,
      course_id: baseId,
    };
    if (baseId && sessionDatesByCourseId.value) {
      const sessions = sessionDatesByCourseId.value[String(baseId)] || [];
      const sid = resolveSessionIdForSubstitute(sessions, dateStr, course.start_time);
      if (sid) substituteForm.value.session_id = sid;
    }
    showSubstituteModal.value = true;
  };

  const openSubstituteModal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    substituteForm.value = {
      student_id: modalForm.value.student_id,
      subject: modalForm.value.subject,
      session_date: exactDate,
      start_time: modalForm.value.start_time || '',
      end_time: modalForm.value.end_time || '',
      original_teacher_name: teacherDisplayName(modalForm.value.teacher_id),
      substitute_teacher_id: '',
      reason: '',
      session_id: null,
      course_id: editingCourseId.value,
    };
    showModal.value = false;

    const courseId = editingCourseId.value;
    if (courseId && sessionDatesByCourseId.value) {
      const sessions = sessionDatesByCourseId.value[String(courseId)] || [];
      const sid = resolveSessionIdForSubstitute(sessions, exactDate, modalForm.value.start_time);
      if (sid) substituteForm.value.session_id = sid;
    }

    showSubstituteModal.value = true;
  };

  const submitSubstitute = async () => {
    if (!substituteForm.value.substitute_teacher_id) { alert('請選擇代課老師'); return; }
    if (!substituteForm.value.session_id) {
      alert('找不到該堂次 ClassSession，無法設定代課。\n（可能此日期尚未有 ClassSession 紀錄）');
      return;
    }

    substituteSubmitting.value = true;
    try {
      const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
      const token = session?.access_token || '';
      if (!token) { alert('請重新登入'); return; }

      const res = await fetch(`/api/v1/class-sessions/${substituteForm.value.session_id}/substitute`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          substitute_teacher_id: Number(substituteForm.value.substitute_teacher_id),
          reason: substituteForm.value.reason || null,
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert('代課設定失敗：' + (json.message || res.statusText));
        return;
      }
      showSubstituteModal.value = false;
      alert(json.message || '代課設定完成');
      await loadCourses();
    } catch (e) {
      alert('代課設定失敗：' + (e?.message || '請稍後再試'));
    } finally {
      substituteSubmitting.value = false;
    }
  };

  const substituteDisplay = computed(() => ({
    studentName: getStudentName(substituteForm.value.student_id),
    subjectLabel: getSubjectLabel(substituteForm.value.subject),
    sessionSlot: `${substituteForm.value.session_date} ${substituteForm.value.start_time}~${substituteForm.value.end_time}`,
  }));

  const featureSubstituteV2 = FEATURE_SUBSTITUTE_V2;
  const showSubstituteV2Modal = ref(false);
  const substituteV2PickerRef = ref(null);
  const toastRef = ref(null);
  const substituteV2Context = ref({});
  const substituteV2SessionId = ref(null);
  const substituteV2Submitting = ref(false);
  const showTeacherLeaveBatchModal = ref(false);

  const branchNameMap = computed(() => {
    const m = {};
    const bid = Number(branchId.value ?? branchId ?? 0);
    if (bid > 0) m[bid] = `分校#${bid}`;
    return m;
  });

  const openSubstituteV2Modal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    const courseId = editingCourseId.value;
    let sessionId = null;
    if (courseId && sessionDatesByCourseId.value) {
      const sessions = sessionDatesByCourseId.value[String(courseId)] || [];
      sessionId = resolveSessionIdForSubstitute(sessions, exactDate, modalForm.value.start_time);
    }
    if (!sessionId) {
      alert('找不到該堂次 ClassSession，無法設定代課。\n（可能此日期尚未有 ClassSession 紀錄）');
      return;
    }
    substituteV2SessionId.value = sessionId;
    substituteV2Context.value = {
      student_name: getStudentName(modalForm.value.student_id),
      subject_id: modalForm.value.subject_id || null,
      subject_label: getSubjectLabel(modalForm.value.subject),
      session_date: exactDate,
      start_time: (modalForm.value.start_time || '').toString().slice(0, 5),
      end_time: (modalForm.value.end_time || '').toString().slice(0, 5),
      original_teacher_id: modalForm.value.teacher_id,
      original_teacher_name: teacherDisplayName(modalForm.value.teacher_id),
      session_campus_id: Number(branchId.value ?? branchId ?? 0) || null,
    };
    showModal.value = false;
    showSubstituteV2Modal.value = true;
  };

  const onSubstituteV2Submit = async (submitPayload) => {
    if (substituteV2Submitting.value) return;
    const { substitute_teacher_id, reason, new_date, new_start_time, new_end_time } = submitPayload || {};
    const sessionId = substituteV2SessionId.value;
    substituteV2Submitting.value = true;
    try {
      const ses = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
      const tkn = ses?.access_token || '';
      if (!tkn) throw new Error('請重新登入');
      const body = {
        substitute_teacher_id: Number(substitute_teacher_id),
        reason: reason || null,
      };
      if (new_date && new_start_time && new_end_time) {
        body.new_date = new_date;
        body.new_start_time = new_start_time;
        body.new_end_time = new_end_time;
      }
      const res = await fetch(`/api/v1/class-sessions/${sessionId}/substitute`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${tkn}` },
        body: JSON.stringify(body),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 422 && json?.code === 'no_class_session') {
          substituteV2PickerRef.value?.setError?.(
            '此日期尚未建立課堂，請先在課程管理確認課堂日期，再重新指派代課。',
            'warning',
          );
        } else {
          const msg = json.message || res.statusText || '代課設定失敗';
          substituteV2PickerRef.value?.setError?.(msg);
        }
        throw new Error(json.message || res.statusText || '代課設定失敗');
      }
      showSubstituteV2Modal.value = false;
      const teacherName = json.substitute_teacher_name || teacherDisplayName(substitute_teacher_id);
      const uiSeconds = Number(json.undo_window_seconds);
      const durationMs = Number.isFinite(uiSeconds) && uiSeconds > 0 ? uiSeconds * 1000 : 5000;
      const isCombined = json.rescheduled === true || json.operation_type === 'substitute_with_reschedule';
      const effDate = json.session_date || substituteV2Context.value.session_date;
      const effStart = json.start_time || substituteV2Context.value.start_time;
      const effEnd = json.end_time || substituteV2Context.value.end_time;
      const studentName = substituteV2Context.value.student_name || '';
      const description = isCombined
        ? `${studentName ? `${studentName} · ` : ''}已調整至 ${effDate} ${effStart}~${effEnd}`
        : (studentName ? `${studentName} · ${effDate} ${effStart}` : '');
      toastRef.value?.show?.({
        title: isCombined ? `已指派 ${teacherName} 代課並調整時間` : `已指派 ${teacherName} 代課`,
        description,
        variant: 'success',
        durationMs,
        undoDescription: isCombined ? '代課與換時已撤銷，家長通知已作廢' : '代課已撤銷，家長通知已作廢',
        onUndo: async () => {
          await undoSubstitute(sessionId);
          await loadCourses();
        },
      });
      await loadCourses();
    } catch (e) {
      substituteV2PickerRef.value?.setError?.(e?.message || '代課設定失敗');
    } finally {
      substituteV2Submitting.value = false;
    }
  };

  const openTeacherLeaveBatch = () => {
    showTeacherLeaveBatchModal.value = true;
  };

  const onBatchSubstituteSubmitted = async (resp) => {
    const sum = resp?.summary || {};
    toastRef.value?.show?.({
      title: '批次代課完成',
      description: `成功 ${sum.success ?? 0} · 失敗 ${sum.fail ?? 0}${sum.cross_campus ? ` · 跨分校 ${sum.cross_campus}` : ''}`,
      variant: sum.fail ? 'info' : 'success',
      durationMs: 6000,
    });
    await loadCourses();
  };

  return {
    featureSubstituteV2,
    showSubstituteModal, substituteForm, substituteSubmitting, substituteDisplay,
    openSubstituteModal, openSubstituteFromDrag, submitSubstitute,
    showSubstituteV2Modal, substituteV2PickerRef, toastRef, substituteV2Context,
    substituteV2SessionId, substituteV2Submitting, branchNameMap,
    openSubstituteV2Modal, onSubstituteV2Submit, showTeacherLeaveBatchModal,
    openTeacherLeaveBatch, onBatchSubstituteSubmitted,
  };
}
