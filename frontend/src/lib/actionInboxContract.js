/** Action Inbox display contract helpers (B-lite + D). Opaque IDs only in URLs/telemetry. */
export function parseInboxCount(json = {}) {
  const notificationsUnread = Number(json.notifications_unread ?? 0);
  const casesUnresolved = Number(json.cases_unresolved ?? json.cases_open ?? 0);
  const casesOverdue = Number(json.cases_overdue ?? 0);
  const casesDueSoon = Number(json.cases_due_soon ?? 0);
  const urgentTotal = Number(json.urgent_total ?? 0);
  const badgeTotal = Number(json.badge_total ?? json.needs_attention ?? (notificationsUnread + casesUnresolved));
  return { notificationsUnread, casesUnresolved, casesOverdue, casesDueSoon, urgentTotal, badgeTotal };
}
export function isInboxBadgeDanger(urgentTotal) { return Number(urgentTotal || 0) > 0; }
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
export function extractCaseTotal(json, fallbackLen = 0) {
  if (json?.cases?.total != null) return Number(json.cases.total);
  if (json?.summary?.cases_unresolved != null) return Number(json.summary.cases_unresolved);
  if (json?.meta?.cases_unresolved != null) return Number(json.meta.cases_unresolved);
  if (json?.meta?.cases_open != null) return Number(json.meta.cases_open);
  return Number(fallbackLen || 0);
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
  return {
    page: params.get('page') || '',
    section: params.get('section') || '',
    workflowId: Number(params.get('workflow_id') || 0) || null,
    branchId: Number(params.get('branch_id') || 0) || null,
  };
}
export function mergeInboxCountState(prev, next, { failed = false } = {}) {
  if (failed) return { ...prev, stale: true, error: true };
  return { ...next, stale: false, error: false };
}
