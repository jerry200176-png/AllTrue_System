import { ref, computed } from 'vue';
import { getPerSessionFee, getRateUnit } from '../../lib/coursePricing';
import { trackAdoptionEvent } from '../../lib/adoptionTelemetry';
import { sessionViewModelFromEnsureProjected, sessionViewModelPatchFromApi } from '../../lib/classSessionsApi';
import {
  formatRescheduleSuccessMessage,
  formatRescheduleConflictStudents,
  humanizeRescheduleFailure,
} from '../../lib/scheduleDisplay';
import { getSubjectLabel } from '../../lib/constants';
import { commitReschedule } from '../../lib/rescheduleApi';
import { canMaterializeProjectedSession } from '../../lib/sessionPlanningStatus';

const SESSION_STATUS_TRANSITIONS = {
  scheduled:      ['attended', 'late', 'absent', 'leave', 'cancelled'],
  attended:       ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
  completed:      ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
  late:           ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'cancelled'],
  absent:         ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'cancelled'],
  leave:          ['scheduled', 'attended', 'late', 'absent', 'cancelled'],
  leave_adjusted: ['cancelled'],
  cancelled:      ['scheduled'],
};

const SESSION_STATUS_LABELS = {
  scheduled: '排課中', attended: '已上', completed: '已上', late: '遲到', absent: '缺席',
  excused: '請假', leave: '請假', leave_adjusted: '請假',
  cancelled: '已取消',
};

export function useSessionEditFlow({
  supabase,
  branchId,
  computeEndTime,
  normalizeTo30Min,
  dayOfWeekFromDate,
  getSessionDisplayRow,
  getSessionRowsForDate,
  reloadCourseSessions,
  formatAttendanceTooltipTime,
  updateLocalSessionRow,
  ensureCompletedSessionDatesLoaded,
  displaySessions,
  todayYmd,
  rescheduleCourse,
  rescheduleForm,
  fetchMakeupSlots,
  loadCourses,
  openQuickAddSessionModal,
}) {
  const showSessionEditModal = ref(false);
  const sessionEditMode = ref('menu');
  const sessionEditSubmitting = ref(false);
  const sessionEditForm = ref({
    session_id: null, student_class_id: null,
    session_date: '', start_time: '', end_time: '', current_status: '',
    student_name: '', teacher_id: null, teacher_name: '', subject: '',
    attendance_time: '', lr_status: '', course: null,
    reason: '', new_date: '', new_start: '16:00', duration_hours: 2,
    note: '', edit_start_time: '', edit_end_time: '',
    session_charge: null,
    contract_rate: null, contract_session_duration: null, contract_rate_unit: 'session',
  });

  const secondaryStatusSelection = ref('');
  const secondaryStatusOptions = computed(() => {
    const current = sessionEditForm.value.current_status || '';
    const allowed = SESSION_STATUS_TRANSITIONS[current] || [];
    const hiddenPrimary = new Set(['scheduled', 'leave', 'leave_adjusted']);
    return allowed.filter((s) => !hiddenPrimary.has(s));
  });

  // Unified actionable dialog (replaces native alert dead-ends).
  const chipActionDialog = ref(null);

  function closeChipActionDialog() {
    chipActionDialog.value = null;
  }

  function openProjectedActionDialog(course, dateYmd, unit) {
    const dateYmdNorm = String(dateYmd || '').slice(0, 10);
    const startTime = unit?.startTime || '';
    const endTime = unit?.endTime || '';
    chipActionDialog.value = {
      kind: 'projected_quick_add',
      title: '這是預排日期，尚未建立正式堂次',
      message: '堂數制不會自動產生可編輯堂次。請確認後手動補排；直接推算建立僅適用月結固定時段。',
      meta: [dateYmdNorm, startTime && (endTime ? `${startTime}–${endTime}` : startTime)].filter(Boolean).join(' '),
      primaryLabel: '補排此堂',
      secondaryLabel: '返回',
      course,
      dateYmd: dateYmdNorm,
      startTime,
      endTime,
      busy: false,
    };
  }

  function openSessionResolveDialog(course, dateYmd, sessionId, unit, title, message) {
    chipActionDialog.value = {
      kind: 'resolve_retry',
      title: title || '暫時找不到這堂課的最新資料',
      message: message || '課表可能剛更新。尚未進行任何變更。',
      primaryLabel: '再試一次',
      secondaryLabel: '關閉',
      course,
      dateYmd: String(dateYmd || '').slice(0, 10),
      sessionId,
      unit,
      busy: false,
    };
  }

  async function confirmChipActionDialog() {
    const dlg = chipActionDialog.value;
    if (!dlg) return;
    if (dlg.kind === 'projected_quick_add') {
      const { course, dateYmd, startTime, endTime } = dlg;
      closeChipActionDialog();
      if (course && typeof openQuickAddSessionModal === 'function') {
        openQuickAddSessionModal(course, { date: dateYmd, startTime, endTime, source: 'projected_count_chip' });
      }
      return;
    }
    if (dlg.kind === 'resolve_retry') await retrySessionResolve();
  }

  function sessionStatusLabel(status) {
    return SESSION_STATUS_LABELS[status] || status || '—';
  }

  function canTransitionTo(target) {
    const current = sessionEditForm.value.current_status || '';
    const allowed = SESSION_STATUS_TRANSITIONS[current] || [];
    return allowed.includes(target);
  }

  function applySecondaryStatus() {
    if (!secondaryStatusSelection.value) return;
    const next = secondaryStatusSelection.value;
    secondaryStatusSelection.value = '';
    doStatusChange(next);
  }

  // Among the rows already cached for a date, prefer the one whose start_time
  // matches the clicked chip — disambiguates duplicate / overlapping sessions.
  function resolveRowByStartTime(course, dateYmd, unit) {
    const wanted = String(unit?.startTime || '').slice(0, 5);
    if (!wanted || typeof getSessionRowsForDate !== 'function') return null;
    const rows = getSessionRowsForDate(course, dateYmd) || [];
    return rows.find((r) => String(r?.startTime || '').slice(0, 5) === wanted) || null;
  }

  function populateSessionEditForm(course, dateYmd, row) {
    sessionEditForm.value = {
      session_id: row.id,
      student_class_id: row.studentClassId || course.id,
      session_date: dateYmd,
      start_time: row.startTime || '',
      end_time: row.endTime || '',
      current_status: String(row.status || '').toLowerCase(),
      // in-app #205: propagate for substitute picker exclude_student_id
      student_id: Number(course.student_id ?? course.StudentID ?? row.studentId ?? 0) || null,
      student_name: course.student_name || row.studentName || '—',
      teacher_id: row.teacherId || course.teacher_id || null,
      teacher_name: row.teacherName || course.teacher_name || '—',
      subject: course.subject || '',
      attendance_time: formatAttendanceTooltipTime(row.attendanceSignInAt) || '',
      lr_status: row.learningRecordStatus || '',
      course,
      reason: '',
      new_date: '',
      new_start: row.startTime || '16:00',
      duration_hours: course.duration_hours ?? 2,
      note: row.note || '',
      edit_start_time: row.startTime || '',
      edit_end_time: row.endTime || '',
      session_charge: row.sessionCharge ?? null,
      contract_rate: row.contractRate ?? getPerSessionFee(course),
      contract_session_duration: row.contractSessionDuration ?? (course?.duration_hours != null ? Math.round(Number(course.duration_hours) * 60) : null),
      contract_rate_unit: row.contractRateUnit || getRateUnit(course),
    };
    sessionEditMode.value = 'menu';
    secondaryStatusSelection.value = '';
    sessionEditSubmitting.value = false;
    showSessionEditModal.value = true;
  }

  async function openSessionEdit(course, dateYmd, sessionId, unit = null) {
    // Count-mode projected chips: never call ensure-projected (backend 422).
    // Offer quick-add prefill instead. Monthly date-mode still materializes.
    if (unit?.isProjected && !canMaterializeProjectedSession(course)) {
      openProjectedActionDialog(course, dateYmd, unit);
      return;
    }

    let row = getSessionDisplayRow(course, dateYmd, sessionId);
    // Miss: the chip points at a real session that is not in the local cache yet
    // (duplicate/overlap sessions, or a stale load). Reload this course's sessions
    // and retry — matching by start_time when several rows share the date — before
    // falling back to materialize/actionable dialog. See #942 (in-app #177).
    if (!row && typeof reloadCourseSessions === 'function') {
      const reloaded = await reloadCourseSessions(course);
      if (reloaded) {
        row = getSessionDisplayRow(course, dateYmd, sessionId)
          || resolveRowByStartTime(course, dateYmd, unit);
      }
    }
    if (!row && unit?.isProjected && canMaterializeProjectedSession(course)) {
      row = await materializeProjectedSession(course, dateYmd, unit);
      if (!row) return; // materialize shows its own resolve dialog on failure
    }
    if (!row) {
      openSessionResolveDialog(course, dateYmd, sessionId, unit);
      return;
    }
    populateSessionEditForm(course, dateYmd, row);
  }

  async function retrySessionResolve() {
    const dlg = chipActionDialog.value;
    if (!dlg?.course || dlg.kind !== 'resolve_retry') return;
    dlg.busy = true;
    try {
      const { course, dateYmd, sessionId, unit } = dlg;
      let row = null;
      if (typeof reloadCourseSessions === 'function' && await reloadCourseSessions(course)) {
        row = getSessionDisplayRow(course, dateYmd, sessionId) || resolveRowByStartTime(course, dateYmd, unit);
      }
      if (!row && unit?.isProjected && canMaterializeProjectedSession(course)) {
        row = await materializeProjectedSession(course, dateYmd, unit);
      }
      if (row) {
        closeChipActionDialog();
        populateSessionEditForm(course, dateYmd, row);
        return;
      }
      chipActionDialog.value = {
        ...dlg,
        title: '堂次載入失敗',
        message: '目前無法確認這堂課的最新狀態，尚未進行任何變更。',
        busy: false,
      };
    } finally {
      if (chipActionDialog.value) chipActionDialog.value.busy = false;
    }
  }

  async function materializeProjectedSession(course, dateYmd, unit) {
    if (!canMaterializeProjectedSession(course)) {
      openProjectedActionDialog(course, dateYmd, unit);
      return null;
    }
    if (sessionEditSubmitting.value) return null;
    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) {
        openSessionResolveDialog(course, dateYmd, unit?.id, unit, '請重新登入', '登入狀態已失效，請重新登入後再試。尚未進行任何變更。');
        return null;
      }
      const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
      const res = await fetch('/api/v1/class-sessions/ensure-projected', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          student_class_id: Number(course?.id || course?.ID || 0),
          session_date: String(dateYmd || '').slice(0, 10),
          start_time: unit?.startTime || undefined,
          branch_id: bid || undefined,
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.session) {
        openSessionResolveDialog(course, dateYmd, unit?.id, unit, '無法建立可編輯堂次', '固定時段推算建立失敗。尚未進行任何變更，可以再試一次。');
        return null;
      }
      const vm = sessionViewModelFromEnsureProjected(json.session);
      if (!vm) {
        openSessionResolveDialog(course, dateYmd, unit?.id, unit);
        return null;
      }
      updateLocalSessionRow(course?.id || course?.ID, vm);
      return vm;
    } catch (_) {
      openSessionResolveDialog(course, dateYmd, unit?.id, unit);
      return null;
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  async function openSessionEditFromAction(course) {
    await ensureCompletedSessionDatesLoaded(course);
    const dates = displaySessions(course);
    if (!Array.isArray(dates) || dates.length === 0) {
      alert('此課程目前沒有可操作的上課日期。');
      return;
    }
    const today = typeof todayYmd === 'object' ? todayYmd.value : todayYmd;
    const upcoming = dates.find((d) => String(d) >= today);
    const targetDate = upcoming || dates[0];
    await openSessionEdit(course, targetDate);
  }

  function closeSessionEdit() {
    showSessionEditModal.value = false;
    sessionEditMode.value = 'menu';
  }

  function addSessionFromModal() {
    const course = sessionEditForm.value?.course;
    if (course) {
      closeSessionEdit();
      openQuickAddSessionModal(course);
    }
  }

  async function doStatusChange(newStatus) {
    const form = sessionEditForm.value;
    if (!form.session_id) return;

    // #142§1/#596：請假 → 未上（取消請假）必須走 cascade-correct 的 undo 路徑，
    // 否則請假時自動順延的尾堂與 EndDate 不會回復，會多出一堂。一般狀態轉換維持原 PATCH。
    const isUndoLeave = form.current_status === 'leave' && newStatus === 'scheduled';

    const preview = isUndoLeave
      ? [
          '取消這次請假，並回復被順延的後續課程與結束日。',
          '（若後續堂次已有上課/補請假記錄，系統會擋下並提示。）',
        ].join('\n')
      : [
          `目前狀態：${sessionStatusLabel(form.current_status)}`,
          `變更後：${sessionStatusLabel(newStatus)}`,
          '本次操作會寫入堂次紀錄，可於單堂編輯改回。',
        ].join('\n');
    const confirmTitle = isUndoLeave ? '取消請假預覽' : '狀態變更預覽';
    if (!confirm(`${confirmTitle}\n\n${preview}\n\n確認送出？`)) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const res = isUndoLeave
        ? await fetch('/api/v1/schedules/undo-leave-by-session', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
            body: JSON.stringify({ class_session_id: form.session_id }),
          })
        : await fetch(`/api/v1/class-sessions/${form.session_id}`, {
            method: 'PATCH', credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
            body: JSON.stringify({ status: newStatus, reason: form.reason || '' }),
          });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert((isUndoLeave ? '取消請假失敗：' : '狀態更新失敗：') + (json.message || res.statusText));
        return;
      }
      if (!isUndoLeave && json.session) {
        const vm = sessionViewModelFromEnsureProjected(json.session);
        if (vm) updateLocalSessionRow(form.student_class_id || form.course?.id, vm);
      }
      const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
      const event = (form.current_status !== 'scheduled' && newStatus === 'scheduled') ? 'flow_undone' : 'flow_submitted';
      trackAdoptionEvent(event, bid, { flow: 'session_status', from: form.current_status, to: newStatus });
      closeSessionEdit();
      alert(json.message || (isUndoLeave ? '已取消請假' : '狀態已更新'));
      await loadCourses();
    } catch (e) {
      alert('操作失敗：' + (e?.message || '請稍後再試'));
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  function startRetroLeave() {
    sessionEditMode.value = 'retro-leave';
  }

  async function doRetroLeave() {
    const form = sessionEditForm.value;
    if (!form.session_id) return;
    if (!confirm('此堂已上課/已點名，確認要執行補請假嗎？\n（將沖回堂數、作廢出缺勤與評量記錄）')) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const res = await fetch(`/api/v1/class-sessions/${form.session_id}`, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status: 'leave_adjusted', reason: form.reason || '' }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert('補請假失敗：' + (json.message || res.statusText));
        return;
      }
      if (json.session) {
        const vm = sessionViewModelFromEnsureProjected(json.session);
        if (vm) updateLocalSessionRow(form.student_class_id || form.course?.id, vm);
      }
      closeSessionEdit();
      alert(json.message || '補請假完成');
      await loadCourses();
    } catch (e) {
      alert('操作失敗：' + (e?.message || '請稍後再試'));
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  function startSessionReschedule() {
    sessionEditMode.value = 'reschedule';
    sessionEditForm.value.new_date = '';
    sessionEditForm.value.new_start = sessionEditForm.value.start_time || '16:00';
  }

  async function fetchMakeupSlotsForEdit() {
    const form = sessionEditForm.value;
    if (!form.course) return;
    rescheduleCourse.value = form.course;
    rescheduleForm.value = {
      ...rescheduleForm.value,
      student_id: form.course.student_id,
      student_name: form.student_name,
      subject: form.subject,
      teacher_id: form.course.teacher_id,
      class_type: form.course.class_type || 'one_on_one',
      duration_hours: form.duration_hours,
      course_id: form.student_class_id || form.course.id,
      original_date: form.session_date,
      original_start: form.start_time,
      original_end: form.end_time,
    };
    await fetchMakeupSlots();
  }

  async function doSessionReschedule() {
    const form = sessionEditForm.value;
    if (!form.new_date || !form.session_id) return;
    const reschedulePreview = [
      `原堂次：${form.session_date} ${form.start_time}~${form.end_time}`,
      `新堂次：${form.new_date} ${form.new_start}`,
      '系統會一次同步課表、點名與評量資料。',
    ].join('\n');
    if (!confirm(`調課影響預覽\n\n${reschedulePreview}\n\n確認送出？`)) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
      const course = form.course;
      const newEnd = computeEndTime(form.new_start, form.duration_hours);

      await commitReschedule({
        token,
        payload: {
          student_class_id: form.student_class_id || course.id,
          old_date: form.session_date,
          old_start_time: form.start_time,
          new_date: form.new_date,
          start_time: normalizeTo30Min(form.new_start),
          end_time: newEnd,
          subject: form.subject || null,
        },
      });

      closeSessionEdit();
      alert(formatRescheduleSuccessMessage({
        studentName: course.student_name || form.student_name,
        subject: getSubjectLabel(form.subject) || form.subject,
        originalDate: form.session_date,
        originalStart: form.start_time,
        originalEnd: form.end_time,
        newDate: form.new_date,
        newStart: normalizeTo30Min(form.new_start),
        newEnd,
      }));
      trackAdoptionEvent('flow_submitted', bid, { flow: 'reschedule', source: 'session-edit-atomic' });
      await loadCourses();
    } catch (error) {
      let message = humanizeRescheduleFailure(error?.message || '調課未完成，資料沒有變更');
      const conflictLine = formatRescheduleConflictStudents(error?.conflicts?.[0]?.overlap_details);
      if (conflictLine) message = `${message}\n${conflictLine}`;
      alert('調課失敗：' + message);
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  function startEditNoteTime() {
    sessionEditMode.value = 'edit-note-time';
  }

  async function doEditNoteTime() {
    const form = sessionEditForm.value;
    if (!form.session_id) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const body = { status: form.current_status };
      if (form.edit_start_time) body.start_time = form.edit_start_time;
      if (form.edit_end_time) body.end_time = form.edit_end_time;
      body.note = form.note ?? '';

      const res = await fetch(`/api/v1/class-sessions/${form.session_id}`, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert('儲存失敗：' + (json.message || res.statusText));
        return;
      }
      if (json.session) {
        const vm = sessionViewModelFromEnsureProjected(json.session);
        if (vm) updateLocalSessionRow(form.student_class_id || form.course?.id, vm);
      }
      closeSessionEdit();
      await loadCourses();
    } catch (e) {
      alert('操作失敗：' + (e?.message || '請稍後再試'));
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  function startSubstitute() {
    sessionEditMode.value = 'substitute';
    sessionEditForm.value.substitute_teacher_id = '';
    sessionEditForm.value.substitute_reason = '';
  }

  async function doSubstitute() {
    const form = sessionEditForm.value;
    if (!form.session_id || !form.substitute_teacher_id) return;
    if (!confirm('確定要將此堂換為代課老師嗎？（僅影響此堂，不影響後續排課）')) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const res = await fetch(`/api/v1/class-sessions/${form.session_id}/substitute`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
          substitute_teacher_id: Number(form.substitute_teacher_id),
          reason: form.substitute_reason || null,
        }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        let errMsg = json.message || res.statusText;
        if (res.status === 409 && Array.isArray(json.conflicts) && json.conflicts.length > 0) {
          const details = json.conflicts[0]?.overlap_details;
          const conflictLine = formatRescheduleConflictStudents(details);
          if (conflictLine) errMsg = `${errMsg}\n${conflictLine}`;
        }
        alert('代課設定失敗：' + errMsg);
        return;
      }
      // Immediately patch the local row so tooltip/list shows the new teacher before full reload
      if (json.substitute_teacher_id) {
        const patch = sessionViewModelPatchFromApi({
          id: form.session_id,
          teacher_id: json.substitute_teacher_id,
          teacher_name: json.substitute_teacher_name || '',
        });
        if (patch) updateLocalSessionRow(form.student_class_id || form.course?.id, patch);
      }
      closeSessionEdit();
      alert(json.message || '代課設定完成');
      await loadCourses();
    } catch (e) {
      alert('代課設定失敗：' + (e?.message || '請稍後再試'));
    } finally {
      sessionEditSubmitting.value = false;
    }
  }

  return {
    showSessionEditModal, sessionEditMode, sessionEditSubmitting, sessionEditForm,
    secondaryStatusSelection, secondaryStatusOptions,
    SESSION_STATUS_TRANSITIONS, SESSION_STATUS_LABELS,
    sessionStatusLabel, canTransitionTo, applySecondaryStatus,
    openSessionEdit, openSessionEditFromAction, closeSessionEdit,
    addSessionFromModal, doStatusChange,
    startRetroLeave, doRetroLeave,
    startSessionReschedule, fetchMakeupSlotsForEdit, doSessionReschedule,
    startSubstitute, doSubstitute,
    startEditNoteTime, doEditNoteTime,
    chipActionDialog, closeChipActionDialog, confirmChipActionDialog,
    canMaterializeProjectedSession,
  };
}
