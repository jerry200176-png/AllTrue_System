import assert from 'node:assert/strict';
import {
  ENROLLMENT_FORCE_REASONS,
  buildForceOverrideFields,
  isAuditedForceReason,
} from './enrollmentConflictDecision.js';

assert.equal(isAuditedForceReason(ENROLLMENT_FORCE_REASONS.CREATE_TRIAL), true);
assert.equal(isAuditedForceReason('yes_i_know'), false);

const fields = buildForceOverrideFields({
  force_reason: ENROLLMENT_FORCE_REASONS.INDEPENDENT_PARALLEL,
  force_note: '同科不同時段',
  existing_contract_ids: ['12', 34, 0, null],
});
assert.deepEqual(fields, {
  force: true,
  force_reason: 'independent_parallel',
  force_note: '同科不同時段',
  existing_contract_ids: [12, 34],
});

console.log('enrollmentConflictDecision.test.js: ok');
