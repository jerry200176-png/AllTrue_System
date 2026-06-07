import { ref, computed } from 'vue';
import { resolveSessionIdForSubstitute } from '../../lib/classSessionPick.js';
import { undoSubstitute } from '../../lib/substituteApi.js';
import {
  dayLabel,
  dayOfWeekFromDate,
  normalizeTimeTo30,
  computeEndTime,
} from '../../lib/calendarFormat.js';

const FEATURE_SUBSTITUTE_V2 = ((import.meta?.env?.VITE_FEATURE_SUBSTITUTE_V2 ?? '1') + '') !== '0';

/** #740 Step 7b2：代課 + 調課 modal 流程 */
export function useCalendarSubstituteReschedule({
  supabase,
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  getToken,
  teachers,
  sessionDatesByCourseId,
  allStudents,
  courses,
  exceptions,
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

  // ===== Substitute Teacher (換代課老師) =====
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

  // ===== PRD 9c058f19 代課流程 UX 優化（V2） =====
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

  // ===== Reschedule (調課) =====
  const showRescheduleModal = ref(false);
  const rescheduleForm = ref({
    student_id: '', subject: '', course_id: '',
    original_day: 1, original_start: '', original_end: '',
    new_date: '', new_day_of_week: 1, new_start: '', new_end: '',
    teacher_id: '', class_type: '', duration_hours: 2,
  });

  const onRescheduleNewStartChange = () => {
    rescheduleForm.value.new_start = normalizeTimeTo30(rescheduleForm.value.new_start);
    rescheduleForm.value.new_end = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);
  };
  const computedRescheduleNewEnd = computed(() =>
    computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
  );

  const openRescheduleModal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    const newStart = normalizeTimeTo30(modalForm.value.start_time);
    const dur = modalForm.value.duration_hours || 2;
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

  const resolveTeacherIdForRescheduledSlot = (anchorId, courseId, fallbackTeacherId) => {
    if (anchorId == null || anchorId === '' || !courseId) {
      return fallbackTeacherId ?? null;
    }
    const subEx = exceptions.value.find((ex) =>
      ex.status === 'scheduled'
      && ex.original_schedule_id != null
      && String(ex.original_schedule_id) === String(anchorId)
      && String(ex.student_course_id) === String(courseId));
    if (subEx?.teacher_id == null || String(subEx.teacher_id).trim() === '') {
      return fallbackTeacherId ?? null;
    }
    const substituteTid = Number(subEx.teacher_id);
    const fbNum = fallbackTeacherId != null && fallbackTeacherId !== ''
      ? Number(fallbackTeacherId)
      : 0;
    const baseCourse = courses.value.find((c) => String(c.id) === String(courseId));
    const contractTid = baseCourse?.teacher_id != null ? Number(baseCourse.teacher_id) : 0;
    if (
      contractTid > 0
      && Number.isFinite(substituteTid)
      && substituteTid > 0
      && substituteTid !== contractTid
      && (fbNum === 0 || fbNum === contractTid)
    ) {
      return substituteTid;
    }
    return fallbackTeacherId ?? null;
  };

  const submitReschedule = async () => {
    if (!rescheduleForm.value.new_date) { alert('請選擇新日期'); return; }
    const newDayOfWeek = dayOfWeekFromDate(rescheduleForm.value.new_date);
    const bid = Number(branchId.value ?? branchId) || 0;
    if (!bid) { alert('請先選擇分校'); return; }

    const payload1 = {
      student_id: rescheduleForm.value.student_id,
      teacher_id: rescheduleForm.value.teacher_id || null,
      subject: rescheduleForm.value.subject,
      day_of_week: rescheduleForm.value.original_day,
      start_time: rescheduleForm.value.original_start,
      end_time: rescheduleForm.value.original_end,
      duration_hours: rescheduleForm.value.duration_hours,
      class_type: rescheduleForm.value.class_type,
      status: 'rescheduled',
      type: 'normal',
      deduction: 0,
      branch_id: bid,
      student_course_id: rescheduleForm.value.course_id,
      schedule_date: rescheduleForm.value.original_date,
    };

    const alreadyRescheduled = exceptions.value.some((ex) =>
      (ex.status === 'rescheduled' || ex.status === 'leave')
      && String(ex.student_course_id) === String(rescheduleForm.value.course_id)
      && ex.schedule_date === rescheduleForm.value.original_date,
    );
    let originalId = null;
    if (!alreadyRescheduled) {
      const res1 = await supabase.from('schedules').insert([payload1]);
      if (res1.error) {
        alert('調課失敗：' + (res1.error?.message || '無法寫入原堂次紀錄'));
        return;
      }
      const origList = Array.isArray(res1.data) ? res1.data : (res1.data ? [res1.data] : []);
      originalId = origList[0]?.id ?? null;
    } else {
      const existing = exceptions.value.find((ex) =>
        ex.status === 'rescheduled'
        && String(ex.student_course_id) === String(rescheduleForm.value.course_id)
        && ex.schedule_date === rescheduleForm.value.original_date,
      );
      originalId = existing?.id ?? null;
    }

    const newEnd = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);

    const alreadySubstituted = originalId !== null && exceptions.value.some((ex) =>
      ex.status === 'scheduled'
      && ex.original_schedule_id != null
      && String(ex.original_schedule_id) === String(originalId)
      && String(ex.student_course_id) === String(rescheduleForm.value.course_id),
    );

    if (!alreadySubstituted) {
      const effectiveTid = resolveTeacherIdForRescheduledSlot(
        originalId,
        rescheduleForm.value.course_id,
        rescheduleForm.value.teacher_id,
      );
      const payload2 = {
        student_id: rescheduleForm.value.student_id,
        teacher_id: effectiveTid != null && effectiveTid !== '' ? effectiveTid : null,
        subject: rescheduleForm.value.subject,
        day_of_week: newDayOfWeek,
        start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
        end_time: newEnd,
        duration_hours: rescheduleForm.value.duration_hours,
        class_type: rescheduleForm.value.class_type,
        status: 'scheduled',
        type: 'normal',
        deduction: 1,
        branch_id: bid,
        schedule_date: rescheduleForm.value.new_date,
        original_schedule_id: originalId,
        student_course_id: rescheduleForm.value.course_id,
      };

      const res2 = await supabase.from('schedules').insert([payload2]);
      if (res2.error) {
        if (originalId) {
          await supabase.from('schedules').delete().eq('id', originalId);
        }
        const errMsg = res2.error?.message || '無法寫入新堂次';
        const isConflict = res2.error?.conflicts?.length > 0 || String(errMsg).includes('已有') || String(errMsg).includes('上限');
        if (isConflict) {
          alert('調課失敗：目標時段已有其他學生（撞課），請換一個時段再試。\n\n詳細：' + errMsg);
        } else {
          alert('調課失敗：' + errMsg);
        }
        return;
      }
    }

    if (rescheduleForm.value.course_id) {
      try {
        const token = await getToken();
        const resched = await fetch('/api/v1/learning-records/reschedule-session', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            student_class_id: rescheduleForm.value.course_id,
            old_date: rescheduleForm.value.original_date || null,
            old_start_time: rescheduleForm.value.original_start || undefined,
            new_date: rescheduleForm.value.new_date,
            start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
            end_time: computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
          }),
        });
        if (!resched.ok) {
          const err = await resched.json().catch(() => ({}));
          alert('調課失敗：' + (err.message || '找不到指定堂次，請確認日期與時間是否正確'));
          return;
        }
      } catch (e) {
        alert('調課失敗：' + (e?.message || '網路錯誤，請稍後再試'));
        return;
      }
    }

    showRescheduleModal.value = false;
    await loadCourses();
    alert('調課完成');
  };

  const rescheduleDisplay = computed(() => ({
    studentName: getStudentName(rescheduleForm.value.student_id),
    subjectLabel: getSubjectLabel(rescheduleForm.value.subject),
    originalSlot: `${dayLabel(rescheduleForm.value.original_day)} ${rescheduleForm.value.original_start}~${rescheduleForm.value.original_end}`,
  }));

  return {
    featureSubstituteV2,
    showSubstituteModal, substituteForm, substituteSubmitting, substituteDisplay,
    openSubstituteModal, openSubstituteFromDrag, submitSubstitute,
    showSubstituteV2Modal, substituteV2PickerRef, toastRef, substituteV2Context,
    substituteV2SessionId, substituteV2Submitting, branchNameMap,
    openSubstituteV2Modal, onSubstituteV2Submit, showTeacherLeaveBatchModal,
    openTeacherLeaveBatch, onBatchSubstituteSubmitted,
    showRescheduleModal, rescheduleForm, rescheduleDisplay, computedRescheduleNewEnd,
    onRescheduleNewStartChange, openRescheduleModal, submitReschedule,
    resolveTeacherIdForRescheduledSlot,
  };
}
