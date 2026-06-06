// 純 Node 單元測試（#740 Step 2）— 無 Vue test-utils / jsdom。
// 鎖定自 SmartCalendar.vue 剝離的格式化工具行為等價，加固時間正規化/結束時間邊界。
import assert from 'node:assert/strict';
import {
  classTypeLabel,
  dayLabel,
  dayOfWeekFromDate,
  getWeekLabel,
  parseHour,
  normalizeTimeTo30,
  computeEndTime,
  TIME_STEP_MINUTES,
} from './calendarFormat.js';

let passed = 0;
function test(name, fn) {
  fn();
  passed++;
  console.log(`  ✓ ${name}`);
}

console.log('calendarFormat — 純格式化工具');

// ── classTypeLabel ────────────────────────────────────────────────
test('classTypeLabel：已知型別中譯', () => {
  assert.equal(classTypeLabel('one_on_one'), '一對一');
  assert.equal(classTypeLabel('one_on_three'), '一對三');
  assert.equal(classTypeLabel('trial'), '試聽');
});
test('classTypeLabel：未知型別原樣回傳', () => {
  assert.equal(classTypeLabel('zzz'), 'zzz');
});

// ── dayLabel ──────────────────────────────────────────────────────
test('dayLabel：1–7 對應週一～週日', () => {
  assert.equal(dayLabel(1), '週一');
  assert.equal(dayLabel(7), '週日');
});
test('dayLabel：0 / 超出範圍 → 空字串', () => {
  assert.equal(dayLabel(0), '');
  assert.equal(dayLabel(8), '');
});

// ── dayOfWeekFromDate ─────────────────────────────────────────────
test('dayOfWeekFromDate：空值 → 1（週一預設）', () => {
  assert.equal(dayOfWeekFromDate(''), 1);
});
test('dayOfWeekFromDate：星期日歸 7（非 0）', () => {
  assert.equal(dayOfWeekFromDate('2025-06-01'), 7);
});

// ── getWeekLabel ──────────────────────────────────────────────────
test('getWeekLabel：部分週次 → 第1,3週', () => {
  assert.equal(getWeekLabel([1, 3]), '第1,3週');
});
test('getWeekLabel：空 / 全 5 週 → 空字串（代表每週，不顯示）', () => {
  assert.equal(getWeekLabel([]), '');
  assert.equal(getWeekLabel(null), '');
  assert.equal(getWeekLabel([1, 2, 3, 4, 5]), '');
});

// ── parseHour ─────────────────────────────────────────────────────
test('parseHour：補零與單碼小時皆取整點數', () => {
  assert.equal(parseHour('09:30'), 9);
  assert.equal(parseHour('8:00'), 8);
});
test('parseHour：空值 → 0', () => {
  assert.equal(parseHour(''), 0);
  assert.equal(parseHour(null), 0);
});

// ── normalizeTimeTo30（CEO 指定加固：單碼時數 / 跨午夜 24:00 / 空值）──
test('normalizeTimeTo30：單碼時數 9:00 → 補零 09:00', () => {
  assert.equal(normalizeTimeTo30('9:00'), '09:00');
});
test('normalizeTimeTo30：四捨五入到 30 分（08:14→08:00、08:15→08:30）', () => {
  assert.equal(normalizeTimeTo30('08:14'), '08:00');
  assert.equal(normalizeTimeTo30('08:15'), '08:30');
});
test('normalizeTimeTo30：空值 / null → 預設 08:00', () => {
  assert.equal(normalizeTimeTo30(''), '08:00');
  assert.equal(normalizeTimeTo30(null), '08:00');
});
test('normalizeTimeTo30：跨午夜 24:00 → clamp 至 23:00（小時上限保護）', () => {
  assert.equal(normalizeTimeTo30('24:00'), '23:00');
});
test('normalizeTimeTo30：23:50 進位後 clamp → 23:00', () => {
  assert.equal(normalizeTimeTo30('23:50'), '23:00');
});
test('normalizeTimeTo30：步進常數為 30 分', () => {
  assert.equal(TIME_STEP_MINUTES, 30);
});

// ── computeEndTime（CEO 指定加固：單碼 / 跨午夜 / 空值 / 預設時長）──
test('computeEndTime：空開始時間 → --:--', () => {
  assert.equal(computeEndTime(''), '--:--');
  assert.equal(computeEndTime(null), '--:--');
});
test('computeEndTime：單碼時數 9:00 + 2hr → 11:00', () => {
  assert.equal(computeEndTime('9:00', 2), '11:00');
});
test('computeEndTime：跨午夜 23:00 + 2hr → clamp 至 23:00', () => {
  assert.equal(computeEndTime('23:00', 2), '23:00');
});
test('computeEndTime：未給時長 → 預設 2hr（08:00 → 10:00）', () => {
  assert.equal(computeEndTime('08:00'), '10:00');
});
test('computeEndTime：小數時長 08:00 + 1.5hr → 09:30', () => {
  assert.equal(computeEndTime('08:00', 1.5), '09:30');
});

console.log(`\ncalendarFormat.test.js — ${passed} passed ✅`);
