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
 * Distinct current grades among actionable rows, in preview order (in-app #360 cohort chips).
 * @param {Array<{ actionable?: boolean, from_grade?: string }>} rows
 * @returns {string[]}
 */
export function actionableGradeOptions(rows) {
  const seen = [];
  for (const p of Array.isArray(rows) ? rows : []) {
    if (p?.actionable && p.from_grade && !seen.includes(p.from_grade)) seen.push(p.from_grade);
  }
  return seen;
}

/**
 * Cohort filter (in-app #360): keep only actionable students whose current grade is chosen;
 * every other actionable row becomes excluded. An empty choice means "all grades" (nothing
 * excluded). Non-actionable rows are never added, so the confirm payload stays minimal.
 * @param {Array<{ actionable?: boolean, student_id?: number, from_grade?: string }>} rows
 * @param {Iterable<string>} grades
 * @returns {Set<number>}
 */
export function excludeOutsideGrades(rows, grades) {
  const keep = new Set(grades);
  const next = new Set();
  if (keep.size === 0) return next;
  for (const p of Array.isArray(rows) ? rows : []) {
    if (p?.actionable && !keep.has(p.from_grade)) next.add(Number(p.student_id));
  }
  return next;
}

/**
 * Effective exclusions = the director's manual unticks plus every actionable row outside the
 * chosen grades. Changing grade chips never touches the manual set, so row choices survive.
 * @param {Array<{ actionable?: boolean, student_id?: number, from_grade?: string }>} rows
 * @param {Set<number>|Iterable<number>} manualExcluded
 * @param {Iterable<string>} grades
 * @returns {Set<number>}
 */
export function effectivePromotionExcluded(rows, manualExcluded, grades) {
  return new Set([...manualExcluded, ...excludeOutsideGrades(rows, grades)]);
}

/** A row outside the chosen grades is locked (cannot be ticked back in while chips are active). */
export function lockedByCohort(row, grades) {
  const list = [...grades];
  return list.length > 0 && !list.includes(row?.from_grade);
}

/**
 * @param {{
 *   branchId: number|string,
 *   seasonYear: number|null|undefined,
 *   idempotencyKey: string,
 *   excludeStudentIds: Iterable<number>,
 *   onlyGrades?: Iterable<string>,
 * }} args
 */
export function buildGradePromotionConfirmPayload({
  branchId,
  seasonYear,
  idempotencyKey,
  excludeStudentIds,
  onlyGrades = [],
}) {
  const payload = {
    branch_id: Number(branchId),
    idempotency_key: String(idempotencyKey),
    exclude_student_ids: [...excludeStudentIds].map(Number),
  };
  if (seasonYear != null && seasonYear !== '') {
    payload.season_year = Number(seasonYear);
  }
  // Server re-applies the cohort at confirm time (preview may have changed since it was shown).
  const grades = [...onlyGrades].map(String);
  if (grades.length > 0) payload.only_grades = grades;
  return payload;
}

export function gradePromotionSuccessMessage(json, fallbackCount) {
  if (json?.replayed) return '此確認已處理過（重試安全）。';
  const applied = json?.summary?.applied ?? fallbackCount;
  return `升級完成！共 ${applied} 位`;
}
