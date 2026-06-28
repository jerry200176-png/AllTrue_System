import { ref } from 'vue';
import {
  fetchSessionDates as defaultFetchSessionDates,
  materializedSessionsOnly,
  mergeSessionsByCourse,
  mergeSessionViewModels,
  patchSessionViewModel,
  sessionDatesFromViewModels,
  sessionViewModelFromClassSessionsRow,
  sessionViewModelFromEnsureProjected,
  sessionViewModelKey,
  sessionViewModelPatchFromApi,
  sortSessionViewModels,
} from '../../lib/classSessionsApi';

const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);
const ATTENDED_SESSION_STATUSES = new Set(['completed', 'attended', 'late']);
const SESSION_DISPLAY_CONSUMED = new Set(['completed', 'absent']);
const SESSION_NOT_OCCUPYING_QUOTA = new Set(['cancelled', 'leave', 'leave_adjusted', 'excused']);

export function useCourseSessionsDisplay({
  sessionsByCourse,
  completedSessionDatesByCourse,
  fetchClassSessionsFn,
  fetchSessionDatesFn = defaultFetchSessionDates,
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
      sessionsByCourse.value = mergeSessionsByCourse(sessionsByCourse.value, { [cid]: rows });

      const dates = [...new Set(
        materializedSessionsOnly(rows)
          .filter((row) => ATTENDED_SESSION_STATUSES.has(String(row?.status || '').toLowerCase()))
          .map((row) => row.date)
          .filter(Boolean)
      )].sort();
      completedSessionDatesByCourse.value = { ...completedSessionDatesByCourse.value, [cid]: dates };
    } catch (_) {}
  }

  // Reload a single course's ClassSessions from the server and MERGE into the
  // local cache (preserving every other course). Unlike loadClassSessionsForCourses,
  // which replaces the whole cache, this is safe to call mid-interaction. Used when
  // a chip references a real session that is missing from the cache (duplicate /
  // overlapping sessions, or a stale load) so the session-edit modal can resolve it
  // instead of dead-ending on "資料尚未載入". See #942 (in-app #177).
  async function reloadCourseSessions(course) {
    const cid = String(course?.id ?? course?.ID ?? '');
    if (!cid) return false;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return false;
      const { byClass } = await fetchClassSessionsFn({
        token,
        branchId: branchId.value ?? branchId,
        studentClassId: cid,
        perPage: 2000,
      });
      const rows = Array.isArray(byClass?.[cid]) ? byClass[cid] : [];
      sessionsByCourse.value = mergeSessionsByCourse(sessionsByCourse.value, { [cid]: rows });
      return true;
    } catch (_) {
      return false;
    }
  }

  async function loadClassSessionsForCourses(courseRows = [], token = '') {
    const rows = Array.isArray(courseRows) ? courseRows : [];
    const ids = rows.map((c) => Number(c?.id || c?.ID || 0)).filter((id) => id > 0);
    const bid = branchId.value ?? branchId;
    if (!bid || ids.length === 0 || !token) {
      sessionsByCourse.value = {};
      return;
    }
    try {
      const now = new Date();
      const rangeStart = new Date(now.getFullYear(), now.getMonth() - 2, 1);
      const rangeEnd = new Date(now.getFullYear(), now.getMonth() + 2, 0);
      rows.forEach((course) => {
        const endRaw = course?.end_date || course?.EndDate || '';
        const endDate = endRaw ? new Date(`${String(endRaw).slice(0, 10)}T12:00:00`) : null;
        if (endDate && !Number.isNaN(endDate.getTime()) && endDate > rangeEnd) {
          rangeEnd.setTime(endDate.getTime());
        }
      });
      const start = `${rangeStart.getFullYear()}-${String(rangeStart.getMonth() + 1).padStart(2, '0')}-01`;
      const end = `${rangeEnd.getFullYear()}-${String(rangeEnd.getMonth() + 1).padStart(2, '0')}-${String(rangeEnd.getDate()).padStart(2, '0')}`;
      const { byClass } = await fetchClassSessionsFn({ token, branchId: bid, studentClassIds: ids, start, end, perPage: 2000 });
      sessionsByCourse.value = byClass || {};
      return true;
    } catch (_) {
      sessionsByCourse.value = {};
      return false;
    }
  }

  async function loadEffectiveSessionDates(courseRows = [], token = '') {
    const rows = Array.isArray(courseRows) ? courseRows : [];
    const bid = branchId.value ?? branchId;
    if (!bid || rows.length === 0 || !token) return;

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

    if (payloadCourses.length === 0) return;

    try {
      const now = new Date();
      const rangeStart = new Date(now.getFullYear(), now.getMonth() - 2, 1);
      const rangeEnd = new Date(now.getFullYear(), now.getMonth() + 2, 0);
      rows.forEach((course) => {
        const endRaw = course?.end_date || course?.EndDate || '';
        const endDate = endRaw ? new Date(`${String(endRaw).slice(0, 10)}T12:00:00`) : null;
        if (endDate && !Number.isNaN(endDate.getTime()) && endDate > rangeEnd) {
          rangeEnd.setTime(endDate.getTime());
        }
      });
      const start = `${rangeStart.getFullYear()}-${String(rangeStart.getMonth() + 1).padStart(2, '0')}-01`;
      const end = `${rangeEnd.getFullYear()}-${String(rangeEnd.getMonth() + 1).padStart(2, '0')}-${String(rangeEnd.getDate()).padStart(2, '0')}`;

      const { byClass } = await fetchSessionDatesFn({
        token,
        branchId: bid,
        rangeStart: start,
        rangeEnd: end,
        courses: payloadCourses,
      });
      sessionsByCourse.value = mergeSessionsByCourse(sessionsByCourse.value, byClass || {});
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

  const sessionRowKey = sessionViewModelKey;

  const getCourseSessions = (course) => {
    const key = String(course?.id ?? '');
    const rows = sessionsByCourse.value[key];
    return Array.isArray(rows) ? rows : [];
  };

  const getCourseSessionRows = (course) => materializedSessionsOnly(getCourseSessions(course));

  const sessionUnits = (course) => {
    const rows = getCourseSessions(course);
    return sortSessionViewModels(rows.filter((row) => String(row?.status || '').toLowerCase() !== 'cancelled'));
  };

  const allSessionUnits = (course) => sortSessionViewModels(getCourseSessions(course));

  const cancelledSessionCount = (course) =>
    getCourseSessionRows(course).filter((row) => String(row?.status || '').toLowerCase() === 'cancelled').length;

  const sessions = (course) => sessionDatesFromViewModels(sessionUnits(course));

  const getSessionRowsForDate = (course, dateYmd) => {
    const target = String(dateYmd || '').slice(0, 10);
    if (!target) return [];
    return getCourseSessionRows(course).filter((row) => row.date === target);
  };

  const getSessionRowById = (course, sessionId) => {
    const id = Number(sessionId);
    if (!id) return null;
    return getCourseSessionRows(course).find((r) => Number(r?.id) === id) || null;
  };

  const isContractException = (row) => !!row?.isContractException;

  const rowOccupiesPurchasedQuota = (row) => {
    if (row?.isProjected) return false;
    const status = String(row?.status || '').toLowerCase();
    return !SESSION_NOT_OCCUPYING_QUOTA.has(status);
  };

  const isOverQuotaSession = (course, row) => {
    if (!row || course?.PackageID) return false;
    const purchased = getPurchasedSessions(course);
    if (purchased <= 0 || !rowOccupiesPurchasedQuota(row)) return false;

    let quotaIndex = 0;
    for (const unit of sessionUnits(course)) {
      if (!rowOccupiesPurchasedQuota(unit)) continue;
      quotaIndex += 1;
      const sameId = unit.id && unit.id === row.id;
      const sameProjected = unit.isProjected
        && unit.date === row.date
        && unit.startTime === row.startTime;
      if (sameId || sameProjected) return quotaIndex > purchased;
    }
    return false;
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

  const getSessionDisplayRow = (course, dateYmd, sessionId) => {
    if (sessionId) {
      const exact = getSessionRowById(course, sessionId);
      if (exact) return exact;
    }
    const rows = getSessionRowsForDate(course, dateYmd);
    if (!rows.length) return null;
    if (rows.length === 1) return rows[0];
    const priority = ['completed', 'attended', 'late', 'absent', 'scheduled', 'excused', 'leave_adjusted', 'leave', 'cancelled'];
    const sorted = [...rows].sort((a, b) => {
      const aStatus = String(a?.status || '').toLowerCase();
      const bStatus = String(b?.status || '').toLowerCase();
      return priority.indexOf(aStatus) - priority.indexOf(bStatus);
    });
    return sorted[0] || null;
  };

  const resolveRecordedByLabel = (row) => {
    const memo = String(row?.attendanceMemo || '').toLowerCase();
    if (memo === 'swipe' || memo === 'swipe-rfid') return 'RFID 刷卡';
    if (memo === 'manual-match') return row?.recordedByName || '人工配對';
    return row?.recordedByName || row?.teacherName || '';
  };

  const getCompletedSessionCount = (course) => {
    const rows = getCourseSessionRows(course);
    if (rows.length > 0) {
      return rows.filter((row) => ATTENDED_SESSION_STATUSES.has(String(row?.status || '').toLowerCase())).length;
    }
    const key = String(course?.id ?? '');
    const dates = completedSessionDatesByCourse.value[key];
    return Array.isArray(dates) ? dates.length : 0;
  };

  const getCourseCompletedDates = (course) => {
    const rows = getCourseSessionRows(course);
    if (rows.length > 0) {
      const dates = rows
        .filter((row) => ATTENDED_SESSION_STATUSES.has(String(row?.status || '').toLowerCase()))
        .map((row) => row.date)
        .filter(Boolean);
      return [...new Set(dates)].sort();
    }
    const dates = completedSessionDatesByCourse.value[String(course?.id ?? '')];
    return Array.isArray(dates) ? dates : [];
  };

  const isCompletedDate = (course, dateYmd) => getCourseCompletedDates(course).includes(String(dateYmd || ''));

  const getSessionState = (course, dateYmd, sessionId) => {
    let rows;
    if (sessionId) {
      const exact = getSessionRowById(course, sessionId);
      rows = exact ? [exact] : getSessionRowsForDate(course, dateYmd);
    } else {
      rows = getSessionRowsForDate(course, dateYmd);
    }
    if (!rows.length) {
      return isCompletedDate(course, dateYmd) ? { label: '已上', className: 'completed' } : null;
    }
    const statuses = new Set(rows.map((row) => String(row?.status || '').toLowerCase()).filter(Boolean));
    if ([...statuses].some((status) => ATTENDED_SESSION_STATUSES.has(status))) return { label: '已上', className: 'completed' };
    if (statuses.has('absent')) return { label: '缺席', className: 'absent' };
    if (statuses.has('leave_adjusted')) return { label: '補請假', className: 'leave' };
    if (statuses.has('excused') || statuses.has('leave')) return { label: '請假', className: 'leave' };
    if (statuses.has('cancelled')) return { label: '取消', className: 'cancelled' };
    if (rows.some((row) => isContractException(row))) return { label: '例外堂', className: 'exception' };
    if (rows.some((row) => isOverQuotaSession(course, row))) return { label: '超排', className: 'over-quota' };
    if (statuses.has('scheduled')) return null;
    return null;
  };

  const getSessionNumber = (course, dateYmd, sessionId) => {
    const units = sessionUnits(course);
    let num = 0;
    for (const u of units) {
      const row = u.id ? getSessionRowById(course, u.id) || u : u;
      const state = getSessionState(course, u.date, u.id || undefined);
      const isLeave = state && LEAVE_STATUSES.has(state.className);
      const isNonQuota = isLeave || isOverQuotaSession(course, row);
      const isMatch = sessionId ? (u.id && u.id === Number(sessionId)) : (u.date === dateYmd);
      if (isMatch) return isNonQuota ? null : num + 1;
      if (!isNonQuota) num++;
    }
    return null;
  };

  const countNonLeaveSessions = (course) => {
    let count = 0;
    for (const u of sessionUnits(course)) {
      const row = u.id ? getSessionRowById(course, u.id) || u : u;
      const state = getSessionState(course, u.date, u.id || undefined);
      if (!state || (!LEAVE_STATUSES.has(state.className) && !isOverQuotaSession(course, row))) count++;
    }
    return count;
  };

  const effectiveSessionCount = (course) => {
    const rows = getCourseSessionRows(course);
    if (rows.length > 0) return rows.filter((row) => rowOccupiesPurchasedQuota(row)).length;
    return countNonLeaveSessions(course);
  };

  const leaveSessionCount = (course) =>
    getCourseSessionRows(course).filter((row) => LEAVE_STATUSES.has(String(row?.status || '').toLowerCase())).length;

  const sessionCountWarning = (course) => {
    if (!isSessionMode(course)) return null;
    const purchased = course?.PackageID
      ? Math.max(0, Number(course?.package_total_sessions ?? 0) || 0)
      : getPurchasedSessions(course);
    if (purchased <= 0) return null;
    const effective = effectiveSessionCount(course);
    if (effective === purchased) return null;
    const leaves = leaveSessionCount(course);
    if (effective > purchased) {
      return { show: true, type: 'over', message: '已超出購買堂數，請取消、改為例外堂，或建立新批次' };
    }
    if (leaves > 0) {
      return { show: true, type: 'under_leave', message: '有請假堂次尚未補課' };
    }
    return { show: true, type: 'under_other', message: '排程列數與購買堂數不一致' };
  };

  const countUpcomingNonLeaveSessions = (course) => {
    let count = 0;
    for (const u of sessionUnits(course)) {
      const row = u.id ? getSessionRowById(course, u.id) || u : u;
      const state = getSessionState(course, u.date, u.id || undefined);
      if (state && LEAVE_STATUSES.has(state.className)) continue;
      if (isContractException(row) || isOverQuotaSession(course, row)) continue;
      if (state && SESSION_DISPLAY_CONSUMED.has(state.className)) continue;
      count++;
    }
    return count;
  };

  const getSessionStateLabel = (course, dateYmd, sessionId) => getSessionState(course, dateYmd, sessionId)?.label || '';
  const getSessionStateClass = (course, dateYmd, sessionId) => getSessionState(course, dateYmd, sessionId)?.className || '';

  const getSessionTooltip = (course, dateYmd, sessionId) => {
    const row = getSessionDisplayRow(course, dateYmd, sessionId);
    const stateLabel = getSessionStateLabel(course, dateYmd, sessionId) || '未上';
    if (!row) return `狀態：${stateLabel}`;
    const lines = [
      `狀態：${stateLabel}`,
      `時段：${row.startTime}-${row.endTime}`,
    ];
    if (isContractException(row) && stateLabel !== '例外堂') lines.push('類型：例外堂');
    const attendanceTime = formatAttendanceTooltipTime(row?.attendanceSignInAt);
    if (attendanceTime) lines.push(`點名時間：${attendanceTime}`);
    const recordedBy = resolveRecordedByLabel(row);
    if (recordedBy) lines.push(`點名人：${recordedBy}`);
    if (!attendanceTime && !recordedBy && row?.teacherName) lines.push(`授課老師：${row.teacherName}`);
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
    return Math.max(0, getCompletedSessionCount(course));
  };

  const displayRemainingSessions = (course) => {
    if (!isSessionMode(course)) return null;
    if (course?.PackageID) {
      return Math.max(0, Number(course?.package_remaining_sessions ?? 0) || 0);
    }
    const purchased = getPurchasedSessions(course);
    const rows = getCourseSessionRows(course);
    const apiRem = getRawRemainingSessions(course);
    if (apiRem != null && Number.isFinite(Number(apiRem))) return Math.max(0, Number(apiRem));
    if (rows.length > 0) {
      const fromList = Math.max(0, countUpcomingNonLeaveSessions(course));
      return Math.min(purchased, fromList);
    }
    const fromRows = getCompletedSessionCount(course);
    return Math.max(0, purchased - Math.min(purchased, fromRows));
  };

  function replaceSessionsForCourse(courseId, rows = []) {
    const key = String(courseId || '');
    if (!key) return;
    sessionsByCourse.value = {
      ...sessionsByCourse.value,
      [key]: sortSessionViewModels(Array.isArray(rows) ? rows : []),
    };
  }

  function updateLocalSessionRow(courseId, sessionData) {
    const key = String(courseId || '');
    if (!key || !sessionData) return;

    const patchId = Number(sessionData.id || 0);
    if (!patchId) return;

    const vm = sessionData.date
      ? sessionData
      : (sessionViewModelFromEnsureProjected(sessionData)
        || sessionViewModelFromClassSessionsRow(sessionData));

    const rows = Array.isArray(sessionsByCourse.value[key]) ? [...sessionsByCourse.value[key]] : [];
    const leaveStatuses = new Set(['leave', 'leave_adjusted', 'cancelled']);
    const overlayKeys = [
      'status', 'date', 'startTime', 'endTime',
      'teacherId', 'teacherName',
      'learningRecordStatus', 'attendanceSignInAt',
    ];

    const overlay = vm || sessionViewModelPatchFromApi(sessionData);
    if (!overlay) return;

    let changed = false;
    for (let i = 0; i < rows.length; i++) {
      if (rows[i].id !== patchId) continue;
      const updated = { ...rows[i] };
      for (const k of overlayKeys) {
        if (Object.prototype.hasOwnProperty.call(overlay, k) && overlay[k] !== undefined) {
          updated[k] = overlay[k];
        }
      }
      if (overlay.status !== undefined && leaveStatuses.has(overlay.status)) {
        updated.learningRecordStatus = null;
        updated.attendanceSignInAt = null;
      }
      rows[i] = patchSessionViewModel(updated, { isProjected: false, kind: 'materialized' });
      changed = true;
    }

    if (!changed && vm?.date) {
      rows.push(patchSessionViewModel(vm, { isProjected: false, kind: 'materialized' }));
      changed = true;
    }

    if (changed) {
      sessionsByCourse.value = {
        ...sessionsByCourse.value,
        [key]: mergeSessionViewModels(rows, []),
      };
    }
  }

  return {
    expandedDates,
    toggleDates,
    sessions,
    sessionUnits,
    allSessionUnits,
    cancelledSessionCount,
    sessionRowKey,
    getSessionNumber,
    countNonLeaveSessions,
    effectiveSessionCount,
    leaveSessionCount,
    sessionCountWarning,
    getCourseSessionRows,
    getSessionRowsForDate,
    getSessionRowById,
    getSessionDisplayRow,
    getSessionState,
    getSessionStateLabel,
    getSessionStateClass,
    getSessionTooltip,
    getCourseCompletedDates,
    getCompletedSessionCount,
    isCompletedDate,
    displaySessions,
    isSessionMode,
    getPurchasedSessions,
    getRawRemainingSessions,
    getUsedSessions,
    displayRemainingSessions,
    formatAttendanceTooltipTime,
    updateLocalSessionRow,
    replaceSessionsForCourse,
    ensureCompletedSessionDatesLoaded,
    reloadCourseSessions,
    loadClassSessionsForCourses,
    loadEffectiveSessionDates,
    LEAVE_STATUSES,
    ATTENDED_SESSION_STATUSES,
    SESSION_NOT_OCCUPYING_QUOTA,
  };
}
