/**
 * Grade promotion staff UI helpers (in-app #297 Phase A).
 * Preview/confirm semantics live on the server — this module only shapes UI state/payloads.
 */

export function createGradePromotionIdempotencyKey(now = Date.now(), random = Math.random) {
  return `gp-${now}-${random().toString(36).slice(2, 10)}`;
}

/**
 * @param {Array<{ actionable?: boolean, student_id?: number }>} rows
 * @param {Set<number>|Iterable<number>} excluded
 */
export function countActionableSelected(rows, excluded) {
  const skip = excluded instanceof Set ? excluded : new Set(excluded);
  return (Array.isArray(rows) ? rows : []).filter(
    (p) => p?.actionable && !skip.has(p.student_id)
  ).length;
}

/**
 * Checkbox checked = include in promotion (not excluded).
 * @param {Set<number>} excluded
 * @param {number} studentId
 * @param {boolean} checked
 */
export function toggleGradePromotionExclude(excluded, studentId, checked) {
  const next = new Set(excluded);
  const id = Number(studentId);
  if (checked) next.delete(id);
  else next.add(id);
  return next;
}

/**
 * @param {{
 *   branchId: number|string,
 *   seasonYear: number|null|undefined,
 *   idempotencyKey: string,
 *   excludeStudentIds: Iterable<number>,
 * }} args
 */
export function buildGradePromotionConfirmPayload({
  branchId,
  seasonYear,
  idempotencyKey,
  excludeStudentIds,
}) {
  const payload = {
    branch_id: Number(branchId),
    idempotency_key: String(idempotencyKey),
    exclude_student_ids: [...excludeStudentIds].map(Number),
  };
  if (seasonYear != null && seasonYear !== '') {
    payload.season_year = Number(seasonYear);
  }
  return payload;
}

export function gradePromotionSuccessMessage(json, fallbackCount) {
  if (json?.replayed) return '此確認已處理過（重試安全）。';
  const applied = json?.summary?.applied ?? fallbackCount;
  return `升級完成！共 ${applied} 位`;
}
