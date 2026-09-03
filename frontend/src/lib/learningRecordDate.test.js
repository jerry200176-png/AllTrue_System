import assert from 'node:assert/strict';
import {
  addMinutesToTime,
  dayOfWeekFromYmd,
  formatLocalDate,
  localTodayYmd,
} from './learningRecordDate.js';

assert.equal(formatLocalDate(new Date(2026, 0, 2)), '2026-01-02');
assert.match(localTodayYmd(), /^\d{4}-\d{2}-\d{2}$/);

assert.equal(dayOfWeekFromYmd('2026-09-07'), 1);
assert.equal(dayOfWeekFromYmd('2026-09-13'), 7);
assert.equal(dayOfWeekFromYmd(''), 1);

assert.equal(addMinutesToTime('09:30', 90), '11:00');
assert.equal(addMinutesToTime('23:30', 60), '00:30');
assert.equal(addMinutesToTime('invalid', 60), '');

console.log('learningRecordDate.test.js: all assertions passed');
