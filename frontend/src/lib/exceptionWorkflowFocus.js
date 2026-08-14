// Single source of truth for "is this exception workflow still pending" so the
// director queue (loadExceptionWorkflows) and the deep-link focus re-fetch
// (applyFocusFromInbox) can never drift apart again (see GitHub #1625 / in-app #215:
// a closed workflow fetched by id was unconditionally spliced back into the
// visible queue, making an already-approved/rejected leave case look stuck).
export const PENDING_WORKFLOW_STATUSES = Object.freeze(['open', 'candidate_ready']);

export function isPendingWorkflowStatus(status) {
  return PENDING_WORKFLOW_STATUSES.includes(String(status || ''));
}

// Given the current visible pending list and a workflow fetched by id (deep-link
// focus target), return the list the UI should show. Never resurrects a workflow
// that is no longer pending, and never duplicates one already present.
export function reconcileFocusedWorkflowList(existingList, fetchedWorkflow) {
  const list = existingList || [];
  if (!fetchedWorkflow) return list;
  if (!isPendingWorkflowStatus(fetchedWorkflow.status)) return list;
  const wfId = Number(fetchedWorkflow.id);
  if (list.some((w) => Number(w.id) === wfId)) return list;
  return [fetchedWorkflow, ...list];
}
