import assert from 'node:assert/strict';
import test from 'node:test';
import { holdBlockStyle, holdDurationMinutes, holdsInSlot, laneStyle } from './trialHolds.js';

const hold = (over = {}) => ({ id: 1, campus_id: 9, teacher_id: 5, hold_date: '2026-11-21', start_time: '16:30', end_time: '17:30', ...over });

test('a hold shows only in its campus, date, start hour and (when filtered) teacher', () => {
  const holds = [hold(), hold({ id: 2, campus_id: 15 }), hold({ id: 3, hold_date: '2026-11-22' }), hold({ id: 4, start_time: '17:00', end_time: '18:00' })];
  assert.deepEqual(holdsInSlot(holds, { date: '2026-11-21', hour: 16, campusId: '9' }).map((h) => h.id), [1]);
  assert.deepEqual(holdsInSlot(holds, { date: '2026-11-21', hour: 17, campusId: 9 }).map((h) => h.id), [4]);
  assert.deepEqual(holdsInSlot(holds, { date: '2026-11-21', hour: 16, campusId: 9, teacherIds: new Set(['6']) }), []);
  assert.deepEqual(holdsInSlot(null, { date: '2026-11-21', hour: 16, campusId: 9 }), []);
});

test('position follows the minute offset and the duration', () => {
  assert.deepEqual(holdBlockStyle(hold()), { top: '32px', height: '50px' });
  assert.deepEqual(holdBlockStyle(hold({ start_time: '16:00', end_time: '18:00' })), { top: '3px', height: '107px' });
  assert.equal(holdBlockStyle(hold({ start_time: '16:00', end_time: '16:10' })).height, '20px', 'very short holds stay tappable');
  assert.equal(holdDurationMinutes(hold()), 60);
});

test('a hold sharing a slot with a lesson gets its own lane instead of covering it', () => {
  assert.deepEqual(laneStyle(0, 1), {});
  assert.deepEqual(laneStyle(1, 2), { left: 'calc(50% + 2px)', width: 'calc(50% - 4px)', right: 'auto' });
});
