import assert from 'node:assert/strict';
import { lessonStatusBadge, LESSON_STATUS_LEGEND } from './lessonStatusBadge.js';

// in-app #342: every calendar lesson says what actually happened, in words (not color / glyph only).
const now = new Date('2026-10-07T12:00:00');
const row = (status, extra = {}) => ({ status, session_date: '2026-10-06', end_time: '18:00', ...extra });
const kindText = (r) => {
  const b = lessonStatusBadge(r, now);
  return b && [b.kind, b.text];
};

assert.equal(lessonStatusBadge(null, now), null, 'no session row → no badge');
for (const st of ['attended', 'completed', 'late', 'absent']) assert.deepEqual(kindText(row(st)), ['done', '已上']);
assert.deepEqual(kindText(row('scheduled', { attendance_sign_in_at: '2026-10-06 17:00' })), ['done', '已上'], 'swipe sign-in counts');
for (const st of ['leave', 'leave_adjusted', 'excused']) assert.deepEqual(kindText(row(st)), ['leave', '請假']);
assert.deepEqual(kindText(row('cancelled')), ['cancelled', '取消']);
assert.deepEqual(kindText(row('scheduled')), ['missed', '漏點名'], 'ended but not marked');
assert.deepEqual(kindText(row('scheduled', { session_date: '2026-10-08' })), ['upcoming', '還沒上'], 'future lesson');
assert.deepEqual(kindText(row('Scheduled', { session_date: '2026-10-07', end_time: '23:00' })), ['upcoming', '還沒上'], 'later today, case-insensitive');

// Every badge carries a short glyph for the tiny calendar cell and the full words for screen readers / legend.
for (const b of LESSON_STATUS_LEGEND) {
  assert.ok(b.label && b.text && b.kind, `legend entry ${b.kind} has glyph + text`);
}
assert.deepEqual(LESSON_STATUS_LEGEND.map((b) => b.text), ['已上', '請假', '取消', '還沒上', '漏點名']);

console.log('ok: lessonStatusBadge');
