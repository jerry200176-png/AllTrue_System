const asNonNegativeInteger = (value) => Math.max(0, Math.floor(Number(value) || 0));

/**
 * Keep progress presentation honest when the API returns incomplete or stale
 * counters. The UI may clamp a completed count to its known total, but it must
 * never invent a total from a missing response.
 */
export function getDailyWorkProgress(completed = 0, total = 0) {
  const normalizedTotal = asNonNegativeInteger(total);
  const normalizedCompleted = Math.min(asNonNegativeInteger(completed), normalizedTotal);
  const hasWork = normalizedTotal > 0;

  return {
    completed: normalizedCompleted,
    total: normalizedTotal,
    remaining: Math.max(0, normalizedTotal - normalizedCompleted),
    percent: hasWork ? Math.round((normalizedCompleted / normalizedTotal) * 100) : 0,
    hasWork,
    isComplete: hasWork && normalizedCompleted >= normalizedTotal,
  };
}
