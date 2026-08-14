import assert from 'node:assert/strict';
import {
  PENDING_WORKFLOW_STATUSES,
  isPendingWorkflowStatus,
  reconcileFocusedWorkflowList,
} from './exceptionWorkflowFocus.js';

assert.deepEqual(PENDING_WORKFLOW_STATUSES, ['open', 'candidate_ready']);

assert.equal(isPendingWorkflowStatus('open'), true);
assert.equal(isPendingWorkflowStatus('candidate_ready'), true);
assert.equal(isPendingWorkflowStatus('waived'), false);
assert.equal(isPendingWorkflowStatus('confirmed'), false);
assert.equal(isPendingWorkflowStatus('rejected'), false);
assert.equal(isPendingWorkflowStatus(null), false);
assert.equal(isPendingWorkflowStatus(undefined), false);
assert.equal(isPendingWorkflowStatus(''), false);

// Inserts a still-pending focused workflow that is missing from the list.
assert.deepEqual(
  reconcileFocusedWorkflowList([{ id: 2, status: 'open' }], { id: 1, status: 'open' }).map((w) => w.id),
  [1, 2],
);

// Inserts a candidate_ready focused workflow.
assert.deepEqual(
  reconcileFocusedWorkflowList([], { id: 1, status: 'candidate_ready' }).map((w) => w.id),
  [1],
);

// Regression: GitHub #1625 / in-app #215 — must NOT resurrect a waived workflow.
{
  const existing = [{ id: 2, status: 'open' }];
  const result = reconcileFocusedWorkflowList(existing, { id: 1, status: 'waived' });
  assert.equal(result, existing, 'a waived workflow must not be spliced back into the pending list');
  assert.deepEqual(result.map((w) => w.id), [2]);
}

// Must NOT resurrect a confirmed workflow.
{
  const existing = [];
  const result = reconcileFocusedWorkflowList(existing, { id: 1, status: 'confirmed' });
  assert.equal(result, existing);
}

// Must NOT resurrect a rejected workflow.
{
  const existing = [];
  const result = reconcileFocusedWorkflowList(existing, { id: 1, status: 'rejected' });
  assert.equal(result, existing);
}

// Must not duplicate a pending workflow already present in the list.
{
  const existing = [{ id: 1, status: 'open' }];
  const result = reconcileFocusedWorkflowList(existing, { id: 1, status: 'open' });
  assert.equal(result, existing);
}

// Returns the existing list unchanged when the fetched workflow is missing.
{
  const existing = [{ id: 2, status: 'open' }];
  assert.equal(reconcileFocusedWorkflowList(existing, null), existing);
  assert.equal(reconcileFocusedWorkflowList(existing, undefined), existing);
}

// Defaults to an empty list when existingList is not provided.
assert.deepEqual(reconcileFocusedWorkflowList(undefined, null), []);

console.log('exceptionWorkflowFocus.test.js OK');
