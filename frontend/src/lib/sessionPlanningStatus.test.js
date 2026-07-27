import assert from 'node:assert/strict';
import {
  buildSessionPlanningStatus,
  canMaterializeProjectedSession,
  isSessionModeCourse,
  planningStatusToLegacyWarning,
} from './sessionPlanningStatus.js';

// --- capability gate (mirrors ensureProjected ScheduleMode=date) ---
{
  assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'date', payment_type: 'monthly' }), true);
  assert.equal(canMaterializeProjectedSession({ schedule_mode: 'date' }), true);
  assert.equal(canMaterializeProjectedSession({ payment_type: 'monthly' }), true);
  assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'count', payment_type: 'session' }), false);
  assert.equal(canMaterializeProjectedSession({ payment_type: 'session', PackageID: 9 }), false);
  assert.equal(canMaterializeProjectedSession({ ScheduleMode: 'date', Stop: 1 }), false);
}

// --- AC-01 package partial schedule → info, not warning ---
{
  const status = buildSessionPlanningStatus({
    course: {
      payment_type: 'session',
      PackageID: 115,
      package_total_sessions: 56,
      package_remaining_sessions: 56,
    },
    effectiveCount: 8,
    leaveCount: 0,
    hasAnySessionRows: true,
  });
  assert.equal(status.code, 'package_partially_scheduled');
  assert.equal(status.severity, 'info');
  assert.match(status.title, /只排定部分/);
  assert.match(status.message, /56 堂未使用/);
  assert.doesNotMatch(status.message, /還可排/);
  assert.doesNotMatch(status.title + status.message, /不一致/);
  const legacy = planningStatusToLegacyWarning(status);
  assert.equal(legacy?.type, 'under_other');
}

// --- AC-02 package pending leave → warning ---
{
  const status = buildSessionPlanningStatus({
    course: {
      payment_type: 'session',
      PackageID: 115,
      package_total_sessions: 56,
      package_remaining_sessions: 56,
    },
    effectiveCount: 6,
    leaveCount: 2,
    hasAnySessionRows: true,
  });
  assert.equal(status.code, 'pending_makeup');
  assert.equal(status.severity, 'warning');
  assert.match(status.title, /2 堂請假待補/);
  assert.equal(status.action, 'arrange_makeup');
}

// --- AC-03 package over → danger ---
{
  const status = buildSessionPlanningStatus({
    course: {
      payment_type: 'session',
      PackageID: 115,
      package_total_sessions: 56,
      package_remaining_sessions: 50,
    },
    effectiveCount: 59,
    leaveCount: 0,
    hasAnySessionRows: true,
  });
  assert.equal(status.code, 'over_quota');
  assert.equal(status.severity, 'danger');
  assert.match(status.title, /超排 3/);
}

// --- single course partial with remaining count OK ---
{
  const status = buildSessionPlanningStatus({
    course: { payment_type: 'session', sessions_purchased: 12, SessionCount: 12 },
    effectiveCount: 8,
    leaveCount: 0,
    hasAnySessionRows: true,
  });
  assert.equal(status.code, 'course_partially_scheduled');
  assert.equal(status.severity, 'info');
  assert.match(status.message, /已排 8／購買 12/);
  assert.match(status.message, /尚有 4 堂未安排/);
}

// --- healthy ---
{
  const status = buildSessionPlanningStatus({
    course: { payment_type: 'session', sessions_purchased: 8 },
    effectiveCount: 8,
    leaveCount: 0,
    hasAnySessionRows: true,
  });
  assert.equal(status.code, 'healthy');
  assert.equal(planningStatusToLegacyWarning(status), null);
}

// --- AC-04 / AC-08 load error priority ---
{
  const status = buildSessionPlanningStatus({
    course: {
      payment_type: 'session',
      PackageID: 1,
      package_total_sessions: 56,
      package_remaining_sessions: 56,
    },
    effectiveCount: 0,
    leaveCount: 0,
    sessionLoadFailed: true,
    hasAnySessionRows: false,
  });
  assert.equal(status.code, 'session_load_failed');
  assert.equal(status.severity, 'danger');
}

// --- empty schedule package ---
{
  const status = buildSessionPlanningStatus({
    course: {
      payment_type: 'session',
      PackageID: 1,
      package_total_sessions: 56,
      package_remaining_sessions: 56,
    },
    effectiveCount: 0,
    leaveCount: 0,
    hasAnySessionRows: false,
  });
  assert.equal(status.code, 'empty_schedule');
  assert.equal(status.severity, 'info');
  assert.match(status.message, /56 堂未使用/);
}

// --- monthly not session mode → null ---
{
  assert.equal(isSessionModeCourse({ payment_type: 'monthly' }), false);
  const status = buildSessionPlanningStatus({
    course: { payment_type: 'monthly', ScheduleMode: 'date' },
    effectiveCount: 2,
    leaveCount: 0,
    hasAnySessionRows: true,
  });
  assert.equal(status, null);
}

console.log('sessionPlanningStatus.test.js: all assertions passed');
