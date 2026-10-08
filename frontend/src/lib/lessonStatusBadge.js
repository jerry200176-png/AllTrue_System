// in-app #342: what actually happened to one calendar lesson, as a short glyph for the cell plus the words for
// screen readers, titles and the legend (never color alone). Display only — the session row's status is the truth.

const ATTENDED = new Set(['attended', 'completed', 'late', 'absent']);
const LEAVE = new Set(['leave', 'leave_adjusted', 'excused']);

const BADGES = {
  done: { kind: 'done', label: '✓', text: '已上' },
  leave: { kind: 'leave', label: '假', text: '請假' },
  cancelled: { kind: 'cancelled', label: '取消', text: '取消' },
  upcoming: { kind: 'upcoming', label: '○', text: '還沒上' },
  missed: { kind: 'missed', label: '!', text: '漏點名' },
};

export const LESSON_STATUS_LEGEND = Object.values(BADGES);

/** @param {object|null} row materialized session row for the cell; @param {Date} now */
export function lessonStatusBadge(row, now = new Date()) {
  if (!row) return null;
  const st = String(row.status || '').toLowerCase();
  if (LEAVE.has(st)) return BADGES.leave;
  if (st === 'cancelled') return BADGES.cancelled;
  if (ATTENDED.has(st) || row.attendance_sign_in_at) return BADGES.done;
  if (st === 'scheduled') {
    const end = new Date(`${row.session_date}T${row.end_time || '23:59'}`);
    return end < now ? BADGES.missed : BADGES.upcoming;
  }
  return null;
}
