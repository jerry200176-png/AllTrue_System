import { expect } from '@playwright/test';

// The course list and AR are independent readers of the canonical resolver.
// Stopped debt remains AR; an unbilled course's Charge-Pay is only an estimate.
export function invoiceReceivableControls(courses, branchId) {
  const seen = new Set();
  const regularIds = [];
  const tutoringIds = [];
  let regularOutstanding = 0;
  for (const course of courses) {
    const id = String(course.id ?? course.ID ?? '');
    const campus = Number(course.student?.CampusID ?? course.CampusID ?? course.campus_id);
    const verdict = course.billing_verdict;
    if (!id || seen.has(id) || campus !== branchId || !verdict
      || !['paid', 'partial', 'unpaid', 'unbilled', 'free', 'review_required'].includes(verdict.status)
      || !['invoice', 'none', 'legacy_flag'].includes(verdict.source)
      || !Number.isSafeInteger(verdict.outstanding) || verdict.outstanding < 0) {
      throw new Error('incomplete or invalid scoped billing verdict evidence');
    }
    seen.add(id);
    const tutoring = String(course.ClassType ?? course.class_type ?? '').trim().toLowerCase() === 'tutoring';
    if (tutoring) tutoringIds.push(id);
    if (tutoring || course.closed_reason === 'waived') continue;
    if (verdict.source === 'invoice' && ['unpaid', 'partial'].includes(verdict.status) && verdict.outstanding > 0) {
      regularIds.push(id);
      regularOutstanding += verdict.outstanding;
      if (!Number.isSafeInteger(regularOutstanding)) throw new Error('receivable control exceeds safe integer range');
    }
  }
  return { regularIds, tutoringIds, regularOutstanding };
}

// Production data may have no active tutoring course. Keep that coverage gap
// visible while checking the regular unpaid control and all observed tutoring.
export function assertTutoringReceivableControls({ branchId, tutoringIds, regularIds, alertIds, agingTotal, regularOutstanding, tutoringOutstanding }) {
  expect(regularIds.length, 'production branch must provide a non-empty regular unpaid control').toBeGreaterThan(0);
  expect(new Set(tutoringIds).size, 'tutoring paired control must expose unique class IDs').toBe(tutoringIds.length);
  expect(tutoringIds.every(Boolean), 'tutoring paired control must expose stable class IDs').toBe(true);
  for (const id of tutoringIds) expect(alertIds.has(id), `tutoring course ${id} leaked into alerts`).toBe(false);
  expect(regularIds.some((id) => alertIds.has(id)), 'regular unpaid paired control should remain visible').toBe(true);
  expect(agingTotal, 'AR total must equal regular unpaid control only').toBe(regularOutstanding);
  if (tutoringOutstanding > 0) expect(agingTotal).not.toBe(regularOutstanding + tutoringOutstanding);
  return {
    branch_id: branchId,
    observed_tutoring_count: tutoringIds.length,
    regular_unpaid_count: regularIds.length,
    live_tutoring_exclusion_checked: tutoringIds.length > 0,
  };
}
