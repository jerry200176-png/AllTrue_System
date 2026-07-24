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
assert.equal(rowOccupiesPurchasedQuota({ status: 'scheduled', isContractException: true }), false);
assert.equal(rowOccupiesPurchasedQuota({ status: 'scheduled', isContractException: false }), true);
assert.equal(rowOccupiesPurchasedQuota({ status: 'leave' }), false);

console.log('ok: sessionOccurrenceFilter');
