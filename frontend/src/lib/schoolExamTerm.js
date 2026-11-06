/** ROC academic year + semester for a date: Aug-Jan = 1st, Feb-Jul = 2nd. */
export function currentTerm(d = new Date()) {
  const m = d.getMonth() + 1;
  const y = d.getFullYear() - 1911;
  return m >= 8 ? { year: y, semester: 1 } : { year: y - 1, semester: m === 1 ? 1 : 2 };
}
