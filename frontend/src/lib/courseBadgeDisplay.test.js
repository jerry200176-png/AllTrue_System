import assert from 'node:assert/strict';
import { courseBadgeSessionLabel, sharedPackageSummaries } from './courseBadgeDisplay.js';

assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 8, remaining_sessions: 3 }), '3堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 8, remaining_sessions: 0 }), '0堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', sessions_purchased: 0, remaining_sessions: 0 }), '堂數待確認');
assert.equal(courseBadgeSessionLabel({ payment_type: 'session', remaining_sessions: 0 }), '堂數待確認');
assert.equal(courseBadgeSessionLabel({ payment_type: 'monthly', monthly_sessions: 4 }), '每月4堂');
assert.equal(courseBadgeSessionLabel({ payment_type: 'monthly', monthly_sessions: 0 }), '月結');
assert.equal(courseBadgeSessionLabel({ PackageID: 7, package_total_sessions: 12, package_remaining_sessions: 0 }), '共用方案');
assert.equal(courseBadgeSessionLabel({ PackageID: 7, package_total_sessions: 0, package_remaining_sessions: 0 }), '共用方案');

console.log('courseBadgeDisplay.test.js: all assertions passed');

const member = { PackageID: 7, package_total_sessions: 25, package_remaining_sessions: 17, package_used_sessions: 8, sessions_purchased: 99, remaining_sessions: 99 };
assert.equal(courseBadgeSessionLabel(member), '共用方案');
assert.deepEqual(sharedPackageSummaries([member, member]), [{ id: 7, name: '共用方案', total: 25, remaining: 17, used: 8 }]);
assert.equal(sharedPackageSummaries([member, { ...member, package_remaining_sessions: 16 }])[0].remaining, null);
assert.equal(sharedPackageSummaries([{ ...member, package_remaining_sessions: null }])[0].remaining, null);
assert.deepEqual(sharedPackageSummaries([{ sessions_purchased: 25 }]), []);
