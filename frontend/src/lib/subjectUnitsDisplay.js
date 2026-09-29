/**
 * Normalize subject-unit values received from the API.
 * Laravel may serialize formatted numeric values as strings, so callers must
 * not invoke Number.prototype methods on the raw response value.
 */
export function formatSubjectCount(value) {
  const numeric = Number(value ?? 0);
  return (Number.isFinite(numeric) ? numeric : 0).toFixed(2);
}


/**
 * Analytical reference total: complete raw weighted total divided by 8 exactly
 * once at the final step (in-app #331). Display only; never feeds payroll.
 */
export function formatSubjectTotalDiv8(rawTotal) {
  const numeric = Number(rawTotal ?? 0);
  return ((Number.isFinite(numeric) ? numeric : 0) / 8).toFixed(2);
}
