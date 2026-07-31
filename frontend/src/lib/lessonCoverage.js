/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING — create-time coverage preview.
 *
 * Mirrors backend App\Services\Scheduling\LessonEntitlementCoverageCalculator so the
 * figures a teacher sees while building a course match what the server will compute.
 * The server recomputes coverage itself and is the only authority; this exists so the
 * shortfall is visible BEFORE submitting, not as a 422 afterwards.
 *
 * `standardLessonMinutes` is always THAT COURSE's own value. There is no company-wide
 * lesson length: the same 180-minute session is worth 2.00 / 1.50 / 1.00
 * lesson-equivalents against a 90 / 120 / 180-minute standard, and 8 purchased units
 * is 720 / 960 / 1440 minutes. Anything assuming 120 is a bug.
 *
 * Minutes are integers throughout; lesson equivalents are emitted as fixed-precision
 * decimal STRINGS so no float rounding can turn into a billing figure.
 */

/** ROUND_HALF_UP(minutes / perUnit) to 2dp, as a string. Integer maths only. */
export function toDecimalString(minutes, perUnit) {
  const m = Number(minutes);
  const u = Number(perUnit);
  if (!Number.isFinite(m) || !Number.isFinite(u) || u < 1 || m < 0) return null;

  let whole = Math.floor(m / u);
  const remainder = m % u;
  // floor((2*(remainder*100) + u) / (2*u)) — half-up without touching floats.
  let hundredths = Math.floor((remainder * 200 + u) / (u * 2));
  if (hundredths >= 100) {
    whole += 1;
    hundredths -= 100;
  }

  return `${whole}.${String(hundredths).padStart(2, '0')}`;
}

/** Lesson-equivalents of `minutes` for a course whose standard lesson is `standardLessonMinutes`. */
export function lessonEquivalent(minutes, standardLessonMinutes) {
  return toDecimalString(minutes, standardLessonMinutes);
}

/** Hours, as a 2dp decimal string. */
export function hoursEquivalent(minutes) {
  return toDecimalString(minutes, 60);
}

/**
 * Allocate a purchased entitlement across scheduled occurrences, in order.
 *
 * @param {object} input
 * @param {number} input.purchasedStandardUnits entitlement in this course's standard units
 * @param {number} input.standardLessonMinutes  this course's own standard lesson length
 * @param {Array<{duration_minutes:number}>} input.occurrences in chronological order
 * @returns {object|null} null when inputs are not yet usable (e.g. no standard chosen)
 */
export function calculateCoverage({ purchasedStandardUnits, standardLessonMinutes, occurrences }) {
  const units = Number(purchasedStandardUnits);
  const standard = Number(standardLessonMinutes);
  if (!Number.isFinite(units) || units < 0) return null;
  if (!Number.isFinite(standard) || standard < 1) return null;

  const rows = Array.isArray(occurrences) ? occurrences : [];
  const entitlementMinutes = units * standard;

  let remaining = entitlementMinutes;
  let scheduledMinutes = 0;
  let coveredMinutes = 0;
  let uncoveredMinutes = 0;
  let fullyCovered = 0;
  let fullyUncovered = 0;
  let firstPartial = null;
  let partialCovered = 0;
  let partialUncovered = 0;
  let past = false;

  rows.forEach((row, index) => {
    const duration = Math.max(0, Number(row?.duration_minutes) || 0);
    if (duration <= 0) return;
    scheduledMinutes += duration;

    if (!past && remaining >= duration) {
      remaining -= duration;
      fullyCovered += 1;
      coveredMinutes += duration;
      return;
    }

    if (!past) {
      // Sequential fill is monotonic, so this transition happens at most once.
      past = true;
      if (remaining > 0) {
        firstPartial = index + 1;
        partialCovered = remaining;
        partialUncovered = duration - remaining;
        coveredMinutes += partialCovered;
        uncoveredMinutes += partialUncovered;
        remaining = 0;
        return;
      }
    }

    fullyUncovered += 1;
    uncoveredMinutes += duration;
  });

  return {
    standardLessonMinutes: standard,
    purchasedStandardUnits: units,
    entitlementMinutes,
    scheduledOccurrenceCount: rows.length,
    scheduledMinutes,
    fullyCoveredOccurrences: fullyCovered,
    firstPartiallyCoveredOccurrence: firstPartial,
    partialCoveredMinutes: partialCovered,
    partialUncoveredMinutes: partialUncovered,
    fullyUncoveredOccurrences: fullyUncovered,
    coveredMinutes,
    uncoveredMinutes,
    remainingEntitlementMinutes: remaining,
    requiresOverageConfirmation: scheduledMinutes > entitlementMinutes,
    entitlementHours: hoursEquivalent(entitlementMinutes),
    scheduledHours: hoursEquivalent(scheduledMinutes),
    uncoveredLessonEquivalent: lessonEquivalent(uncoveredMinutes, standard),
    remainingLessonEquivalent: lessonEquivalent(remaining, standard),
  };
}

/**
 * Human-readable preview lines for the create form. Returns [] when coverage is not
 * computable yet, so the caller can simply not render the block.
 */
export function describeCoverage(coverage) {
  if (!coverage) return [];

  const lines = [
    `標準一堂時長：${coverage.standardLessonMinutes} 分鐘`,
    `購買額度：${coverage.purchasedStandardUnits} 堂／${coverage.entitlementMinutes} 分鐘／${coverage.entitlementHours} 小時`,
    `預計排課：${coverage.scheduledOccurrenceCount} 次／${coverage.scheduledMinutes} 分鐘／${coverage.scheduledHours} 小時`,
    `可完整涵蓋：${coverage.fullyCoveredOccurrences} 次`,
  ];

  if (coverage.firstPartiallyCoveredOccurrence !== null) {
    lines.push(`第 ${coverage.firstPartiallyCoveredOccurrence} 次可涵蓋：${coverage.partialCoveredMinutes} 分鐘`);
  }
  if (coverage.uncoveredMinutes > 0) {
    lines.push(`尚缺：${coverage.uncoveredMinutes} 分鐘／${coverage.uncoveredLessonEquivalent} 堂`);
  } else {
    lines.push(`剩餘：${coverage.remainingEntitlementMinutes} 分鐘／${coverage.remainingLessonEquivalent} 堂`);
  }

  return lines;
}
