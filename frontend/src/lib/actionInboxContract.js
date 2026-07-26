/** Action Inbox helpers (B-lite + D). */
export function parseInboxCount(json = {}) {
  const notificationsUnread = Number(json.notifications_unread ?? 0);
  const casesUnresolved = Number(json.cases_unresolved ?? json.cases_open ?? 0);
  const casesOverdue = Number(json.cases_overdue ?? 0);
  const casesDueSoon = Number(json.cases_due_soon ?? 0);
  const casesCandidateReady = Number(json.cases_candidate_ready ?? 0);
  const urgentTotal = Number(json.urgent_total ?? 0);
  const badgeTotal = Number(json.badge_total ?? json.needs_attention ?? (notificationsUnread + casesUnresolved));
  return { notificationsUnread, casesUnresolved, casesOverdue, casesDueSoon, casesCandidateReady, urgentTotal, badgeTotal };
}

export function isInboxBadgeDanger(urgentTotal) {
  return Number(urgentTotal || 0) > 0;
}

export function caseCtaLabel(item) {
  if (item?.action?.label) return String(item.action.label);
  const code = String(item?.status_code || '');
  if (code === 'candidate_ready') return '檢視並確認';
  if (code === 'confirmed' || code === 'waived') return '查看結果';
  return '安排補課';
}

export function casePriorityLabel(item) {
  if (item?.overdue || item?.priority === 'overdue') return '已逾期';
  if (item?.priority === 'due_soon') return '即將到期';
  return '待處理';
}

export function extractCaseItems(json) {
  if (Array.isArray(json?.cases?.data)) return json.cases.data;
  if (Array.isArray(json?.data)) return json.data.filter((r) => r?.lane === 'case');
  return [];
}

export function extractCasePageMeta(json) {
  const c = json?.cases || {};
  return {
    total: Number(c.total ?? json?.summary?.cases_unresolved ?? 0),
    currentPage: Number(c.current_page ?? 1),
    lastPage: Number(c.last_page ?? 1),
    perPage: Number(c.per_page ?? 20),
    hasMore: Boolean(c.has_more),
  };
}

export function extractCaseTotal(json, fallbackLen = 0) {
  if (json?.cases?.total != null) return Number(json.cases.total);
  if (json?.summary?.cases_unresolved != null) return Number(json.summary.cases_unresolved);
  if (json?.meta?.cases_unresolved != null) return Number(json.meta.cases_unresolved);
  if (json?.meta?.cases_open != null) return Number(json.meta.cases_open);
  return Number(fallbackLen || 0);
}

export function inboxScopeKey(branchId) {
  const id = Number(branchId || 0);
  return id > 0 ? `branch:${id}` : 'branch:none';
}

export function shouldShowCachedCases({ scopeKey, cachedScopeKey }) {
  return Boolean(cachedScopeKey) && cachedScopeKey === scopeKey;
}

export function resolveDefaultLane({ casesUnresolved, userPickedLane = false, currentLane = 'case' }) {
  if (userPickedLane) return currentLane === 'ops' ? 'ops' : 'case';
  return Number(casesUnresolved || 0) > 0 ? 'case' : 'ops';
}

export function buildInboxDeepLinkQuery({ branchId, workflowId, page = 'director', section = 'exception-workflows' } = {}) {
  const q = new URLSearchParams();
  if (page) q.set('page', String(page));
  if (section) q.set('section', String(section));
  const wf = Number(workflowId || 0);
  if (wf > 0) q.set('workflow_id', String(wf));
  const bid = Number(branchId || 0);
  if (bid > 0) q.set('branch_id', String(bid));
  return q;
}

export function parseInboxDeepLinkSearch(search) {
  const params = new URLSearchParams(typeof search === 'string' ? search : '');
  const wf = Number(params.get('workflow_id') || 0);
  const bid = Number(params.get('branch_id') || 0);
  return {
    page: params.get('page') || '',
    section: params.get('section') || '',
    workflowId: Number.isFinite(wf) && wf > 0 ? wf : null,
    branchId: Number.isFinite(bid) && bid > 0 ? bid : null,
  };
}

/** Clamp URL branch_id to authorized campuses (non-super_admin). */
export function resolveAuthorizedBranchId(requestedId, authorizedIds = [], { allowAny = false } = {}) {
  const id = Number(requestedId || 0);
  if (!(id > 0)) return null;
  if (allowAny) return id;
  return new Set((authorizedIds || []).map(Number)).has(id) ? id : null;
}

export function mergeInboxCountState(prev, next, { failed = false, scopeKey = null, prevScopeKey = null } = {}) {
  if (!failed) return { ...next, stale: false, error: false, scopeInvalidated: false };
  if (scopeKey && prevScopeKey && scopeKey !== prevScopeKey) {
    return {
      notificationsUnread: 0, casesUnresolved: 0, casesOverdue: 0, casesDueSoon: 0,
      casesCandidateReady: 0, urgentTotal: 0, badgeTotal: 0, stale: true, error: true, scopeInvalidated: true,
    };
  }
  return { ...prev, stale: true, error: true, scopeInvalidated: false };
}
