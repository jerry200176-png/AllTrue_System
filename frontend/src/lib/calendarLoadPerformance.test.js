import assert from 'node:assert/strict';
import {
  resolveCalendarDataFetchBoundsYmd,
  shouldUseLegacyCalendarFallback,
} from './calendarLoadPerformance.js';

assert.deepEqual(
  resolveCalendarDataFetchBoundsYmd([
    '2026-05-04',
    '2026-05-05',
    '2026-05-06',
    '2026-05-07',
    '2026-05-08',
    '2026-05-09',
    '2026-05-10',
  ], { displayYear: 2026, displayMonth: 5 }),
  { schedStart: '2026-04-13', schedEnd: '2026-05-31' },
  'calendar fetch window should cover the visible week plus 21 days on both sides'
);

assert.equal(
  shouldUseLegacyCalendarFallback({ apiSucceeded: true }),
  false,
  'successful REST responses should not trigger legacy Supabase fallback'
);

assert.equal(
  shouldUseLegacyCalendarFallback({ apiSucceeded: false }),
  true,
  'failed REST responses should keep the legacy fallback path available'
);
