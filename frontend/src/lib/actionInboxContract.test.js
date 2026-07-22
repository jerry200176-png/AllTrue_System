import { describe, expect, it } from 'vitest';
import {
  caseCtaLabel, extractCaseItems, extractCaseTotal, isInboxBadgeDanger,
  mergeInboxCountState, parseInboxCount, buildInboxDeepLinkQuery, parseInboxDeepLinkSearch,
} from './actionInboxContract.js';

describe('actionInboxContract', () => {
  it('count + badge danger + CTA + pagination extract + fail-soft merge + deep link', () => {
    const c = parseInboxCount({
      notifications_unread: 2, cases_unresolved: 5, cases_overdue: 1, cases_due_soon: 2,
      urgent_total: 1, badge_total: 7, cases_open: 5, needs_attention: 7,
    });
    expect(c).toMatchObject({ casesUnresolved: 5, urgentTotal: 1, badgeTotal: 7 });
    expect(isInboxBadgeDanger(0)).toBe(false);
    expect(isInboxBadgeDanger(1)).toBe(true);
    expect(caseCtaLabel({ status_code: 'candidate_ready' })).toBe('檢視並確認');
    expect(caseCtaLabel({ status_code: 'open', action: { label: '安排補課' } })).toBe('安排補課');
    const json = { summary: { cases_unresolved: 51 }, cases: { data: [{ id: 'workflow:1' }], total: 51 } };
    expect(extractCaseItems(json)).toHaveLength(1);
    expect(extractCaseTotal(json)).toBe(51);
    const prev = { ...c, stale: false, error: false };
    const merged = mergeInboxCountState(prev, null, { failed: true });
    expect(merged.casesUnresolved).toBe(5);
    expect(merged.error).toBe(true);
    const q = buildInboxDeepLinkQuery({ branchId: 1, workflowId: 88 });
    expect(q.get('workflow_id')).toBe('88');
    expect(parseInboxDeepLinkSearch(`?${q}`).workflowId).toBe(88);
  });
});
