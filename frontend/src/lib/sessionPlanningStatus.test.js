import assert from 'node:assert/strict';
import {
  buildSessionPlanningStatus,
  canMaterializeProjectedSession,
  isSessionModeCourse,
  planningStatusToLegacyWarning,
} from './sessionPlanningStatus.js';

function assertNoPackagePoolCopy(status, forbiddenDigits = []) {
  const text = `${status?.title || ''}${status?.message || ''}`;
  assert.doesNotMatch(text, /還可排/);
  assert.doesNotMatch(text, /剩餘/);
  assert.doesNotMatch(text, /尚有\s*\d+\s*堂/);
  assert.doesNotMatch(text, /\d+\s*堂未使用/);
  assert.doesNotMatch(text, /不一致/);
  for (const n of forbiddenDigits) {
    assert.doesNotMatch(text, new RegExp(String(n)));
  }
}

// capability truth table — ScheduleMode=date only
assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'date', payment_type: 'monthly' }), true);
assert.equal(canMaterializeProjectedSession({ payment_type: 'monthly', ScheduleMode: 'date' }), true);
assert.equal(canMaterializeProjectedSession({ payment_type: 'monthly', ScheduleMode: 'count' }), false);
assert.equal(canMaterializeProjectedSession({ payment_type: 'monthly' }), false);
assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'count', payment_type: 'session' }), false);
assert.equal(canMaterializeProjectedSession({ payment_type: 'session', PackageID: 9 }), false);
assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'date', Stop: 1 }), false);
assert.equal(canMaterializeProjectedSession({ schedule_mode: 'date' }), true);
assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'unknown', payment_type: 'monthly' }), false);

// AC-01 package partial → info; no pool remaining digits in copy
{
  const s = buildSessionPlanningStatus({
    course: { payment_type: 'session', PackageID: 115, package_total_sessions: 56, package_remaining_sessions: 56 },
    effectiveCount: 8, leaveCount: 0, hasAnySessionRows: true,
  });
  assert.equal(s.code, 'package_partially_scheduled');
  assert.equal(s.severity, 'info');
  assert.match(s.title, /只排定部分/);
  assert.match(s.message, /共用方案/);
  assertNoPackagePoolCopy(s, [56]);
  assert.equal(s.counts.poolRemaining, 56); // internal diagnostic only
}

// package empty → info; no pool remaining digits
{
  const s = buildSessionPlanningStatus({
    course: { payment_type: 'session', PackageID: 1, package_total_sessions: 56, package_remaining_sessions: 56 },
    effectiveCount: 0, hasAnySessionRows: false,
  });
  assert.equal(s.code, 'empty_schedule');
  assert.equal(s.severity, 'info');
  assert.match(s.title, /尚未排入/);
  assert.match(s.message, /成員課程/);
  assertNoPackagePoolCopy(s, [56]);
}

// AC-02 pending leave → warning
{
  const s = buildSessionPlanningStatus({
    course: { payment_type: 'session', PackageID: 115, package_total_sessions: 56, package_remaining_sessions: 56 },
    effectiveCount: 6, leaveCount: 2, hasAnySessionRows: true,
  });
  assert.equal(s.code, 'pending_makeup');
  assert.equal(s.severity, 'warning');
  assert.match(s.title, /2 堂請假待補/);
}

// AC-03 over → danger
{
  const s = buildSessionPlanningStatus({
    course: { payment_type: 'session', PackageID: 115, package_total_sessions: 56, package_remaining_sessions: 50 },
    effectiveCount: 59, leaveCount: 0, hasAnySessionRows: true,
  });
  assert.equal(s.code, 'over_quota');
  assert.equal(s.severity, 'danger');
  assert.match(s.title, /超排 3/);
}

// single course partial (non-package may show course remaining)
{
  const s = buildSessionPlanningStatus({
    course: { payment_type: 'session', sessions_purchased: 12 },
    effectiveCount: 8, leaveCount: 0, hasAnySessionRows: true,
  });
  assert.equal(s.code, 'course_partially_scheduled');
  assert.match(s.message, /已排 8／購買 12/);
}

assert.equal(buildSessionPlanningStatus({
  course: { payment_type: 'session', sessions_purchased: 8 },
  effectiveCount: 8, leaveCount: 0, hasAnySessionRows: true,
}).code, 'healthy');
assert.equal(planningStatusToLegacyWarning({ code: 'healthy', severity: 'none' }), null);
assert.equal(buildSessionPlanningStatus({
  course: { payment_type: 'session', PackageID: 1, package_total_sessions: 56, package_remaining_sessions: 56 },
  sessionLoadFailed: true,
}).code, 'session_load_failed');
assert.equal(isSessionModeCourse({ payment_type: 'monthly' }), false);
assert.equal(buildSessionPlanningStatus({
  course: { payment_type: 'monthly' }, effectiveCount: 2, hasAnySessionRows: true,
}), null);

console.log('sessionPlanningStatus.test.js: all assertions passed');
