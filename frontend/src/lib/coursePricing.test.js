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

// Server Charge preserves negotiated/renewal discounts and session-charge deltas.
// Recomputing rate x sessions loses that agreement on the director course screen.
assert.equal(getCourseTotalFee({ ...sessionCourse, rate_per_30min: 1100, charge: 8000, effective_charge: 8000 }), 8000,
  'stored discounted contract8000 must not display rate-estimate8800 as its total');
assert.equal(getCourseTotalFee({ ...sessionCourse, rate_per_30min: 1000, charge: 8300 }), 8300,
  'persisted session-charge delta must not disappear from the course total');
assert.equal(getCourseTotalFee({ ...sessionCourse, Charge: 8000, charge: 0, effective_charge: 8800 }), 8800,
  'the server effective-charge projection outranks stale raw aliases');
assert.equal(getCourseTotalFee({ ...sessionCourse, charge: 0 }), 0, 'an explicit zero charge stays zero');
assert.equal(getCourseTotalFee({ ...hourlyCourse, charge: 10000 }), 10000, 'hourly discounted total stays the agreed amount');

assert.equal(getCourseTotalFee({ ...sessionCourse, charge: 'not-a-number' }), 6000, 'malformed optional charge keeps rate fallback');
assert.equal(getCourseTotalFee({ ...sessionCourse, charge: '' }), 6000, 'empty optional charge keeps rate fallback');
console.error('coursePricing.test: OK');
