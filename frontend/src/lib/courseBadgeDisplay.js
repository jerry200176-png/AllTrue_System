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
  if (Number(course?.PackageID ?? course?.package_id ?? 0) > 0) return '共用方案';
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

/** Deduplicate repeated API pool metadata. Never allocate or derive member balances. */
export function sharedPackageSummaries(courses) {
  const packages = new Map();
  const read = (value) => value == null || value === '' || !Number.isFinite(Number(value)) || Number(value) < 0 ? null : Number(value);
  for (const course of courses) {
    const id = Number(course?.PackageID ?? course?.package_id ?? 0);
    if (!Number.isInteger(id) || id <= 0) continue;
    const values = {
      total: read(course.package_total_sessions),
      remaining: read(course.package_remaining_sessions),
      used: read(course.package_used_sessions),
    };
    if (!packages.has(id)) {
      packages.set(id, { id, name: course.PackageName || course.package_name || '共用方案', ...values });
    } else {
      const pool = packages.get(id);
      for (const field of ['total', 'remaining', 'used']) {
        if (pool[field] !== values[field]) pool[field] = null;
      }
    }
  }
  return [...packages.values()];
}
