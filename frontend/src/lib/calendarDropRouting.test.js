import assert from 'node:assert/strict';
import { resolveCalendarDropAction } from './calendarDropRouting.js';

const sameTeacherSameSlot = resolveCalendarDropAction({
  originalDate: '2026-07-14',
  targetDate: '2026-07-14',
  originalStart: '17:30',
  targetHour: 17,
  currentTeacherId: 84,
  targetTeacherId: 84,
});
assert.deepEqual(sameTeacherSameSlot, { kind: 'noop', timeChanged: false });

const sameTimeSubstitute = resolveCalendarDropAction({
  originalDate: '2026-07-14',
  targetDate: '2026-07-14',
  originalStart: '17:30',
  targetHour: 17,
  currentTeacherId: 84,
  targetTeacherId: 67,
});
assert.deepEqual(sameTimeSubstitute, { kind: 'substitute', timeChanged: false });

const combinedSubstitute = resolveCalendarDropAction({
  originalDate: '2026-07-14',
  targetDate: '2026-07-14',
  originalStart: '17:30',
  targetHour: 18,
  currentTeacherId: 84,
  targetTeacherId: 67,
});
assert.deepEqual(
  combinedSubstitute,
  { kind: 'substitute', timeChanged: true },
  'cross-teacher drag at another hour must use substitute+reschedule, never plain reschedule',
);

const ordinaryReschedule = resolveCalendarDropAction({
  originalDate: '2026-07-14',
  targetDate: '2026-07-15',
  originalStart: '17:30',
  targetHour: 18,
  currentTeacherId: 84,
  targetTeacherId: 84,
});
assert.deepEqual(ordinaryReschedule, { kind: 'reschedule', timeChanged: true });

console.log('calendarDropRouting.test.js — 4 passed ✅');
