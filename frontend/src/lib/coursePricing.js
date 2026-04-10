const toNumber = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
};

export const calcSessionFeeFromRate = (ratePer30Min, durationHours) => {
  const rate = toNumber(ratePer30Min);
  if (rate == null) return 0;
  return Math.max(0, Math.round(rate));
};

export const calcRatePer30MinFromSessionFee = (sessionFee, durationHours) => {
  const fee = toNumber(sessionFee);
  if (fee == null) return 0;
  return Math.max(0, Math.round(fee));
};

/**
 * Single Source of Truth for per-session fee display across pages.
 * When rate_unit='hour', Rate is per-hour; label should reflect this.
 * Priority:
 * 1) rate_per_30min (treat as per-session fee for session mode, per-hour for hour mode)
 * 2) total amount / purchased sessions (fallback for legacy rows)
 */
export const getPerSessionFee = (course) => {
  if (!course) return 0;

  const fromRate = calcSessionFeeFromRate(course.rate_per_30min ?? course.Rate, null);
  if (fromRate > 0) return fromRate;

  const total = toNumber(
    course.total_price
      ?? course.Charge
      ?? course.charge
      ?? course.Pay
      ?? course.pay
  );
  const purchased = toNumber(course.sessions_purchased ?? course.SessionCount);
  if (total != null && purchased != null && purchased > 0) {
    return Math.max(0, Math.round(total / purchased));
  }

  return 0;
};

export const getRateUnit = (course) => {
  return (course?.rate_unit || 'session');
};

export const getRateLabel = (course) => {
  return getRateUnit(course) === 'hour' ? '每小時費用' : '單堂費用';
};

export const getCourseTotalFee = (course) => {
  if (!course) return 0;
  const rateUnit = getRateUnit(course);
  const rate = toNumber(course.rate_per_30min ?? course.Rate) ?? 0;
  const purchased = Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);

  if (rateUnit === 'hour' && rate > 0) {
    const slots = Array.isArray(course.day_time_slots) ? course.day_time_slots : [];
    const globalDur = Number(course.duration_hours ?? 2);
    if (slots.length > 0) {
      let totalHours = 0;
      const slotCount = slots.length;
      for (const slot of slots) {
        totalHours += Number(slot.duration_hours ?? globalDur);
      }
      const avgHoursPerSession = totalHours / slotCount;
      return Math.round(rate * avgHoursPerSession * purchased);
    }
    return Math.round(rate * globalDur * purchased);
  }

  const perSession = getPerSessionFee(course);
  return perSession * purchased;
};

