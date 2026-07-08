import { ref } from 'vue';
import { supabase } from '../../supabase';
import { fetchClassSessionsProjection, mapSessionViewModelsForCalendar } from '../../lib/classSessionsApi';
import { fetchAllPages } from '../../lib/pagedFetchAll';
import { shouldUseLegacyCalendarFallback } from '../../lib/calendarLoadPerformance';
import {
  fetchCalendarCoursesAndSchedulesParallel,
  fetchCalendarStudentClassesApi,
  fetchCalendarSchedulesApi,
} from '../../lib/calendarCourseLoad';

/**
 * #740 Step 7：行事曆資料載入層（courses / exceptions / class-sessions / 選項清單）。
 */
export function useCalendarDataLoad({
  branchId,
  userId,
  isTeacher,
  viewMode,
  getCalendarDataFetchBoundsYmd,
}) {
  const courses = ref([]);
  const allCoursesUnfiltered = ref([]);
  const exceptions = ref([]);
  const calendarLoading = ref(false);
  const calendarLoadProgress = ref('');
  const sessionDatesByCourseId = ref({});
  const allStudents = ref([]);
  const teachers = ref([]);
  const lastCalendarFetch = ref(null);

  const isCourseActiveForCalendar = (course) => {
    const status = String(course?.status || '').toLowerCase();
    const teacherStatus = String(course?.teacher_status || '').toLowerCase();
    if (teacherStatus === 'suspended' || teacherStatus === 'inactive' || teacherStatus === 'disabled') {
      return false;
    }
    const stopFlag = Number(course?.stop ?? course?.Stop ?? 0);
    if (status === 'inactive' || stopFlag === 1) {
      return viewMode.value === 'week';
    }
    return true;
  };

  const loadCourses = async () => {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const bid = Number(branchId.value ?? branchId) || 0;
    const teacherMode = typeof isTeacher === 'function' ? isTeacher() : !!isTeacher?.value;

    if (!bid && !teacherMode) {
      courses.value = [];
      allCoursesUnfiltered.value = [];
      exceptions.value = [];
      sessionDatesByCourseId.value = {};
      calendarLoading.value = false;
      calendarLoadProgress.value = '';
      lastCalendarFetch.value = null;
      return;
    }

    calendarLoading.value = true;
    calendarLoadProgress.value = '載入課程與排程中…';
    const { schedStart, schedEnd } = getCalendarDataFetchBoundsYmd();

    let courseList = [];
    let courseApiSucceeded = false;
    let excData = [];
    let exceptionsApiSucceeded = false;

    const uid = typeof userId === 'function' ? userId() : (userId?.value ?? userId);

    if (token) {
      const { courses: coursesResult, schedules } = await fetchCalendarCoursesAndSchedulesParallel({
        fetchCourses: () => fetchCalendarStudentClassesApi({
          baseUrl,
          token,
          branchId: bid,
          isTeacher: teacherMode,
          userId: uid,
          schedStart,
          schedEnd,
          fetchAllPages,
          onProgress: (loaded, total) => {
            calendarLoadProgress.value = `載入課程與排程中… ${loaded}/${total}`;
          },
        }),
        fetchSchedules: () => fetchCalendarSchedulesApi({
          baseUrl,
          token,
          schedStart,
          schedEnd,
          branchId: bid,
          isTeacher: teacherMode,
          userId: uid,
        }),
      });
      courseList = coursesResult.list;
      courseApiSucceeded = coursesResult.apiSucceeded;
      excData = schedules.list;
      exceptionsApiSucceeded = schedules.apiSucceeded;
    }

    let supabaseList = [];
    if (shouldUseLegacyCalendarFallback({ apiSucceeded: courseApiSucceeded })) {
      let query = supabase
        .from('student-classes')
        .select('*, student:students(name), teacher:profiles(username)');
      if (!teacherMode && bid) query = query.eq('branch_id', bid);
      if (teacherMode && uid) query = query.eq('teacher_id', uid);
      const { data } = await query;
      supabaseList = (data || []).map((c) => ({
        ...c,
        student_name: c.student?.name || c.student_name || '—',
        teacher_name: c.teacher_name || c.teacher?.username || '未指派',
        day_of_week: parseInt(c.day_of_week, 10) || 0,
        days_of_week: Array.isArray(c.days_of_week) && c.days_of_week.length ? c.days_of_week : null,
        weeks: Array.isArray(c.weeks) && c.weeks.length ? c.weeks : [1, 2, 3, 4, 5],
        first_class_date: c.first_class_date || c.StartDate || null,
        end_date: c.end_date || (c.EndDate ? String(c.EndDate).slice(0, 10) : null),
        status: c.status || '',
        stop: c.stop ?? c.Stop ?? 0,
        payment_type: c.payment_type || (c.ScheduleMode === 'count' ? 'session' : 'monthly'),
        sessions_purchased: c.sessions_purchased ?? c.SessionCount ?? 0,
      }));
    }

    if (courseList.length > 0) {
      const sbMap = Object.fromEntries(supabaseList.map((c) => [c.id, c]));
      courseList = courseList.map((c) => {
        const sb = sbMap[c.id];
        if (sb) {
          const hasApiRemaining = c.remaining_sessions !== null && c.remaining_sessions !== undefined;
          if (!hasApiRemaining && sb.remaining_sessions != null) c.remaining_sessions = sb.remaining_sessions;
          if (sb.first_class_date && !c.first_class_date) c.first_class_date = sb.first_class_date;
        }
        return c;
      });
    } else {
      courseList = supabaseList;
    }

    const preFilterCourseList = courseList;
    courseList = courseList.filter(isCourseActiveForCalendar);
    allCoursesUnfiltered.value = preFilterCourseList;

    if (shouldUseLegacyCalendarFallback({ apiSucceeded: exceptionsApiSucceeded })) {
      let excQuery = supabase.from('schedules').select('*');
      if (!teacherMode && bid) excQuery = excQuery.eq('branch_id', bid);
      if (!teacherMode && uid) excQuery = excQuery.eq('teacher_id', uid);
      const { data: excRaw } = await excQuery;
      excData = Array.isArray(excRaw) ? excRaw : (excRaw?.data || []);
    }

    const studentNameMap = {};
    const teacherNameMap = {};
    courseList.forEach((c) => {
      if (c.student_id && c.student_name) studentNameMap[c.student_id] = c.student_name;
      if (c.teacher_id && c.teacher_name) teacherNameMap[c.teacher_id] = c.teacher_name;
    });
    (teachers.value || []).forEach((t) => { if (t.id && t.username) teacherNameMap[t.id] = t.username; });
    (allStudents.value || []).forEach((s) => { if (s.id && s.name) studentNameMap[s.id] = s.name; });

    excData = excData.map((ex) => ({
      ...ex,
      student: ex.student || (ex.student_id ? { name: studentNameMap[ex.student_id] || null } : null),
      teacher: ex.teacher || (ex.teacher_id ? { username: teacherNameMap[ex.teacher_id] || null } : null),
    }));

    courses.value = courseList;
    exceptions.value = excData;

    sessionDatesByCourseId.value = {};
    if (token && (bid || teacherMode)) {
      const uid = typeof userId === 'function' ? userId() : (userId?.value ?? userId);
      try {
        const fetchOpts = {
          token,
          start: schedStart,
          end: schedEnd,
        };
        if (teacherMode && uid) {
          fetchOpts.teacherId = uid;
        } else if (bid) {
          fetchOpts.branchId = bid;
        }
        const { byClass } = await fetchClassSessionsProjection(fetchOpts);
        sessionDatesByCourseId.value = mapSessionViewModelsForCalendar(byClass || {});
        const knownCourseIds = new Set(
          preFilterCourseList.map((c) => Number(c?.id)).filter((id) => id > 0),
        );
        const sessionOnlyCourses = [];
        Object.entries(byClass || {}).forEach(([scId, rows]) => {
          const cid = Number(scId);
          if (!cid || knownCourseIds.has(cid)) return;
          const row = Array.isArray(rows) ? rows[0] : null;
          if (!row) return;
          sessionOnlyCourses.push({
            id: cid,
            student_id: row.student_id ?? row.studentId,
            student_name: row.student_name ?? row.studentName ?? '—',
            teacher_id: row.teacher_id ?? row.teacherId,
            teacher_name: row.teacher_name ?? row.teacherName ?? '未指派',
            duration_hours: 2,
            status: 'inactive',
            stop: 1,
          });
        });
        if (sessionOnlyCourses.length > 0) {
          allCoursesUnfiltered.value = [...preFilterCourseList, ...sessionOnlyCourses];
        }
      } catch (_) {
        sessionDatesByCourseId.value = {};
      }
    }

    lastCalendarFetch.value = courseApiSucceeded
      ? { branchId: bid, schedStart, schedEnd }
      : null;
    calendarLoading.value = false;
    calendarLoadProgress.value = '';
  };

  const loadStudents = async () => {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) return;
    const teacherMode = typeof isTeacher === 'function' ? isTeacher() : !!isTeacher?.value;
    const bid = branchId?.value ?? branchId;
    const stuParams = new URLSearchParams({ per_page: '500' });
    if (!teacherMode && bid) stuParams.set('branch_id', String(bid));
    try {
      const res = await fetch(`/api/v1/students?${stuParams.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const json = await res.json();
      allStudents.value = Array.isArray(json) ? json : (json?.data || []);
    } catch (e) {
      let query = supabase.from('students').select('id, name');
      if (!teacherMode && bid) query = query.eq('branch_id', bid);
      const { data } = await query;
      allStudents.value = Array.isArray(data) ? data : (data?.data || []);
    }
  };

  const loadTeachers = async () => {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) return;
    const bid = Number(branchId?.value ?? branchId) || 0;
    const teacherMode = typeof isTeacher === 'function' ? isTeacher() : !!isTeacher?.value;
    if (!bid && !teacherMode) {
      teachers.value = [];
      return;
    }
    try {
      const params = new URLSearchParams({ per_page: 'all' });
      if (!teacherMode && bid) {
        params.set('branch_id', String(bid));
        params.set('strict_branch', '1');
      }
      const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const json = await res.json();
      const list = Array.isArray(json) ? json : (json?.data || []);
      const normalized = list
        .map((t) => {
          const id = Number(t?.id ?? 0);
          if (!Number.isFinite(id) || id <= 0) return null;
          const branchIds = Array.isArray(t?.branch_ids)
            ? t.branch_ids.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0)
            : [];
          const branch = Number(t?.branch_id || 0) || null;
          return {
            id,
            name: t?.name || t?.Name || t?.T_Name || t?.username || t?.LoginName || `老師#${id}`,
            username: t?.username || '',
            email: t?.email || '',
            branch_ids: branchIds,
            branch_id: branch,
          };
        })
        .filter(Boolean);
      teachers.value = teacherMode
        ? normalized
        : normalized.filter((t) => (t.branch_ids || []).includes(bid) || Number(t.branch_id || 0) === bid);
    } catch (e) {
      teachers.value = [];
    }
  };

  const reloadCalendarData = (...extraLoaders) => Promise.allSettled([
    loadCourses(),
    loadStudents(),
    loadTeachers(),
    ...extraLoaders.filter((fn) => typeof fn === 'function').map((fn) => fn()),
  ]);

  return {
    courses,
    allCoursesUnfiltered,
    exceptions,
    calendarLoading,
    calendarLoadProgress,
    sessionDatesByCourseId,
    allStudents,
    teachers,
    lastCalendarFetch,
    loadCourses,
    loadStudents,
    loadTeachers,
    reloadCalendarData,
    isCourseActiveForCalendar,
  };
}
