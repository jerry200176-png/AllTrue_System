/**
 * Enrollment conflict decision kinds used when force-creating after 409.
 * Keep in sync with EnrollmentService force_reason validation.
 */
export const ENROLLMENT_FORCE_REASONS = Object.freeze({
  CREATE_TRIAL: 'create_trial',
  RENEWAL_NEXT_TERM: 'renewal_next_term',
  INDEPENDENT_PARALLEL: 'independent_parallel',
});

export function isAuditedForceReason(reason) {
  return Object.values(ENROLLMENT_FORCE_REASONS).includes(String(reason || ''));
}

/**
 * Build force payload fields for createUniversalClassSchedule.
 * @param {{ force_reason: string, force_note?: string, existing_contract_ids?: Array<number|string> }} decision
 */
export function buildForceOverrideFields(decision) {
  const reason = String(decision?.force_reason || '').trim();
  const note = String(decision?.force_note || '').trim();
  const ids = Array.isArray(decision?.existing_contract_ids)
    ? decision.existing_contract_ids.map((x) => Number(x)).filter((n) => n > 0)
    : [];
  return {
    force: true,
    force_reason: reason,
    force_note: note || null,
    existing_contract_ids: ids,
  };
}
