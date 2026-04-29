import { ref, computed } from 'vue';

const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);
const ATTENDED_SESSION_STATUSES = new Set(['completed', 'attended', 'late']);
/** 剩餘堂數顯示：與「已上」一併視為已占用的狀態（缺席通常已扣堂）。 */
const SESSION_DISPLAY_CONSUMED = new Set(['completed', 'absent']);

/**
 * 狀態矩陣：哪些 ClassSession.Status 占購買堂數額度。
 * 與後端 StudentClassController::extendSessionsIfNeeded 口徑一致
 * （後端 whereNotIn: cancelled, leave, leave_adjusted）。
 *
 *  狀態            | 占購買額度 | 說明
 *  --------------- | ---------- | -------
 *  scheduled       | YES        | 未來預排堂次
 *  attended        | YES        | 已上（核准評量後）
 *  completed       | YES        | 已上（舊路徑）
 *  late            | YES        | 遲到但算已上
 *  absent          | YES        | 缺席（通常已扣堂）
 *  leave           | NO         | 請假，不占購買額度
 *  leave_adjusted  | NO         | 補請假，不占購買額度
 *  excused         | NO         | 歷史相容，語意同 leave
 *  cancelled       | NO         | 已取消
 */
const SESSION_NOT_OCCUPYING_QUOTA = new Set(['cancelled', 'leave', 'leave_adjusted', 'excused']);

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
        .filter((row) => ATTENDED_SESSION_STATUSES.has(String(row?.status || '').toLowerCase()))
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean))].sort();
      completedSessionDatesByCourse.value = { ...completedSessionDatesByCourse.value, [cid]: dates };
    } catch (_) {}
  }

  async function loadClassSessionsForCourses(courseRows = [], token = '') {
    const rows = Array.isArray(courseRows) ? courseRows : [];
    const ids = rows.map((c) => Number(c?.id || c?.ID || 0)).filter((id) => id > 0);
    const bid = branchId.value ?? branchId;
    if (!bid || ids.length === 0 || !token) {
      classSessionsByCourse.value = {};
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
      const params = new URLSearchParams({ branch_id: String(bid) });
      const res = await fetch(`/api/v1/student-classes/session-dates?${params.toString()}`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ branch_id: Number(bid), range_start: start, range_end: end, courses: payloadCourses }),
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

  /** Unique session key: prefer class_session id; fallback to date+start_time. */
  const sessionRowKey = (row) => {
    const id = Number(row?.id || 0);
    if (id > 0) return `id:${id}`;
    const date = String(row?.session_date || '').slice(0, 10);
    const start = String(row?.start_time || '').slice(0, 5);
    return `${date}|${start}`;
  };

  const sortSessionRows = (rows) =>
    [...rows].sort((a, b) => {
      const da = String(a?.session_date || '');
      const db = String(b?.session_date || '');
      if (da !== db) return da.localeCompare(db);
      return String(a?.start_time || '').localeCompare(String(b?.start_time || ''));
    });

  const courseSlotForDate = (course, dateYmd) => {
    const date = String(dateYmd || '').slice(0, 10);
    if (!date) return null;
    const dow = new Date(`${date}T12:00:00`).getDay();
    const isoDow = dow === 0 ? 7 : dow;
    const slots = Array.isArray(course?.day_time_slots) ? course.day_time_slots : [];
    return slots.find((slot) => Number(slot?.day || 0) === isoDow) || null;
  };

  const addHoursToTime = (time, hours) => {
    const base = String(time || '').slice(0, 5);
    if (!/^\d{2}:\d{2}$/.test(base)) return '';
    const [hh, mm] = base.split(':').map((v) => Number(v));
    const total = hh * 60 + mm + Math.round((Number(hours) || 0) * 60);
    const endH = Math.floor(total / 60) % 24;
    const endM = total % 60;
    return `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
  };

  const syntheticEffectiveUnits = (course, existingRows = []) => {
    const cid = String(course?.id ?? '');
    const effective = effectiveSessionDatesByCourse.value[cid];
    if (!Array.isArray(effective)) return [];
    const existingDates = new Set(
      (existingRows || [])
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean)
    );
    return [...new Set(effective.map((d) => String(d || '').slice(0, 10)).filter(Boolean))]
      .filter((d) => !existingDates.has(d))
      .map((d) => {
        const slot = courseSlotForDate(course, d);
        const start = String(slot?.start_time || course?.start_time || '').slice(0, 5);
        const duration = Number(slot?.duration_hours ?? course?.duration_hours ?? 0) || 0;
        return {
          session_date: d,
          start_time: start,
          end_time: start && duration ? addHoursToTime(start, duration) : '',
          status: 'scheduled',
          _synthetic: true,
        };
      });
  };

  /**
   * Return ordered, non-cancelled session "units". Each unit is either a real
   * ClassSession row or a synthetic { session_date } from legacy date lists.
   * Same date with different start_time yields multiple entries.
   */
  const sessionUnits = (c) => {
    const cid = String(c?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (Array.isArray(rows) && rows.length > 0) {
      const activeRows = rows.filter((row) => String(row?.status || '').toLowerCase() !== 'cancelled');
      return sortSessionRows([...activeRows, ...syntheticEffectiveUnits(c, activeRows)]);
    }
    const effective = effectiveSessionDatesByCourse.value[cid];
    if (Array.isArray(effective)) {
      return sortSessionRows(syntheticEffectiveUnits(c));
    }
    return [];
  };

  /** All session rows including cancelled, sorted by date. */
  const allSessionUnits = (c) => {
    const cid = String(c?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (Array.isArray(rows) && rows.length > 0) {
      return sortSessionRows([...rows, ...syntheticEffectiveUnits(c, rows)]);
    }
    return sessionUnits(c);
  };

  const cancelledSessionCount = (c) => {
    const cid = String(c?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (!Array.isArray(rows)) return 0;
    return rows.filter((row) => String(row?.status || '').toLowerCase() === 'cancelled').length;
  };

  /** Legacy compat: unique sorted date strings (for callers that iterate dates). */
  const sessions = (c) => {
    const units = sessionUnits(c);
    return [...new Set(units.map((u) => String(u?.session_date || '').slice(0, 10)).filter(Boolean))].sort();
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

  const getSessionRowById = (course, sessionId) => {
    const id = Number(sessionId);
    if (!id) return null;
    return getCourseSessionRows(course).find((r) => Number(r?.id) === id) || null;
  };

  const isContractException = (row) => !!(row?.is_contract_exception ?? row?.IsContractException ?? false);

  const rowOccupiesPurchasedQuota = (row) => {
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
      const sameId = Number(unit?.id || 0) > 0 && Number(unit.id) === Number(row.id || 0);
      const sameSynthetic = !unit?.id
        && String(unit?.session_date || '').slice(0, 10) === String(row?.session_date || '').slice(0, 10)
        && String(unit?.start_time || '').slice(0, 5) === String(row?.start_time || '').slice(0, 5);
      if (sameId || sameSynthetic) return quotaIndex > purchased;
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

  /** Resolve a display row. Accepts (course, dateYmd) for legacy, or (course, dateYmd, sessionId) for exact match. */
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
    const memo = String(row?.attendance_memo || '').toLowerCase();
    if (memo === 'swipe' || memo === 'swipe-rfid') return 'RFID 刷卡';
    if (memo === 'manual-match') return row?.recorded_by_name || '人工配對';
    return row?.recorded_by_name || row?.teacher_name || '';
  };

  /** Number of completed/attended session rows (not unique dates). */
  const getCompletedSessionCount = (course) => {
    const rows = getCourseSessionRows(course);
    if (Array.isArray(rows) && rows.length > 0) {
      return rows.filter((row) => {
        const sessionStatus = String(row?.status || '').toLowerCase();
        return ATTENDED_SESSION_STATUSES.has(sessionStatus);
      }).length;
    }
    const key = String(course?.id ?? '');
    const dates = completedSessionDatesByCourse.value[key];
    return Array.isArray(dates) ? dates.length : 0;
  };

  const getCourseCompletedDates = (course) => {
    const key = String(course?.id ?? '');
    const rows = getCourseSessionRows(course);
    if (Array.isArray(rows) && rows.length > 0) {
      const dates = rows
        .filter((row) => {
          const sessionStatus = String(row?.status || '').toLowerCase();
          return ATTENDED_SESSION_STATUSES.has(sessionStatus);
        })
        .map((row) => String(row?.session_date || '').slice(0, 10))
        .filter(Boolean);
      return [...new Set(dates)].sort();
    }
    const dates = completedSessionDatesByCourse.value[key];
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
      const uDate = String(u?.session_date || '').slice(0, 10);
      const uId = Number(u?.id || 0);
      const row = uId > 0 ? getSessionRowById(course, uId) || u : u;
      const state = getSessionState(course, uDate, uId || undefined);
      const isLeave = state && LEAVE_STATUSES.has(state.className);
      const isNonQuota = isLeave || isOverQuotaSession(course, row);
      const isMatch = sessionId
        ? (uId > 0 && uId === Number(sessionId))
        : (uDate === dateYmd);
      if (isMatch) return isNonQuota ? null : num + 1;
      if (!isNonQuota) num++;
    }
    return null;
  };

  const countNonLeaveSessions = (course) => {
    const units = sessionUnits(course);
    let count = 0;
    for (const u of units) {
      const uDate = String(u?.session_date || '').slice(0, 10);
      const uId = Number(u?.id || 0);
      const row = uId > 0 ? getSessionRowById(course, uId) || u : u;
      const state = getSessionState(course, uDate, uId || undefined);
      if (!state || (!LEAVE_STATUSES.has(state.className) && !isOverQuotaSession(course, row))) count++;
    }
    return count;
  };

  /**
   * 有效堂次數：占購買額度的堂次。
   * 口徑與後端 extendSessionsIfNeeded 一致：排除 cancelled/leave/leave_adjusted/excused。
   * 用於警示判定，與 displayRemainingSessions 解耦。
   */
  const effectiveSessionCount = (course) => {
    const cid = String(course?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (Array.isArray(rows) && rows.length > 0) {
      return rows.filter((row) => rowOccupiesPurchasedQuota(row)).length;
    }
    return countNonLeaveSessions(course);
  };

  const leaveSessionCount = (course) => {
    const cid = String(course?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (!Array.isArray(rows)) return 0;
    return rows.filter((row) => LEAVE_STATUSES.has(String(row?.status || '').toLowerCase())).length;
  };

  /**
   * 堂次警示判定。回傳 { show, type, message } 或 null。
   * type: 'over' | 'under_leave' | 'under_other'
   */
  const sessionCountWarning = (course) => {
    if (!isSessionMode(course)) return null;
    // 方案課程：以方案池總購買數作為比較基準，避免 SessionCount 脫鉤誤報。
    // 排課延伸口徑是以 SessionCount 為上限，但 SessionCount 舊資料可能停留在建立時的值，
    // 造成「排程列數與購買堂數不一致」誤報。統一改用 package_total_sessions。
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
    const units = sessionUnits(course);
    let count = 0;
    for (const u of units) {
      const uDate = String(u?.session_date || '').slice(0, 10);
      const uId = Number(u?.id || 0);
      const row = uId > 0 ? getSessionRowById(course, uId) || u : u;
      const state = getSessionState(course, uDate, uId || undefined);
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
      `時段：${String(row?.start_time || '').slice(0, 5)}-${String(row?.end_time || '').slice(0, 5)}`,
    ];
    if (isContractException(row) && stateLabel !== '例外堂') lines.push('類型：例外堂');
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
    return Math.max(0, getCompletedSessionCount(course));
  };

  const displayRemainingSessions = (course) => {
    if (!isSessionMode(course)) return null;
    // 方案課程：剩餘數以「方案池剩餘」為準，非 per-course 拆分值。
    // 同方案下多科課程應顯示相同的池剩餘數字。
    if (course?.PackageID) {
      return Math.max(0, Number(course?.package_remaining_sessions ?? 0) || 0);
    }
    const purchased = getPurchasedSessions(course);
    const cid = String(course?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    const apiRem = getRawRemainingSessions(course);
    if (apiRem != null && Number.isFinite(Number(apiRem))) return Math.max(0, Number(apiRem));
    // 後端尚未提供 RemainingSessions 時，才退回用堂次列表估算。
    if (Array.isArray(rows) && rows.length > 0) {
      const fromList = Math.max(0, countUpcomingNonLeaveSessions(course));
      return Math.min(purchased, fromList);
    }
    const fromRows = getCompletedSessionCount(course);
    return Math.max(0, purchased - Math.min(purchased, fromRows));
  };

  function updateLocalSessionRow(courseId, sessionData) {
    const key = String(courseId || '');
    if (!key || !sessionData || sessionData.id == null) return;
    const rows = Array.isArray(classSessionsByCourse.value[key])
      ? [...classSessionsByCourse.value[key]]
      : [];
    const leaveStatuses = new Set(['leave', 'leave_adjusted', 'cancelled']);
    // PRD f0cce4d5 P2：只覆寫 payload 中實際提供的欄位，避免把代課 patch（只帶 teacher_id/teacher_name）
    // 意外覆寫成 undefined 的 status / start_time / end_time。
    const overlayKeys = [
      'status', 'session_date', 'start_time', 'end_time',
      'teacher_id', 'teacher_name',
      'learning_record_status', 'attendance_sign_in_at',
    ];
    let changed = false;
    for (let i = 0; i < rows.length; i++) {
      if (rows[i].id !== sessionData.id) continue;
      const updated = { ...rows[i] };
      for (const k of overlayKeys) {
        if (Object.prototype.hasOwnProperty.call(sessionData, k) && sessionData[k] !== undefined) {
          updated[k] = sessionData[k];
        }
      }
      if (sessionData.status !== undefined && leaveStatuses.has(sessionData.status)) {
        updated.learning_record_status = null;
        updated.attendance_sign_in_at = null;
      }
      rows[i] = updated;
      changed = true;
    }
    if (!changed) {
      rows.push({ ...sessionData });
      changed = true;
    }
    if (changed) {
      classSessionsByCourse.value = { ...classSessionsByCourse.value, [key]: [...rows] };
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
    ensureCompletedSessionDatesLoaded,
    loadClassSessionsForCourses,
    loadEffectiveSessionDates,
    LEAVE_STATUSES,
    ATTENDED_SESSION_STATUSES,
    SESSION_NOT_OCCUPYING_QUOTA,
  };
}
