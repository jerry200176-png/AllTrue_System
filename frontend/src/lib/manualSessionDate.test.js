import assert from 'node:assert/strict';
import { nextManualSessionDate } from './manualSessionDate.js';

const saturdayCourse = { days_of_week: [6], start_time: '13:00' };

assert.equal(
  nextManualSessionDate(saturdayCourse, { todayYmd: '2026-08-27', currentTime: '10:00' }),
  '2026-08-29',
  'a Thursday opening should default to the next Saturday course date'
);

assert.equal(
  nextManualSessionDate({ day_of_week: 4, start_time: '13:00' }, { todayYmd: '2026-08-27', currentTime: '14:00' }),
  '2026-09-03',
  'an elapsed course slot should advance to the following week'
);

assert.equal(
  nextManualSessionDate({ start_time: '16:00' }, { todayYmd: '2026-08-27', currentTime: '17:00' }),
  '2026-08-28',
  'an unconfigured manual-occurrence course should avoid an elapsed time'
);

console.log('ok: manualSessionDate');
