/**
 * sessionOccurrenceFilter — shared Course Mgmt / Calendar effective-session rules
 */
import assert from 'node:assert/strict';
import {
  filterEffectiveSessions,
  isEffectiveSession,
  isInternalCancelPlaceholder,
  isVisibleCancelledSession,
  rowOccupiesPurchasedQuota,
  weeklyTemplateOccupiesHour,
} from './sessionOccurrenceFilter.js';

const INTERNAL = 'cancelled-duplicate-reschedule-placeholder';

const rows = [
  { id: 1, status: 'attended', note: '' },
  { id: 2, status: 'cancelled', note: 'manual cancel' },
  { id: 3, status: 'cancelled', note: `x; ${INTERNAL}` },
  { id: 4, status: 'scheduled', note: '' },
];

assert.deepEqual(filterEffectiveSessions(rows).map((r) => r.id), [1, 4]);
assert.equal(isInternalCancelPlaceholder(rows[2]), true);
assert.equal(isVisibleCancelledSession(rows[1]), true);
assert.equal(isVisibleCancelledSession(rows[2]), false);
assert.equal(isEffectiveSession(rows[3]), true);
assert.equal(rowOccupiesPurchasedQuota({ status: 'scheduled', isContractException: true }), true);
assert.equal(rowOccupiesPurchasedQuota({ status: 'scheduled', isContractException: true }, { exceptionsOccupyQuota: false }), false);
assert.equal(rowOccupiesPurchasedQuota({ status: 'scheduled', isContractException: false }), true);
assert.equal(rowOccupiesPurchasedQuota({ status: 'leave' }), false);

const parseHour = (value) => {
  const m = String(value || '').match(/(\d{1,2})/);
  return m ? Number(m[1]) : NaN;
};
assert.equal(weeklyTemplateOccupiesHour({
  sessionRowsOnDate: [{ status: 'scheduled', start_time: '19:00' }],
  hour: 17,
  parseHour,
}), false);
assert.equal(weeklyTemplateOccupiesHour({
  sessionRowsOnDate: [{ status: 'scheduled', start_time: '19:00' }],
  hour: 19,
  parseHour,
}), true);
assert.equal(weeklyTemplateOccupiesHour({
  sessionRowsOnDate: [{ status: 'leave', start_time: '15:00' }],
  hour: 17,
  parseHour,
}), true);
assert.equal(weeklyTemplateOccupiesHour({
  sessionRowsOnDate: [],
  hour: 17,
  parseHour,
}), true);

console.log('ok: sessionOccurrenceFilter');
