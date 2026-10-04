import assert from 'node:assert/strict';
import {
  estimateCreateCharge,
  getCourseTotalFee,
  getRateUnitDisplayLabel,
} from './coursePricing.js';

// session 模式：Charge = round(price × sessions)
assert.deepEqual(
  estimateCreateCharge({ pricePerSession: 1100, rateUnit: 'session', sessions: 8 }),
  { charge: 8800, totalHours: 0 },
  '每堂 1100 × 8 堂 = 8,800'
);

// hour 模式：Charge = round(price × 總時數)；總時數 = sessions × 平均分鐘 / 60
// 對應 RateUnitChargeCalculationTest：1100/時 × (8 堂 × 2 小時) = 17,600
assert.deepEqual(
  estimateCreateCharge({ pricePerSession: 1100, rateUnit: 'hour', sessions: 8, avgSessionMinutes: 120 }),
  { charge: 17600, totalHours: 16 },
  '每小時 1100 × 16 小時 = 17,600（防 ×2 錯帳的核心對照）'
);

// 同一單價、同一堂數：session 與 hour 模式金額不同 → 證明 UI 顯示計價方式可防混淆
const asSession = estimateCreateCharge({ pricePerSession: 1100, rateUnit: 'session', sessions: 8 });
const asHour = estimateCreateCharge({ pricePerSession: 1100, rateUnit: 'hour', sessions: 8, avgSessionMinutes: 120 });
assert.notEqual(asSession.charge, asHour.charge, 'session 與 hour 計價結果應不同（8,800 vs 17,600）');

// 90 分鐘半堂時長（hour 模式）：1000 × (4 堂 × 1.5 小時)=6 小時 → 6000
assert.deepEqual(
  estimateCreateCharge({ pricePerSession: 1000, rateUnit: 'hour', sessions: 4, avgSessionMinutes: 90 }),
  { charge: 6000, totalHours: 6 },
  '每小時 1000 × 6 小時 = 6,000'
);

// 四捨五入：總時數非整數時，charge 在乘積後一次 round
assert.equal(
  estimateCreateCharge({ pricePerSession: 1000, rateUnit: 'hour', sessions: 3, avgSessionMinutes: 50 }).charge,
  2500, // 3 × 50/60 = 2.5h → 1000 × 2.5 = 2500
  'hour 模式總時數 2.5 小時 × 1000 = 2,500'
);

// 防呆：價格或堂數 ≤ 0 回 0
assert.deepEqual(
  estimateCreateCharge({ pricePerSession: 0, rateUnit: 'session', sessions: 8 }),
  { charge: 0, totalHours: 0 },
  '價格為 0 回傳 0'
);
assert.deepEqual(
  estimateCreateCharge({ pricePerSession: 1000, rateUnit: 'session', sessions: 0 }),
  { charge: 0, totalHours: 0 },
  '堂數為 0 回傳 0'
);

// Existing course lookup row: payment cadence is session, but explicit price
// unit is hourly. The total must use persisted course hours, not session count.
const hourlyCourse = {
  payment_type: 'session',
  rate_unit: 'hour',
  rate_per_30min: 750,
  sessions_purchased: 8,
  total_hours: 16,
  duration_hours: 2,
};
assert.equal(getRateUnitDisplayLabel(hourlyCourse), '每小時');
assert.equal(getCourseTotalFee(hourlyCourse), 12000, '每小時 750 × 16 小時 = 12,000');

const sessionCourse = {
  payment_type: 'session',
  rate_unit: 'session',
  rate_per_30min: 750,
  sessions_purchased: 8,
  total_hours: 16,
  duration_hours: 2,
};
assert.equal(getRateUnitDisplayLabel(sessionCourse), '每堂');
assert.equal(getCourseTotalFee(sessionCourse), 6000, '每堂 750 × 8 堂 = 6,000');

// in-app #349: a transaction discount is allocated into Charge; Rate stays the list price.
const discountedCourse = { payment_type: 'session', Rate: 1800, SessionCount: 4, Charge: 5400, pricing_snapshot: { discount_amount: 1800 } };
assert.equal(getCourseTotalFee(discountedCourse), 5400, 'discounted total, not 1800 × 4 = 7200');
assert.equal(getCourseTotalFee({ ...discountedCourse, pricing_snapshot: null }), 7200, 'no discount → Rate × sessions');
const discountedMonthly = { payment_type: 'monthly', Rate: 750, rate_unit: 'session', SessionCount: 4, Charge: 2000, pricing_snapshot: { discount_amount: 1000 }, day_time_slots: [{ day: 1, start_time: '18:00', duration_hours: 2 }] };
assert.equal(getCourseTotalFee(discountedMonthly), 2000, 'monthly history: discounted Charge, not the Rate-derived monthly fee');
assert.notEqual(getCourseTotalFee({ ...discountedMonthly, pricing_snapshot: null }), 2000, 'no discount → monthly fee from Rate');
assert.equal(getCourseTotalFee({ ...discountedCourse, effective_total: 4800 }), 4800, 'effective_total (amendment) beats frozen discounted Charge');
assert.equal(getCourseTotalFee({ ...discountedCourse, effective_total: null }), 5400, 'null effective_total → discounted Charge');

console.error('coursePricing.test: OK');
