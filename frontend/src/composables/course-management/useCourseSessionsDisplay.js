import { ref, computed } from 'vue';

const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);
const ATTENDED_SESSION_STATUSES = new Set(['completed', 'attended', 'late']);

export function useCourseSessionsDisplay({
  classSessionsByCourse,
  completedSessionDatesByCourse,
  effectiveSessionDatesByCourse,
  fetchClassSessionsFn,
  supabase,
  branchId,
}) {
  const expandedDates = ref(new Set());

  async function ensureCompletedSessionDatesLoaded(course) {
    const cid = String(course?.id ?? '');
    if (!cid || completedSessionDatesByCourse.value[cid]) return;

    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return;

      const { byClass } = await fetchClassSessionsFn({
        token,
        branchId: branchId.value ?? branchId,
        studentClassId: cid,
        perPage: 2000,
      });
      const rows = Array.isArray(byClass?.[cid]) ? byClass[cid] : [];
      classSessionsByCourse.value = { ...classSessionsByCourse.value, [cid]: rows };

      const dates = [...new Set(rows
        .filter((row) => String(row?.learning_record_status || '') === 'approved')
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean))].sort();
      completedSessionDatesByCourse.value = { ...completedSessionDatesByCourse.value, [cid]: dates };
    } catch (_) {}
  }

  async function loadClassSessionsForCourses(courseRows = [], token = '') {
    const ids = (courseRows || []).map((c) => Number(c?.id || c?.ID || 0)).filter((id) => id > 0);
    const bid = branchId.value ?? branchId;
    if (!bid || ids.length === 0 || !token) {
      classSessionsByCourse.value = {};
      return;
    }
    try {
      const { byClass } = await fetchClassSessionsFn({ token, branchId: bid, studentClassIds: ids, perPage: 2000 });
      classSessionsByCourse.value = byClass || {};
    } catch (_) {
      classSessionsByCourse.value = {};
    }
  }

  async function loadEffectiveSessionDates(courseRows = [], token = '') {
    const rows = Array.isArray(courseRows) ? courseRows : [];
    const bid = branchId.value ?? branchId;
    if (!bid || rows.length === 0 || !token) {
      effectiveSessionDatesByCourse.value = {};
      return;
    }

    const payloadCourses = rows
      .map((c) => ({
        id: Number(c?.id || c?.ID || 0),
        first_class_date: c?.first_class_date || null,
        sessions_purchased: Number(c?.sessions_purchased ?? c?.SessionCount ?? 0) || 0,
        days_of_week: Array.isArray(c?.days_of_week) && c.days_of_week.length
          ? c.days_of_week.map((d) => Number(d)).filter((d) => d >= 1 && d <= 7)
          : ((Number(c?.day_of_week || 0) >= 1 && Number(c?.day_of_week || 0) <= 7) ? [Number(c.day_of_week)] : []),
      }))
      .filter((c) => c.id > 0);

    if (payloadCourses.length === 0) {
      effectiveSessionDatesByCourse.value = {};
      return;
    }

    try {
      const params = new URLSearchParams({ branch_id: String(bid) });
      const res = await fetch(`/api/v1/student-classes/session-dates?${params.toString()}`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ branch_id: Number(bid), courses: payloadCourses }),
      });
      if (!res.ok) return;
      const json = await res.json().catch(() => ({}));
      const mapped = {};
      Object.keys(json || {}).forEach((key) => {
        const list = Array.isArray(json[key]) ? json[key] : [];
        mapped[String(key)] = [...new Set(list.map((d) => String(d || '').slice(0, 10)).filter(Boolean))].sort();
      });
      effectiveSessionDatesByCourse.value = mapped;
    } catch (_) {}
  }

  const toggleDates = (c) => {
    const s = new Set(expandedDates.value);
    if (s.has(c.id)) s.delete(c.id);
    else {
      s.add(c.id);
      ensureCompletedSessionDatesLoaded(c).catch(() => {});
    }
    expandedDates.value = s;
  };

  const sessions = (c) => {
    const cid = String(c?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (Array.isArray(rows) && rows.length > 0) {
      const dates = rows
        .filter((row) => String(row?.status || '').toLowerCase() !== 'cancelled')
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean);
      return [...new Set(dates)].sort();
    }
    const effective = effectiveSessionDatesByCourse.value[cid];
    if (Array.isArray(effective)) {
      return [...new Set(effective.map((d) => String(d || '').slice(0, 10)).filter(Boolean))].sort();
    }
    return [];
  };

  const getCourseSessionRows = (course) => {
    const key = String(course?.id ?? '');
    const rows = classSessionsByCourse.value[key];
    return Array.isArray(rows) ? rows : [];
  };

  const getSessionRowsForDate = (course, dateYmd) => {
    const target = String(dateYmd || '').slice(0, 10);
    if (!target) return [];
    return getCourseSessionRows(course).filter((row) => String(row?.session_date || '').slice(0, 10) === target);
  };

  const formatAttendanceTooltipTime = (value) => {
    if (!value) return '';
    const text = String(value);
    if (text.includes('T')) {
      const d = new Date(text);
      if (!Number.isNaN(d.getTime())) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${day} ${hh}:${mm}`;
      }
    }
    return text.replace('T', ' ').slice(0, 16);
  };

  const getSessionDisplayRow = (course, dateYmd) => {
    const rows = getSessionRowsForDate(course, dateYmd);
    if (!rows.length) return null;
    const priority = ['completed', 'attended', 'late', 'excused', 'absent', 'leave_adjusted', 'leave', 'cancelled', 'scheduled'];
    const sorted = [...rows].sort((a, b) => {
      const aStatus = String(a?.status || '').toLowerCase();
      const bStatus = String(b?.status || '').toLowerCase();
      return priority.indexOf(aStatus) - priority.indexOf(bStatus);
    });
    return sorted[0] || null;
  };

  const resolveRecordedByLabel = (row) => {
    const memo = String(row?.attendance_memo || '').toLowerCase();
    if (memo === 'swipe' || memo === 'swipe-rfid') return 'RFID 刷卡';
    if (memo === 'manual-match') return row?.recorded_by_name || '人工配對';
    return row?.recorded_by_name || row?.teacher_name || '';
  };

  const getCourseCompletedDates = (course) => {
    const key = String(course?.id ?? '');
    const rows = getCourseSessionRows(course);
    if (Array.isArray(rows) && rows.length > 0) {
      const dates = rows
        .filter((row) => {
          const learningRecordStatus = String(row?.learning_record_status || '').toLowerCase();
          const sessionStatus = String(row?.status || '').toLowerCase();
          return learningRecordStatus === 'approved' || ATTENDED_SESSION_STATUSES.has(sessionStatus);
        })
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean);
      return [...new Set(dates)].sort();
    }
    const dates = completedSessionDatesByCourse.value[key];
    return Array.isArray(dates) ? dates : [];
  };

  const isCompletedDate = (course, dateYmd) => getCourseCompletedDates(course).includes(String(dateYmd || ''));

  const getSessionState = (course, dateYmd) => {
    const rows = getSessionRowsForDate(course, dateYmd);
    if (!rows.length) {
      return isCompletedDate(course, dateYmd) ? { label: '已上', className: 'completed' } : null;
    }
    const statuses = new Set(rows.map((row) => String(row?.status || '').toLowerCase()).filter(Boolean));
    if (statuses.has('leave_adjusted')) return { label: '補請假', className: 'leave' };
    if (statuses.has('excused') || statuses.has('leave')) return { label: '請假', className: 'leave' };
    if (statuses.has('cancelled')) return { label: '取消', className: 'cancelled' };
    if (statuses.has('absent')) return { label: '缺席', className: 'absent' };
    if ([...statuses].some((status) => ATTENDED_SESSION_STATUSES.has(status))) return { label: '已上', className: 'completed' };
    if (rows.some((row) => String(row?.learning_record_status || '').toLowerCase() === 'approved')) return { label: '已上', className: 'completed' };
    return null;
  };

  const getSessionNumber = (course, dateYmd) => {
    const allDates = sessions(course);
    let num = 0;
    for (const d of allDates) {
      const state = getSessionState(course, d);
      const isLeave = state && LEAVE_STATUSES.has(state.className);
      if (d === dateYmd) return isLeave ? null : num + 1;
      if (!isLeave) num++;
    }
    return null;
  };

  const countNonLeaveSessions = (course) => {
    const allDates = sessions(course);
    let count = 0;
    for (const d of allDates) {
      const state = getSessionState(course, d);
      if (!state || !LEAVE_STATUSES.has(state.className)) count++;
    }
    return count;
  };

  const getSessionStateLabel = (course, dateYmd) => getSessionState(course, dateYmd)?.label || '';
  const getSessionStateClass = (course, dateYmd) => getSessionState(course, dateYmd)?.className || '';

  const getSessionTooltip = (course, dateYmd) => {
    const row = getSessionDisplayRow(course, dateYmd);
    const stateLabel = getSessionStateLabel(course, dateYmd) || '未上';
    if (!row) return `狀態：${stateLabel}`;
    const lines = [
      `狀態：${stateLabel}`,
      `時段：${String(row?.start_time || '').slice(0, 5)}-${String(row?.end_time || '').slice(0, 5)}`,
    ];
    const attendanceTime = formatAttendanceTooltipTime(row?.attendance_sign_in_at);
    if (attendanceTime) lines.push(`點名時間：${attendanceTime}`);
    const recordedBy = resolveRecordedByLabel(row);
    if (recordedBy) lines.push(`點名人：${recordedBy}`);
    if (!attendanceTime && !recordedBy && row?.teacher_name) lines.push(`授課老師：${row.teacher_name}`);
    return lines.join('\n');
  };

  const displaySessions = (course) => sessions(course);

  const isSessionMode = (course) => {
    const paymentType = String(course?.payment_type || '').trim();
    if (paymentType) return paymentType === 'session';
    return Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) > 0;
  };

  const getPurchasedSessions = (course) => Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);

  const getRawRemainingSessions = (course) => {
    const v = course?.remaining_sessions ?? course?.RemainingSessions;
    return Number.isFinite(Number(v)) ? Number(v) : null;
  };

  const getUsedSessions = (course) => {
    const purchased = getPurchasedSessions(course);
    const remaining = getRawRemainingSessions(course);
    if (remaining != null) return Math.max(0, purchased - remaining);
    const used = course?.sessions_used ?? course?.UsedSessions;
    if (Number.isFinite(Number(used))) return Math.max(0, Number(used));
    return Math.max(0, getCourseCompletedDates(course).length);
  };

  const displayRemainingSessions = (course) => {
    if (!isSessionMode(course)) return null;
    const purchased = getPurchasedSessions(course);
    const apiRem = getRawRemainingSessions(course);
    if (apiRem != null && Number.isFinite(Number(apiRem))) return Math.max(0, Number(apiRem));
    const fromRows = getCourseCompletedDates(course).length;
    return Math.max(0, purchased - Math.min(purchased, fromRows));
  };

  function updateLocalSessionRow(courseId, sessionData) {
    const key = String(courseId || '');
    if (!key) return;
    const rows = classSessionsByCourse.value[key];
    if (!Array.isArray(rows)) return;
    const idx = rows.findIndex((r) => r.id === sessionData.id);
    if (idx >= 0) {
      const leaveStatuses = new Set(['leave', 'leave_adjusted', 'cancelled']);
      const updated = { ...rows[idx], status: sessionData.status, start_time: sessionData.start_time, end_time: sessionData.end_time };
      if (leaveStatuses.has(sessionData.status)) {
        updated.learning_record_status = null;
        updated.attendance_sign_in_at = null;
      }
      rows[idx] = updated;
      classSessionsByCourse.value = { ...classSessionsByCourse.value, [key]: [...rows] };
    }
  }

  return {
    expandedDates,
    toggleDates,
    sessions,
    getSessionNumber,
    countNonLeaveSessions,
    getCourseSessionRows,
    getSessionRowsForDate,
    getSessionDisplayRow,
    getSessionState,
    getSessionStateLabel,
    getSessionStateClass,
    getSessionTooltip,
    getCourseCompletedDates,
    isCompletedDate,
    displaySessions,
    isSessionMode,
    getPurchasedSessions,
    getRawRemainingSessions,
    getUsedSessions,
    displayRemainingSessions,
    formatAttendanceTooltipTime,
    updateLocalSessionRow,
    ensureCompletedSessionDatesLoaded,
    loadClassSessionsForCourses,
    loadEffectiveSessionDates,
    LEAVE_STATUSES,
    ATTENDED_SESSION_STATUSES,
  };
}
