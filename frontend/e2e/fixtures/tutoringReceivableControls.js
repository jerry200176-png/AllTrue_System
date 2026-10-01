import { expect } from '@playwright/test';

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
    active_tutoring_count: tutoringIds.length,
    regular_unpaid_count: regularIds.length,
    live_tutoring_exclusion_checked: tutoringIds.length > 0,
  };
}
