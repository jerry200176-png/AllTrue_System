import {
  hasLeaveExceptionForCourseDate,
  shouldRenderScheduledException,
} from './calendarExceptionMerge.js';

export function defaultToYmd(value) {
  return value ? String(value).slice(0, 10) : '';
}

export function defaultNormalizeTime(value) {
  return value ? String(value).slice(0, 5) : '';
}

function statusOf(row) {
  return String(row?.status || row?.Status || '').toLowerCase();
}

function courseIdOf(row) {
  return row?.student_course_id ?? row?.StudentClassID ?? row?.id ?? null;
}

function sessionDateOf(row) {
  return defaultToYmd(row?.session_date || row?.SessionDate || row?.schedule_date);
}

function sessionStartOf(row, normalizeTime) {
  return normalizeTime(row?.start_time || row?.StartTime || '');
}

function isCancelled(row) {
  return ['cancelled', 'voided'].includes(statusOf(row));
}

function isLeaveStatus(row) {
  return ['leave', 'leave_adjusted', 'excused'].includes(statusOf(row));
}

function isAttendedStatus(row) {
  return ['attended', 'completed', 'late', 'absent'].includes(statusOf(row));
}

function makeFallbackOccurrenceKey(row, dow, normalizeTime) {
  const scid = row?.student_course_id != null ? String(row.student_course_id) : '';
  const sid = row?.student_id != null ? String(row.student_id) : '';
  const studentKey = scid || (sid ? `student:${sid}` : String(row?.student_name || '').trim().toLowerCase());
  const date = defaultToYmd(row?.schedule_date || row?.session_date || '');
  const start = normalizeTime(row?.start_time || '');
  return `fallback:${studentKey}|${date || dow}|${start}|${row?.original_schedule_id || row?.original_id || row?.id || ''}`;
}

function dedupeByStudentSlot(items, normalizeTime) {
  const bestByKey = new Map();
  const scoreRow = (row) => {
    const sessionScore = row?.class_session_id ? 2 : 0;
    const exceptionScore = row?.is_exception ? 1 : 0;
    const idNum = Number(row?.original_id || row?.id || 0) || 0;
    return sessionScore * 1_000_000_000 + exceptionScore * 1_000_000 + idNum;
  };

  for (const row of items) {
    const sid = row?.student_id != null ? `sid:${row.student_id}` : '';
    const name = String(row?.student_name || '').trim().toLowerCase();
    const studentKey = sid || (name ? `name:${name}` : '');
    const date = defaultToYmd(row?.schedule_date || row?.session_date || '');
    const dow = Number(row?.day_of_week || 0);
    const start = normalizeTime(row?.start_time || '');
    const dayKey = date || (dow ? `dow:${dow}` : '');
    const key = studentKey && dayKey && start ? `${studentKey}|${dayKey}|${start}` : '';
    if (!key) continue;
    const prev = bestByKey.get(key);
    if (!prev || scoreRow(row) >= scoreRow(prev)) {
      bestByKey.set(key, row);
    }
  }

  return Array.from(bestByKey.values());
}

export function mergeWeekCalendarOccurrences({
  courses = [],
  allCourses = courses,
  exceptions = [],
  sessionDatesByCourseId = {},
  weekDatesByDow = {},
  courseLastSessionDate = {},
  resolveAllCourseGridTimesForDate,
  isOverSessionLimit = () => false,
  normalizeTime = defaultNormalizeTime,
  computeEndTime = (start) => start,
  resolveStudentName = () => null,
  resolveTeacherName = () => null,
  filterTeacherId = '',
  teacherScopeId = '',
  isTeacher = false,
  currentTeacherId = '',
} = {}) {
  const mergedByOccurrence = new Map();
  const allCoursesById = new Map(allCourses.map((course) => [String(course?.id ?? ''), course]));
  const activeCourseIds = new Set(allCourses.map((course) => Number(course?.id)).filter((id) => id > 0));
  const visibleCourseIds = new Set(courses.map((course) => String(course?.id ?? '')));

  const rowsForCourse = (courseId) => sessionDatesByCourseId[String(courseId)] || [];
  const liveRowsForDate = (courseId, date) => rowsForCourse(courseId)
    .filter((row) => sessionDateOf(row) === defaultToYmd(date) && !isCancelled(row));
  const exactSessionRow = (courseId, date, start) => liveRowsForDate(courseId, date)
    .find((row) => sessionStartOf(row, normalizeTime) === normalizeTime(start));
  const sessionDateSet = (courseId) => {
    const dates = rowsForCourse(courseId)
      .filter((row) => !isCancelled(row))
      .map(sessionDateOf)
      .filter(Boolean);
    return dates.length ? new Set(dates) : null;
  };
  const hasLeaveOnDate = (courseId, date) => {
    const liveRows = liveRowsForDate(courseId, date);
    return liveRows.some(isLeaveStatus) || hasLeaveExceptionForCourseDate(exceptions, date, courseId);
  };
  const scheduledExceptionForSlot = (courseId, date, start) => exceptions.find((ex) =>
    statusOf(ex) === 'scheduled'
    && String(ex?.student_course_id ?? '') === String(courseId)
    && defaultToYmd(ex?.schedule_date) === defaultToYmd(date)
    && normalizeTime(ex?.start_time || '') === normalizeTime(start)
  );
  const putOccurrence = (row) => {
    const key = row.occurrence_key || makeFallbackOccurrenceKey(row, row.day_of_week, normalizeTime);
    const prev = mergedByOccurrence.get(key);
    if (!prev || (row.is_exception && !prev.is_exception) || row.class_session_id) {
      mergedByOccurrence.set(key, { ...prev, ...row, occurrence_key: key });
    }
  };

  for (const course of courses) {
    const cid = String(course?.id ?? '');
    if (!cid) continue;
    const courseSessionSet = sessionDateSet(cid);
    const isHistoricalCourse = Number(course?.stop ?? course?.Stop ?? 0) === 1
      || String(course?.status || '').toLowerCase() === 'inactive';
    if (isHistoricalCourse && !courseSessionSet) continue;
    const days = ((course.days_of_week && course.days_of_week.length)
      ? course.days_of_week
      : [parseInt(course.day_of_week, 10) || 0]).map(Number);

    for (let dow = 1; dow <= 7; dow += 1) {
      const targetDate = defaultToYmd(weekDatesByDow[dow]);
      if (!targetDate) continue;

      const rawHasReschedule = exceptions.some((ex) =>
        statusOf(ex) === 'rescheduled'
        && String(ex?.student_course_id ?? '') === cid
        && defaultToYmd(ex?.schedule_date) === targetDate
      );
      const liveRows = liveRowsForDate(cid, targetDate);
      const attended = liveRows.some(isAttendedStatus);
      const leaveOnDate = hasLeaveOnDate(cid, targetDate);
      const hasReschedule = rawHasReschedule && !attended && !leaveOnDate;

      if (courseSessionSet) {
        if (!courseSessionSet.has(targetDate)) continue;
        const lastDate = courseLastSessionDate[cid] ?? Array.from(courseSessionSet).sort().pop();
        if (lastDate != null && targetDate > lastDate) continue;
        // Stale schedules rows often keep status=rescheduled on a date that still holds a live
        // ClassSession (e.g. same-day reschedule, partial writes, retries). Skip the whole day only
        // when there are no remaining non-cancelled ClassSession rows (see §R39/R43-ish family).
        if (hasReschedule && liveRows.length === 0) continue;
      } else {
        const isFirstDay = course.first_class_date && String(targetDate).trim() === String(course.first_class_date).trim();
        const isRecurringDay = days.includes(dow);
        if (!isFirstDay && !isRecurringDay) continue;
        const isBeforeStart = course.first_class_date && String(targetDate).trim() < String(course.first_class_date).trim();
        const isAfterEnd = course.end_date && String(targetDate).trim() > String(course.end_date).trim();
        if (hasReschedule || isBeforeStart || isAfterEnd || isOverSessionLimit(course.id, targetDate)) continue;
      }

      const times = resolveAllCourseGridTimesForDate
        ? resolveAllCourseGridTimesForDate(course, dow, targetDate)
        : [{ start_time: course.start_time, end_time: course.end_time, duration_hours: course.duration_hours }];

      for (const timeSlot of times) {
        const start = normalizeTime(timeSlot?.start_time || '');
        const scheduledForSlot = scheduledExceptionForSlot(cid, targetDate, start);
        const sessionRow = exactSessionRow(cid, targetDate, start);
        if (!leaveOnDate && scheduledForSlot && !sessionRow) continue;

        const end = timeSlot?.end_time
          ? normalizeTime(timeSlot.end_time)
          : computeEndTime(start, timeSlot?.duration_hours || course.duration_hours || 2);
        const row = {
          ...course,
          ...timeSlot,
          ...(sessionRow?.teacher_id != null ? { teacher_id: sessionRow.teacher_id, teacher_name: sessionRow.teacher_name || course.teacher_name } : {}),
          ...(scheduledForSlot && sessionRow ? {
            teacher_id: scheduledForSlot.teacher_id ?? timeSlot.teacher_id ?? course.teacher_id,
            teacher_name: scheduledForSlot.teacher?.username || resolveTeacherName(scheduledForSlot.teacher_id) || timeSlot.teacher_name || course.teacher_name,
            original_schedule_id: scheduledForSlot.original_schedule_id || null,
          } : {}),
          id: course.id,
          day_of_week: dow,
          days_of_week: [dow],
          start_time: start,
          end_time: end,
          duration_hours: timeSlot?.duration_hours || course.duration_hours,
          is_base: true,
          is_exception: false,
          class_session_id: sessionRow?.id || null,
          student_course_id: course.id,
          occurrence_key: sessionRow?.id ? `session:${sessionRow.id}` : `base:${cid}|${targetDate}|${start}`,
        };
        putOccurrence(row);
      }
    }
  }

  for (let dow = 1; dow <= 7; dow += 1) {
    const targetDate = defaultToYmd(weekDatesByDow[dow]);
    if (!targetDate) continue;
    const renderedScheduledKeys = new Set();
    const dayExceptions = exceptions
      .filter((ex) =>
        defaultToYmd(ex?.schedule_date) === targetDate
        && shouldRenderScheduledException(ex, exceptions, targetDate)
        && (ex.student_course_id == null || activeCourseIds.has(Number(ex.student_course_id)))
        && (!isTeacher || !currentTeacherId || String(ex.teacher_id) === String(currentTeacherId))
      )
      .sort((a, b) => {
        const score = (ex) => {
          const baseCourse = ex.student_course_id != null ? allCoursesById.get(String(ex.student_course_id)) : null;
          const substitute = statusOf(ex) === 'scheduled' && baseCourse && String(ex.teacher_id ?? '') !== String(baseCourse.teacher_id ?? '');
          return substitute ? 0 : 1;
        };
        const priority = score(a) - score(b);
        return priority !== 0 ? priority : (Number(a.id || 0) - Number(b.id || 0));
      });

    for (const ex of dayExceptions) {
      const scid = ex.student_course_id != null ? String(ex.student_course_id) : '';
      const exStart = normalizeTime(ex.start_time || '');
      const key = [
        scid || `student:${ex.student_id ?? ''}`,
        targetDate,
        exStart,
        ex.original_schedule_id || '',
      ].join('|');
      if (renderedScheduledKeys.has(key)) continue;
      renderedScheduledKeys.add(key);

      const sessionRow = scid ? exactSessionRow(scid, targetDate, exStart) : null;
      const occurrenceKey = sessionRow?.id ? `session:${sessionRow.id}` : makeFallbackOccurrenceKey(ex, dow, normalizeTime);
      if (sessionRow && mergedByOccurrence.has(occurrenceKey)) {
        putOccurrence({
          ...mergedByOccurrence.get(occurrenceKey),
          teacher_id: ex.teacher_id,
          teacher_name: ex.teacher?.username || resolveTeacherName(ex.teacher_id) || mergedByOccurrence.get(occurrenceKey)?.teacher_name || '未指派',
          original_schedule_id: ex.original_schedule_id || null,
          is_exception: true,
          source: 'schedule_overlay',
        });
        continue;
      }

      if (scid && !visibleCourseIds.has(scid) && filterTeacherId && String(ex.teacher_id ?? '') !== String(filterTeacherId)) {
        continue;
      }
      const baseCourse = scid ? allCoursesById.get(scid) : null;
      putOccurrence({
        id: `ex_${ex.id}`,
        original_id: ex.id,
        is_exception: true,
        source: 'schedule_exception',
        student_id: ex.student_id,
        student_name: ex.student?.name || resolveStudentName(ex.student_id) || '—',
        teacher_id: ex.teacher_id,
        teacher_name: ex.teacher?.username || resolveTeacherName(ex.teacher_id) || '未指派',
        subject: baseCourse?.subject || ex.subject,
        class_type: baseCourse?.class_type || ex.class_type,
        day_of_week: dow,
        start_time: ex.start_time,
        end_time: ex.end_time,
        duration_hours: ex.duration_hours,
        student_course_id: ex.student_course_id,
        original_schedule_id: ex.original_schedule_id || null,
        class_session_id: null,
        occurrence_key: occurrenceKey,
      });
    }
  }

  // #180 — materialized-completeness pass. ClassSession is the materialized source of truth.
  // The template/exception loops above are *projection* paths and intentionally skip dates via
  // grid guards (e.g. `targetDate > courseLastSessionDate`, over-session-limit, off-template
  // day/time). A course-management adjustment can produce a real, non-cancelled ClassSession that
  // lands outside every projection path AND has no `schedules` row — it would then silently vanish
  // from the calendar while course management still shows it. Emit any such materialized session
  // that was not already rendered, so materialized truth never disappears. Projected/template
  // occurrences are untouched (they are keyed `session:<id>` when session-backed, so already-rendered
  // sessions are skipped here and never duplicated). dedupeByStudentSlot collapses any slot overlap.
  const dowByDate = new Map();
  for (let dow = 1; dow <= 7; dow += 1) {
    const ymd = defaultToYmd(weekDatesByDow[dow]);
    if (ymd) dowByDate.set(ymd, dow);
  }
  for (const course of courses) {
    const cid = String(course?.id ?? '');
    if (!cid) continue;
    for (const sessionRow of rowsForCourse(cid)) {
      const sid = sessionRow?.id;
      if (!sid || isCancelled(sessionRow)) continue;
      if (mergedByOccurrence.has(`session:${sid}`)) continue;
      const date = sessionDateOf(sessionRow);
      const dow = dowByDate.get(date);
      if (!dow) continue;
      const start = sessionStartOf(sessionRow, normalizeTime);
      if (!start) continue;
      const end = sessionRow?.end_time || sessionRow?.EndTime
        ? normalizeTime(sessionRow.end_time || sessionRow.EndTime)
        : computeEndTime(start, course.duration_hours || 2);
      putOccurrence({
        ...course,
        ...(sessionRow?.teacher_id != null
          ? { teacher_id: sessionRow.teacher_id, teacher_name: sessionRow.teacher_name || course.teacher_name }
          : {}),
        id: course.id,
        day_of_week: dow,
        days_of_week: [dow],
        start_time: start,
        end_time: end,
        duration_hours: course.duration_hours,
        is_base: true,
        is_exception: false,
        class_session_id: sid,
        student_course_id: course.id,
        occurrence_key: `session:${sid}`,
      });
    }
  }

  let out = dedupeByStudentSlot(Array.from(mergedByOccurrence.values()), normalizeTime);
  const effectiveFilterTeacherId = filterTeacherId || teacherScopeId || '';
  if (effectiveFilterTeacherId) {
    out = out.filter((row) => String(row.teacher_id ?? '') === String(effectiveFilterTeacherId));
  }
  return out;
}
