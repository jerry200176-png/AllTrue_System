import assert from 'node:assert/strict';
import { FINAL_LEAVE_STATUSES, LEAVE_STATUSES, NON_QUOTA_SESSION_STATUSES, SESSION_STATUS_LABELS } from './sessionStatus.js';

assert.equal(LEAVE_STATUSES.has('leave_requested'), true);
assert.equal(NON_QUOTA_SESSION_STATUSES.has('leave_requested'), true);
assert.equal(FINAL_LEAVE_STATUSES.has('leave_requested'), false);
assert.equal(SESSION_STATUS_LABELS.leave_requested, '請假待審');
assert.equal(SESSION_STATUS_LABELS.leave, '請假');
