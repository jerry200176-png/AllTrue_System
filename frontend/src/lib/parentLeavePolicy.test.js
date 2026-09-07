import test from 'node:test';
import assert from 'node:assert/strict';
import { getSessionStartTime, isSessionStartedOrPast, isLateLeave, canRequestParentLeave } from './parentLeavePolicy.js';

test('Parent Leave Policy: getSessionStartTime parses date and time', () => {
  const dt = getSessionStartTime({ SessionDate: '2026-05-10', StartTime: '14:30' });
  assert.equal(dt instanceof Date && dt.getHours() === 14 && dt.getMinutes() === 30, true);
  assert.equal(getSessionStartTime(null), null);
  assert.equal(getSessionStartTime({}), null);
});

test('Parent Leave Policy: timing transitions (>24h standard, <24h late, at/after start closed)', () => {
  const session = { id: 101, SessionDate: '2026-05-10', StartTime: '14:00', Status: 'scheduled' };

  // > 24h before session start: standard leave
  const farMs = new Date('2026-05-08T10:00:00').getTime();
  assert.equal(isSessionStartedOrPast(session, farMs), false);
  assert.equal(isLateLeave(session, farMs), false);
  assert.equal(canRequestParentLeave(session, true, farMs), true);

  // < 24h before session start: late leave
  const lateMs = new Date('2026-05-09T20:00:00').getTime();
  assert.equal(isSessionStartedOrPast(session, lateMs), false);
  assert.equal(isLateLeave(session, lateMs), true);
  assert.equal(canRequestParentLeave(session, true, lateMs), true);

  // Exactly at start time & past start time: closed
  const atStartMs = new Date('2026-05-10T14:00:00').getTime();
  assert.equal(isSessionStartedOrPast(session, atStartMs), true);
  assert.equal(isLateLeave(session, atStartMs), false);
  assert.equal(canRequestParentLeave(session, true, atStartMs), false);

  const pastMs = new Date('2026-05-10T14:10:00').getTime();
  assert.equal(isSessionStartedOrPast(session, pastMs), true);
  assert.equal(canRequestParentLeave(session, true, pastMs), false);
});

test('Parent Leave Policy: rescheduled session, backend flags, and status eligibility', () => {
  // Rescheduled session uses actual new slot
  const resched = { id: 102, SessionDate: '2026-05-12', StartTime: '10:00', Status: 'rescheduled' };
  const okMs = new Date('2026-05-07T10:00:00').getTime();
  assert.equal(canRequestParentLeave(resched, true, okMs), true);
  assert.equal(isLateLeave(resched, okMs), false);

  // Precomputed backend flags take precedence
  const flagged = { id: 103, Status: 'scheduled', is_past_or_started: false, is_late_leave: true };
  assert.equal(isSessionStartedOrPast(flagged), false);
  assert.equal(isLateLeave(flagged), true);
  assert.equal(canRequestParentLeave(flagged, true), true);

  // Status eligibility requires scheduled or rescheduled
  const makeS = (st) => ({ id: 104, SessionDate: '2026-05-10', StartTime: '14:00', Status: st });
  assert.equal(canRequestParentLeave(makeS('scheduled'), true, okMs), true);
  assert.equal(canRequestParentLeave(makeS('rescheduled'), true, okMs), true);
  assert.equal(canRequestParentLeave(makeS('leave_requested'), true, okMs), false);
  assert.equal(canRequestParentLeave(makeS('attended'), true, okMs), false);
  assert.equal(canRequestParentLeave(makeS('scheduled'), false, okMs), false);
});
