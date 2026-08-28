/**
 * Normalize subject-unit values received from the API.
 * Laravel may serialize formatted numeric values as strings, so callers must
 * not invoke Number.prototype methods on the raw response value.
 */
export function formatSubjectCount(value) {
  const numeric = Number(value ?? 0);
  return (Number.isFinite(numeric) ? numeric : 0).toFixed(2);
}

