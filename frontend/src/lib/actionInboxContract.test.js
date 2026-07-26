import assert from 'node:assert/strict';
import {
  caseCtaLabel, extractCaseItems, extractCasePageMeta, inboxScopeKey, isInboxBadgeDanger,
  mergeInboxCountState, parseInboxCount, parseInboxDeepLinkSearch, resolveAuthorizedBranchId,
  resolveDefaultLane, shouldShowCachedCases,
} from './actionInboxContract.js';

const c = parseInboxCount({
  notifications_unread: 2, cases_unresolved: 5, cases_overdue: 1, cases_due_soon: 2,
  cases_candidate_ready: 3, urgent_total: 0, badge_total: 7,
});
assert.equal(c.casesCandidateReady, 3);
assert.equal(isInboxBadgeDanger(c.urgentTotal), false);
assert.equal(isInboxBadgeDanger(1), true);
assert.equal(caseCtaLabel({ status_code: 'candidate_ready' }), '檢視並確認');
assert.equal(resolveDefaultLane({ casesUnresolved: 3 }), 'case');
assert.equal(resolveDefaultLane({ casesUnresolved: 0 }), 'ops');
assert.equal(resolveDefaultLane({ casesUnresolved: 0, userPickedLane: true, currentLane: 'case' }), 'case');

const prev = parseInboxCount({ cases_unresolved: 4, badge_total: 4, notifications_unread: 0, urgent_total: 0 });
assert.equal(mergeInboxCountState({ ...prev }, null, { failed: true, scopeKey: 'branch:1', prevScopeKey: 'branch:1' }).casesUnresolved, 4);
assert.equal(mergeInboxCountState({ ...prev }, null, { failed: true, scopeKey: 'branch:2', prevScopeKey: 'branch:1' }).scopeInvalidated, true);
assert.equal(shouldShowCachedCases({ scopeKey: 'branch:2', cachedScopeKey: 'branch:1' }), false);
assert.equal(shouldShowCachedCases({ scopeKey: 'branch:1', cachedScopeKey: 'branch:1' }), true);
assert.equal(inboxScopeKey(2), 'branch:2');

const json = { cases: { data: [{ id: 'workflow:51' }], total: 51, current_page: 3, last_page: 3, per_page: 20, has_more: false } };
assert.equal(extractCaseItems(json).length, 1);
assert.deepEqual(extractCasePageMeta(json), { total: 51, currentPage: 3, lastPage: 3, perPage: 20, hasMore: false });
assert.equal(resolveAuthorizedBranchId(9, [1, 2]), null);
assert.equal(parseInboxDeepLinkSearch('?workflow_id=-3').workflowId, null);

console.log('actionInboxContract.test.js: ok');
