import assert from 'node:assert/strict';
import { courseBadgeSessionLabel } from './courseBadgeDisplay.js';

assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 8, remaining_sessions: 3 }), '3堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 8, remaining_sessions: 0 }), '0堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 0, remaining_sessions: 0 }), '堂數待確認');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', remaining_sessions: 0 }), '堂數待確認');
assert.equal(courseBadgeSessionLabel({ payment_type: 'monthly', monthly_sessions: 4 }), '每月4堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'monthly', monthly_sessions: 0 }), '月結');
assert.equal(courseBadgeSessionLabel({ PackageID: 7, package_total_sessions: 12, package_remaining_sessions: 0 }), '0堂');
assert.equal(courseBadgeSessionLabel({ PackageID: 7, package_total_sessions: 0, package_remaining_sessions: 0 }), '堂數待確認');

console.log('courseBadgeDisplay.test.js: all assertions passed');
