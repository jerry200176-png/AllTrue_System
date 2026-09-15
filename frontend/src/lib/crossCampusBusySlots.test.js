import assert from 'node:assert/strict';
import {
  hasCrossCampusBusySlot,
  normalizeCrossCampusBusySlots,
} from './crossCampusBusySlots.js';

const slots = normalizeCrossCampusBusySlots([
  { start_time: '10:00', end_time: '12:00', campus_id: 16, remaining_capacity: 0 },
  { start_time: '13:00', end_time: '15:00', campus_id: 16, remaining_capacity: 1 },
  { start_time: '15:30', end_time: '16:30', campus_id: 9, remaining_capacity: 0 },
  { start_time: '16:00', end_time: '17:00', campus_id: 15, remaining_capacity: 0 },
  { start_time: 'bad', end_time: '17:00', campus_id: 16, remaining_capacity: 0 },
], 15);

assert.deepEqual(slots, [
  { start: 600, end: 720, campusId: 16 },
  { start: 930, end: 990, campusId: 9 },
], 'only full slots at another campus are presentation candidates');
assert.equal(hasCrossCampusBusySlot(slots, 9), false, 'a slot ending at 10:00 does not mark the next hour');
assert.equal(hasCrossCampusBusySlot(slots, 10), true, '10:00–11:00 overlaps a cross-campus busy interval');
assert.equal(hasCrossCampusBusySlot(slots, 15), true, '15:00–16:00 overlaps a 15:30 start');
assert.equal(hasCrossCampusBusySlot(slots, 17), false, '17:00 is outside the 15:30–16:30 interval boundary');
assert.deepEqual(normalizeCrossCampusBusySlots([], 15), [], 'empty availability remains empty');

console.log('cross-campus busy-slot helpers: ok');
