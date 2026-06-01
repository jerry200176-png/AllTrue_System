import assert from 'node:assert/strict';
import {
  resolveCalendarDataFetchBoundsYmd,
  shouldUseLegacyCalendarFallback,
  isRangeWithinFetchedBounds,
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

// TD-062 Phase 1：視窗包含判斷（換週快取命中與否）
const fetched = { branchId: 1, schedStart: '2026-04-13', schedEnd: '2026-05-31' };

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-05-04', max: '2026-05-10' }, fetched, 1),
  true,
  'a week fully inside the prefetched buffer (same branch) should skip refetch'
);

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-05-04', max: '2026-05-10' }, fetched, 2),
  false,
  'different branch must always refetch even if dates overlap'
);

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-06-01', max: '2026-06-07' }, fetched, 1),
  false,
  'a week past schedEnd must refetch (out of buffer)'
);

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-04-06', max: '2026-04-12' }, fetched, 1),
  false,
  'a week before schedStart must refetch (out of buffer)'
);

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-04-13', max: '2026-05-31' }, fetched, 1),
  true,
  'a range exactly on the buffer edges is still covered'
);

assert.equal(
  isRangeWithinFetchedBounds({ min: '2026-05-04', max: '2026-05-10' }, null, 1),
  false,
  'missing fetched window must refetch'
);

assert.equal(
  isRangeWithinFetchedBounds(null, fetched, 1),
  false,
  'missing target range must refetch'
);

// teacher 視圖：branchId 為 0，視窗 branchId 也為 0 → 以日期判斷
assert.equal(
  isRangeWithinFetchedBounds(
    { min: '2026-05-04', max: '2026-05-10' },
    { branchId: 0, schedStart: '2026-04-13', schedEnd: '2026-05-31' },
    0,
  ),
  true,
  'teacher view (branch 0) is covered by date containment'
);
