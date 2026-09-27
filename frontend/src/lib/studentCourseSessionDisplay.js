import { SESSION_STATUS_LABELS } from './sessionStatus.js';

export const COURSE_SESSION_PREVIEW_LIMIT = 3;

const WEEKDAY_LABELS = ['週日', '週一', '週二', '週三', '週四', '週五', '週六'];

export function sessionDateKey(studentId, courseId) {
  return `${Number(studentId) || 0}:${Number(courseId) || 0}`;
}

export function formatStudentCourseSessionDate(session) {
  const date = String(session?.date || '').slice(0, 10);
  if (!date) return '—';

  const weekday = WEEKDAY_LABELS[new Date(`${date}T12:00:00`).getDay()] || '';
  const start = String(session?.startTime || '').slice(0, 5);
  const end = String(session?.endTime || '').slice(0, 5);
  const time = start && end ? ` ${start}–${end}` : (start ? ` ${start}` : '');
  return `${date}（${weekday}）${time}`;
}

export function studentCourseSessionStatusLabel(session) {
  const status = String(session?.status || '').toLowerCase();
  if (status === 'projected') return '預排';
  return SESSION_STATUS_LABELS[status] || '';
}

export function buildStudentCourseSessionPreview(sessions = [], expanded = false) {
  const rows = Array.isArray(sessions) ? sessions.filter((session) => session?.date) : [];
  const overflow = Math.max(0, rows.length - COURSE_SESSION_PREVIEW_LIMIT);
  return {
    visible: expanded ? rows : rows.slice(0, COURSE_SESSION_PREVIEW_LIMIT),
    total: rows.length,
    overflow,
  };
}

export function studentCourseSessionRowKey(session, fallback = '') {
  return session?.id
    ? `session:${session.id}`
    : `session:${session?.studentClassId || fallback}:${session?.date || ''}:${session?.startTime || ''}`;
}
