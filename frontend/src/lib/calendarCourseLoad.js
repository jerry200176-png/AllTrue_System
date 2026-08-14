/**
 * TD-062 P4-a (#740)：行事曆 loadCourses 前半段 — student-classes 與 schedules 平行抓取。
 * class-sessions 仍依賴 course ids，維持第二棒串行。
 */

/** Laravel student-classes → SmartCalendar course 形狀（純映射，無副作用）。 */
export function mapCalendarCourse(c) {
  return {
    id: c.id,
    student_id: c.student_id,
    teacher_id: c.teacher_id,
    subject: c.subject,
    class_type: c.class_type,
    rate_per_30min: c.rate_per_30min,
    rate_unit: c.rate_unit || 'session',
    duration_hours: c.duration_hours ?? 2,
    day_of_week: parseInt(c.day_of_week, 10) || 0,
    days_of_week: Array.isArray(c.days_of_week) && c.days_of_week.length ? c.days_of_week : null,
    start_time: c.start_time || '',
    end_time: c.end_time || '',
    day_time_slots: Array.isArray(c.day_time_slots) && c.day_time_slots.length ? c.day_time_slots : null,
    student_name: c.student_name || '—',
    teacher_name: c.teacher_name || '未指派',
    teacher_status: String(c.teacher_status || '').toLowerCase(),
    weeks: Array.isArray(c.weeks) && c.weeks.length ? c.weeks : [1, 2, 3, 4, 5],
    first_class_date: c.first_class_date || c.StartDate || null,
    end_date: c.end_date || (c.EndDate ? String(c.EndDate).slice(0, 10) : null),
    status: c.status || '',
    stop: c.stop ?? c.Stop ?? 0,
    payment_type: c.payment_type || (c.ScheduleMode === 'count' ? 'session' : 'monthly'),
    sessions_purchased: c.sessions_purchased ?? c.SessionCount ?? 0,
    scheduling_policy: c.scheduling_policy || 'auto_recurrence',
    room_id: c.RoomID || c.room_id || '',
  };
}

/**
 * R98 (in-app #219): single source of truth for "which role-scope params does this
 * request get." Two independent copies of this same if/if pair (one per endpoint
 * builder below) is exactly how a hand-edit once flipped one of them without the
 * other — teacher-role requests must scope by their own teacher_id, everyone else
 * (director/admin) scopes by branch_id only. Never re-inline this logic per builder.
 */
export function resolveCalendarRoleScopeParams({ isTeacher, userId, branchId }) {
  return {
    teacherId: isTeacher && userId ? String(userId) : null,
    branchId: !isTeacher && branchId ? String(branchId) : null,
  };
}

export function buildStudentClassesApiUrl({
  baseUrl,
  branchId,
  isTeacher,
  userId,
  schedStart,
  schedEnd,
}) {
  const scope = resolveCalendarRoleScopeParams({ isTeacher, userId, branchId });
  const scParams = new URLSearchParams();
  if (scope.branchId) scParams.set('branch_id', scope.branchId);
  if (scope.teacherId) scParams.set('teacher_id', scope.teacherId);
  if (schedStart && schedEnd) {
    scParams.set('start', schedStart);
    scParams.set('end', schedEnd);
  }
  const qs = scParams.toString();
  return `${baseUrl}/v1/student-classes${qs ? `?${qs}` : ''}`;
}

export function buildSchedulesApiUrl({
  baseUrl,
  schedStart,
  schedEnd,
  branchId,
  isTeacher,
  userId,
}) {
  const scope = resolveCalendarRoleScopeParams({ isTeacher, userId, branchId });
  const excParams = new URLSearchParams({ per_page: '2000', start: schedStart, end: schedEnd });
  if (scope.branchId) excParams.set('branch_id', scope.branchId);
  if (scope.teacherId) excParams.set('teacher_id', scope.teacherId);
  return `${baseUrl}/v1/schedules?${excParams.toString()}`;
}

/**
 * 平行執行兩個獨立抓取；單邊失敗不阻斷另一邊（與原 try/catch 行為一致）。
 * @returns {Promise<{ courses: { list, apiSucceeded }, schedules: { list, apiSucceeded } }>}
 */
export async function fetchCalendarCoursesAndSchedulesParallel({
  fetchCourses,
  fetchSchedules,
}) {
  const [coursesOutcome, schedulesOutcome] = await Promise.all([
    fetchCourses().then(
      (value) => ({ ok: true, value }),
      () => ({ ok: false, value: { list: [], apiSucceeded: false } }),
    ),
    fetchSchedules().then(
      (value) => ({ ok: true, value }),
      () => ({ ok: false, value: { list: [], apiSucceeded: false } }),
    ),
  ]);

  return {
    courses: coursesOutcome.value,
    schedules: schedulesOutcome.value,
  };
}

/**
 * 量測用：串行總時間 vs 平行總時間的理論節省（ms）。
 * 冷載 waterfall 中 schedules 疊在 student-classes 之後 → 平行化可省 min(coursesMs, schedulesMs)。
 */
export function estimateParallelLoadSavingsMs(coursesMs, schedulesMs) {
  const serialMs = Number(coursesMs) + Number(schedulesMs);
  const parallelMs = Math.max(Number(coursesMs), Number(schedulesMs));
  return {
    serialMs,
    parallelMs,
    savedMs: Math.max(0, serialMs - parallelMs),
  };
}

export async function fetchCalendarStudentClassesApi({
  baseUrl,
  token,
  branchId,
  isTeacher,
  userId,
  schedStart,
  schedEnd,
  fetchAllPages,
  onProgress,
}) {
  const apiUrl = buildStudentClassesApiUrl({
    baseUrl,
    branchId,
    isTeacher,
    userId,
    schedStart,
    schedEnd,
  });
  const { data: allCourses } = await fetchAllPages(apiUrl, token, {
    perPage: 200,
    concurrency: 4,
    onProgress,
  });
  return {
    list: (allCourses || []).map(mapCalendarCourse),
    apiSucceeded: true,
  };
}

export async function fetchCalendarSchedulesApi({
  baseUrl,
  token,
  schedStart,
  schedEnd,
  branchId,
  isTeacher,
  userId,
  fetchImpl = fetch,
}) {
  const url = buildSchedulesApiUrl({
    baseUrl,
    schedStart,
    schedEnd,
    branchId,
    isTeacher,
    userId,
  });
  const excRes = await fetchImpl(url, {
    credentials: 'include',
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  if (!excRes.ok) {
    return { list: [], apiSucceeded: false };
  }
  const excJson = await excRes.json();
  const excList = Array.isArray(excJson) ? excJson : (excJson?.data ?? []);
  return {
    list: excList.map((ex) => ({
      ...ex,
      schedule_date: ex.schedule_date != null ? String(ex.schedule_date).slice(0, 10) : ex.schedule_date,
    })),
    apiSucceeded: true,
  };
}
