/**
 * Small, factual labels for course badges in the student list.
 *
 * A literal "0 堂" is useful only when the course has a configured package
 * of sessions. A legacy or incomplete row with no configured total is not a
 * zero-session contract, so keep the uncertainty visible instead of making
 * it look like a completed course or silently hiding the row.
 */
function finiteNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

export function courseBadgeSessionLabel(course) {
  if (String(course?.payment_type || '').toLowerCase() === 'monthly') {
    const monthlySessions = finiteNumber(course?.monthly_sessions);
    return monthlySessions != null && monthlySessions > 0
      ? `每月${monthlySessions}堂`
      : '月結';
  }

  const total = finiteNumber(course?.PackageID
    ? course?.package_total_sessions
    : course?.sessions_purchased);
  const remaining = finiteNumber(course?.PackageID
    ? course?.package_remaining_sessions
    : (course?.remaining_sessions ?? course?.RemainingSessions));

  if (total == null || total <= 0 || remaining == null) return '堂數待確認';
  return `${Math.max(0, remaining)}堂`;
}
