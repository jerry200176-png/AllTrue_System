/**
 * Node regression：連續天數計算（不依賴 Vue / DOM）
 */
import assert from 'node:assert/strict';
import {
  computeNextStreakState,
  localTodayYmd,
  parseStreakState,
} from './teacherLoginStreak.js';

const t0 = new Date(2026, 4, 10); // May 10, 2026 local

assert.equal(localTodayYmd(t0), '2026-05-10');

let s = computeNextStreakState(null, '2026-05-10');
assert.deepEqual(s, { lastVisitYmd: '2026-05-10', current: 1, longest: 1 });

s = computeNextStreakState(s, '2026-05-10');
assert.deepEqual(s, { lastVisitYmd: '2026-05-10', current: 1, longest: 1 });

s = computeNextStreakState(s, '2026-05-11');
assert.deepEqual(s, { lastVisitYmd: '2026-05-11', current: 2, longest: 2 });

s = computeNextStreakState(s, '2026-05-13');
assert.deepEqual(s, { lastVisitYmd: '2026-05-13', current: 1, longest: 2 });

const parsed = parseStreakState(JSON.stringify({ lastVisitYmd: '2026-05-01', current: 5, longest: 10 }));
assert.equal(parsed.current, 5);
assert.equal(parsed.longest, 10);
