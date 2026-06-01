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

/**
 * 建課費用試算（鏡像後端 `EnrollmentService::store` 計價契約）。
 * 用於建課表單即時顯示「每堂／每小時 + 預估總額」，防止 rate_unit 單位混淆造成金額 ×2 錯帳
 * （見 docs/PRICING_CONTRACT.md、RateUnitChargeCalculationTest）。
 * - session 模式：Charge = round(price × sessions)（精確）
 * - hour 模式：Charge = round(price × 總時數)，總時數 = sessions × 平均每堂分鐘 / 60
 *   （uniform 時長為精確值；混合時長為預估，故 UI 標示「預估」）
 *
 * @param {object} p
 * @param {number} p.pricePerSession 單價（每堂或每小時，視 rateUnit）
 * @param {'session'|'hour'} p.rateUnit
 * @param {number} p.sessions 計費堂數
 * @param {number} [p.avgSessionMinutes] hour 模式用的平均每堂分鐘
 * @returns {{ charge:number, totalHours:number }}
 */
export const estimateCreateCharge = ({ pricePerSession, rateUnit, sessions, avgSessionMinutes } = {}) => {
  const price = Math.max(0, toNumber(pricePerSession) ?? 0);
  const n = Math.max(0, Math.round(toNumber(sessions) ?? 0));
  if (price <= 0 || n <= 0) return { charge: 0, totalHours: 0 };
  if (rateUnit === 'hour') {
    const mins = Math.max(0, toNumber(avgSessionMinutes) ?? 0);
    const totalHoursExact = (n * mins) / 60;
    return { charge: Math.round(price * totalHoursExact), totalHours: Math.round(totalHoursExact) };
  }
  return { charge: Math.round(price * n), totalHours: 0 };
};

export const getCourseTotalFee = (course) => {
  if (!course) return 0;
  const paymentType = String(course?.payment_type || '').toLowerCase();
  const purchased = Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);

  // AllTrue pricing contract:
  // session payment mode always treats Rate as per-session fee.
  if (paymentType === 'session') {
    const perSession = getPerSessionFee(course);
    return perSession * purchased;
  }

  const rateUnit = getRateUnit(course);
  const rate = toNumber(course.rate_per_30min ?? course.Rate) ?? 0;

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

