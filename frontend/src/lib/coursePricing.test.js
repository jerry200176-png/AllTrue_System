import assert from 'node:assert/strict';
import { estimateCreateCharge } from './coursePricing.js';

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

console.error('coursePricing.test: OK');
