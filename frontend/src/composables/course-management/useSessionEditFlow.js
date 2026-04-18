import { ref, computed } from 'vue';

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
    student_name: '', teacher_name: '', subject: '',
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

  function openSessionEdit(course, dateYmd, sessionId) {
    const row = getSessionDisplayRow(course, dateYmd, sessionId);
    if (!row) {
      // Synthetic chips (rendered from schedule before ClassSession loads) or
      // any other code path that supplies an unresolvable sessionId used to
      // fall through silently — the modal simply never opened and the user
      // (a 主任) was stuck with "button does nothing". Show an explicit
      // message so the user knows to refresh. See PRD §FR-006.
      alert('此堂次資料尚未載入，請重新整理頁面後再試。');
      return;
    }
    sessionEditForm.value = {
      session_id: row.id,
      student_class_id: row.student_class_id || course.id,
      session_date: dateYmd,
      start_time: row.start_time || '',
      end_time: row.end_time || '',
      current_status: String(row.status || '').toLowerCase(),
      student_name: course.student_name || row.student_name || '—',
      teacher_name: row.teacher_name || course.teacher_name || '—',
      subject: course.subject || '',
      attendance_time: formatAttendanceTooltipTime(row.attendance_sign_in_at) || '',
      lr_status: row.learning_record_status || '',
      course,
      reason: '',
      new_date: '',
      new_start: row.start_time || '16:00',
      duration_hours: course.duration_hours ?? 2,
      note: row.note || '',
      edit_start_time: row.start_time || '',
      edit_end_time: row.end_time || '',
      session_charge: row.session_charge ?? null,
      contract_rate: row.contract_rate ?? (course?.rate_per_30min != null ? Number(course.rate_per_30min) * 2 : null),
      contract_session_duration: row.contract_session_duration ?? (course?.duration_hours != null ? Math.round(Number(course.duration_hours) * 60) : null),
      contract_rate_unit: row.contract_rate_unit || 'session',
    };
    sessionEditMode.value = 'menu';
    secondaryStatusSelection.value = '';
    sessionEditSubmitting.value = false;
    showSessionEditModal.value = true;
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
    openSessionEdit(course, targetDate);
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
    if (!confirm(`確定要將此堂狀態改為「${sessionStatusLabel(newStatus)}」嗎？`)) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const res = await fetch(`/api/v1/class-sessions/${form.session_id}`, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status: newStatus, reason: form.reason || '' }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        alert('狀態更新失敗：' + (json.message || res.statusText));
        return;
      }
      if (json.session) {
        updateLocalSessionRow(form.student_class_id || form.course?.id, json.session);
      }
      closeSessionEdit();
      alert(json.message || '狀態已更新');
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
        updateLocalSessionRow(form.student_class_id || form.course?.id, json.session);
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
    if (!confirm(`確定要將 ${form.session_date} 的課程調到 ${form.new_date} ${form.new_start} 嗎？`)) return;

    sessionEditSubmitting.value = true;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) { alert('請重新登入'); return; }

      const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
      const course = form.course;
      const newEnd = computeEndTime(form.new_start, form.duration_hours);
      const newDayOfWeek = dayOfWeekFromDate(form.new_date);

      const payload1 = {
        student_id: course.student_id, teacher_id: course.teacher_id || null, subject: form.subject,
        day_of_week: dayOfWeekFromDate(form.session_date),
        start_time: form.start_time, end_time: form.end_time,
        duration_hours: form.duration_hours, class_type: course.class_type || 'one_on_one',
        status: 'rescheduled', type: 'normal', deduction: 0, branch_id: bid,
        student_course_id: form.student_class_id || course.id, schedule_date: form.session_date,
      };
      const payload2 = (originalId) => ({
        student_id: course.student_id, teacher_id: course.teacher_id || null, subject: form.subject,
        day_of_week: newDayOfWeek, start_time: normalizeTo30Min(form.new_start), end_time: newEnd,
        duration_hours: form.duration_hours, class_type: course.class_type || 'one_on_one',
        status: 'scheduled', type: 'normal', deduction: 1, branch_id: bid,
        schedule_date: form.new_date, original_schedule_id: originalId,
        student_course_id: form.student_class_id || course.id,
      });

      let originalId = null;
      const existingRes = await fetch(
        `/api/v1/schedules?branch_id=${bid}&student_course_id=${form.student_class_id || course.id}&schedule_date=${form.session_date}&status=rescheduled&__limit=1`,
        { credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } }
      );
      if (existingRes.ok) {
        const existingList = await existingRes.json();
        const arr = Array.isArray(existingList) ? existingList : existingList?.data ?? [];
        if (arr.length > 0 && arr[0].id) originalId = arr[0].id;
      }
      if (originalId == null) {
        const r1 = await fetch('/api/v1/schedules', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify(payload1),
        });
        if (!r1.ok) {
          const err = await r1.json().catch(() => ({}));
          alert('調課失敗：' + (err.message || '無法寫入原堂次紀錄'));
          return;
        }
        const created = await r1.json();
        originalId = created?.id ?? null;
      }
      const r2 = await fetch('/api/v1/schedules', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(payload2(originalId)),
      });
      if (!r2.ok) {
        const err = await r2.json().catch(() => ({}));
        alert('調課失敗：' + (err.message || '無法寫入新堂次'));
        return;
      }
      // FR-002/003: pass old_start_time so the backend can uniquely locate the
      // correct ClassSession when a student has multiple time slots on the same day,
      // and surface API errors instead of silently swallowing them.
      if (form.student_class_id || course.id) {
        try {
          const rescheduleRes = await fetch('/api/v1/learning-records/reschedule-session', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify({
              student_class_id: form.student_class_id || course.id,
              old_date: form.session_date,
              old_start_time: form.start_time || undefined,
              new_date: form.new_date,
              start_time: normalizeTo30Min(form.new_start), end_time: newEnd,
            }),
          });
          if (!rescheduleRes.ok) {
            const err = await rescheduleRes.json().catch(() => ({}));
            alert('調課失敗：' + (err.message || '找不到指定堂次，請確認日期與時間是否正確'));
            return;
          }
        } catch (e) {
          alert('調課失敗：' + (e?.message || '網路錯誤，請稍後再試'));
          return;
        }
      }

      closeSessionEdit();
      alert('調課完成');
      await loadCourses();
    } catch (e) {
      alert('調課失敗：' + (e?.message || '請稍後再試'));
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
        updateLocalSessionRow(form.student_class_id || form.course?.id, json.session);
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
          if (Array.isArray(details) && details.length > 0) {
            const names = details.map((d) => d.student_name || `#${d.student_id}`).join('、');
            errMsg += `\n衝突學生：${names}`;
          }
        }
        alert('代課設定失敗：' + errMsg);
        return;
      }
      // Immediately patch the local row so tooltip/list shows the new teacher before full reload
      if (json.substitute_teacher_id) {
        updateLocalSessionRow(form.student_class_id || form.course?.id, {
          id: form.session_id,
          teacher_id: json.substitute_teacher_id,
          teacher_name: json.substitute_teacher_name || '',
        });
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
  };
}
