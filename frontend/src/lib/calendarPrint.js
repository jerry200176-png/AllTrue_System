const DAY_NAMES = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];
const STATUS_FILTERS = ['請假', '補課', '代課', '調課', '已取消'];
const FORBIDDEN = /phone|mobile|tel|address|email|line|amount|charge|fee|billing|note|memo|student.?id|class.?id|session.?id/i;

function dateValue(value) {
  const text = String(value || '').slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : '';
}

function dateFrom(value) {
  const [year, month, day] = dateValue(value).split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day));
}

function formatDate(date) {
  return date.toISOString().slice(0, 10);
}

export function getWeekRange(value) {
  const date = dateFrom(value || formatDate(new Date()));
  const day = date.getUTCDay() || 7;
  const monday = new Date(date);
  monday.setUTCDate(date.getUTCDate() - day + 1);
  const sunday = new Date(monday);
  sunday.setUTCDate(monday.getUTCDate() + 6);
  return { start: formatDate(monday), end: formatDate(sunday) };
}

export function getMonthRange(value) {
  const text = dateValue(value || formatDate(new Date()));
  const [year, month] = text.split('-').map(Number);
  const last = new Date(Date.UTC(year, month, 0));
  return { start: `${year}-${String(month).padStart(2, '0')}-01`, end: formatDate(last) };
}

export function getRange(period, value) {
  return period === 'month' ? getMonthRange(value) : getWeekRange(value);
}

function first(...values) {
  return values.find((value) => value !== undefined && value !== null && value !== '') ?? '';
}

function time(value) {
  return String(value || '').slice(0, 5);
}

function dateInRange(date, range) {
  return date && date >= range.start && date <= range.end;
}

function labelStatus(raw) {
  const status = String(raw || '').toLowerCase();
  if (status.includes('cancel')) return '已取消';
  if (status.includes('leave') || status.includes('absent')) return '請假';
  if (status.includes('attend') || status.includes('complete') || status.includes('done')) return '已點名';
  if (status.includes('makeup') || status.includes('extra')) return '補課';
  if (status.includes('reschedul')) return '調課';
  if (status.includes('project')) return '預排';
  return status ? '已排課' : '—';
}

function explicitTeacherId(value) {
  const raw = value?.teacherId ?? value?.teacher_id ?? value?.TeacherID ?? value?.TeacherId;
  return raw === undefined || raw === null || raw === '' ? null : String(raw);
}

export function exceptionMarkers(schedule = {}, session = {}, contractTeacherId = null) {
  const rawType = String(first(schedule.type, schedule.Type, '')).toLowerCase();
  const rawStatus = String(first(schedule.status, schedule.Status, session.status, '')).toLowerCase();
  const markers = [];
  if (rawType === 'extra' || rawType === 'makeup' || rawStatus.includes('makeup')) markers.push('補課');
  if (rawType === 'leave' || rawStatus.includes('leave') || rawStatus.includes('absent')) markers.push('請假');
  const effectiveTeacherId = explicitTeacherId(session);
  const contractId = explicitTeacherId({ teacherId: contractTeacherId });
  if (session.substituteTeacherId || session.substitute_teacher_id || schedule.substitute_teacher_id
    || (effectiveTeacherId && contractId && effectiveTeacherId !== contractId)) markers.push('代課');
  if (schedule.original_schedule_id || rawType === 'reschedule' || rawStatus.includes('reschedul')) markers.push('調課');
  if (rawStatus.includes('cancel')) markers.push('已取消');
  if (rawStatus.includes('change')) markers.push('變更');
  return [...new Set(markers)];
}

function courseKey(course) {
  return String(first(course?.id, course?.student_class_id, course?.StudentClassID, ''));
}

function sessionKey(session) {
  return `${first(session?.studentClassId, session?.student_class_id, session?.StudentClassID)}|${dateValue(first(session?.date, session?.session_date, session?.SessionDate))}|${time(first(session?.startTime, session?.start_time, session?.StartTime))}`;
}

function scheduleKey(schedule) {
  return `${first(schedule?.student_class_id, schedule?.StudentClassID, schedule?.course_id)}|${dateValue(first(schedule?.date, schedule?.schedule_date, schedule?.ScheduleDate))}|${time(first(schedule?.start_time, schedule?.startTime, schedule?.StartTime))}`;
}

export function projectPrintRows({ courses = [], sessions = [], schedules = [], teachers = [], rooms = [], range }) {
  const courseMap = new Map(courses.map((course) => [courseKey(course), course]));
  const teacherMap = new Map(teachers.map((teacher) => [String(first(teacher?.id, teacher?.teacher_id)), first(teacher?.name, teacher?.username, teacher?.Name, '未指派')]));
  const roomMap = new Map(rooms.map((room) => [String(first(room?.id, room?.room_id)), first(room?.name, room?.label, room?.room_name, '')]));
  const scheduleMap = new Map();
  schedules.forEach((schedule) => {
    const key = scheduleKey(schedule);
    const existing = scheduleMap.get(key) || [];
    existing.push(schedule);
    scheduleMap.set(key, existing);
  });
  const materialized = new Set();
  const output = [];
  const normalizedSessions = Array.isArray(sessions) ? sessions : [];

  normalizedSessions.forEach((session) => {
    const date = dateValue(first(session?.date, session?.session_date, session?.SessionDate));
    if (!dateInRange(date, range)) return;
    const key = sessionKey(session);
    if (!session?.isProjected && session?.id) materialized.add(key);
    const course = courseMap.get(String(first(session?.studentClassId, session?.student_class_id, session?.StudentClassID))) || {};
    const scheduleEntries = scheduleMap.get(key) || [];
    const teacherId = first(session?.teacherId, session?.teacher_id, course?.teacher_id);
    const roomId = first(course?.room_id, course?.RoomID, session?.room_id, session?.roomId);
    const markers = [...new Set([
      ...exceptionMarkers({}, session, first(course?.teacher_id, course?.TeacherID)),
      ...scheduleEntries.flatMap((schedule) => exceptionMarkers(schedule, session, first(course?.teacher_id, course?.TeacherID))),
    ])];
    if (session?.isProjected && scheduleEntries.some((schedule) => schedule?.original_schedule_id) && !session?.id) return;
    output.push({
      occurrenceKey: key,
      date,
      weekday: DAY_NAMES[(dateFrom(date).getUTCDay() + 6) % 7],
      startTime: time(first(session?.startTime, session?.start_time, session?.StartTime, course?.start_time)),
      endTime: time(first(session?.endTime, session?.end_time, session?.EndTime, course?.end_time)),
      studentName: first(session?.studentName, session?.student_name, course?.student_name, '—'),
      subjectName: first(course?.subject_name, course?.subject, session?.subjectName, session?.subject, '—'),
      classTypeLabel: first(course?.class_type_label, course?.class_type, '—'),
      effectiveTeacherName: first(session?.teacherName, session?.teacher_name, teacherMap.get(String(teacherId)), '未指派'),
      campusLabel: first(
        course?.campus_name,
        course?.branch_name,
        session?.branchName,
        session?.branch_name,
        first(course?.branch_id, course?.CampusID, session?.branchId, session?.branch_id)
          ? `分校 #${first(course?.branch_id, course?.CampusID, session?.branchId, session?.branch_id)}`
          : '目前分校',
      ),
      roomLabel: roomMap.get(String(roomId)) || first(course?.room_name, session?.room_name, '未設定教室'),
      statusCode: first(session?.status, session?.Status, 'scheduled'),
      statusLabel: labelStatus(first(session?.status, session?.Status)),
      markers,
      isProjected: !!session?.isProjected,
    });
  });

  // A schedule row alone is never enough to create a printable occurrence.
  // It may decorate a materialized/projected session, but cannot leak an orphan destination.
  return output
    .filter((row) => !row.isProjected || !materialized.has(row.occurrenceKey))
    .sort((a, b) => [a.date, a.startTime, a.roomLabel, a.effectiveTeacherName, a.studentName, a.occurrenceKey]
      .map(String).join('\u0000').localeCompare([b.date, b.startTime, b.roomLabel, b.effectiveTeacherName, b.studentName, b.occurrenceKey].map(String).join('\u0000')));
}

export function filterPrintRows(rows, filters = {}) {
  const teacher = String(filters.teacher || '').trim().toLowerCase();
  const room = String(filters.room || '').trim().toLowerCase();
  const student = String(filters.student || '').trim().toLowerCase();
  const statuses = Array.isArray(filters.statuses) ? filters.statuses : [];
  return rows.filter((row) => (
    (!teacher || row.effectiveTeacherName.toLowerCase().includes(teacher))
    && (!room || row.roomLabel.toLowerCase().includes(room))
    && (!student || row.studentName.toLowerCase().includes(student))
    && (!statuses.length || statuses.length === STATUS_FILTERS.length || statuses.some((status) => row.markers.includes(status) || row.statusLabel === status))
  ));
}

export function summarizeRows(rows, range, period) {
  const days = [];
  const cursor = dateFrom(range.start);
  const end = dateFrom(range.end);
  while (cursor <= end) {
    const date = formatDate(cursor);
    const dayRows = rows.filter((row) => row.date === date);
    const exceptions = dayRows.flatMap((row) => row.markers);
    days.push({ date, weekday: DAY_NAMES[(cursor.getUTCDay() + 6) % 7], count: dayRows.length, exceptions: [...new Set(exceptions)] });
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }
  return { period, range, days, total: rows.length, exceptionCount: days.reduce((sum, day) => sum + day.exceptions.length, 0) };
}

export function chunkPrintRows(rows, orientation = 'landscape') {
  const cap = orientation === 'portrait' ? 18 : 24;
  const sheets = [{ kind: 'summary', rows: [] }];
  let current = null;
  rows.forEach((row) => {
    if (!current || current.rows.length >= cap || (current.date !== row.date && current.rows.length >= cap - 2)) {
      current = { kind: 'detail', date: row.date, continuation: !!current && current.date === row.date, rows: [] };
      sheets.push(current);
    }
    current.rows.push(row);
  });
  return sheets.map((sheet, index, all) => ({ ...sheet, page: index + 1, pages: all.length }));
}

export function serializePrintableRows(rows) {
  const safe = rows.map((row) => {
    const copy = { ...row };
    delete copy.occurrenceKey;
    return copy;
  });
  const text = JSON.stringify(safe);
  if (FORBIDDEN.test(text)) throw new Error('print projection contains forbidden field');
  return safe;
}

export function printPageStyle(orientation) {
  return orientation === 'portrait' ? '@page { size: A4 portrait; margin: 10mm; }' : '@page { size: A4 landscape; margin: 10mm; }';
}

export { DAY_NAMES };
