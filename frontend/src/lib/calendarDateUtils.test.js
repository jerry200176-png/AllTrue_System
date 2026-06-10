// 純 Node 單元測試（#740 Step 1）— 不依賴 Vue test-utils / jsdom，極速運行。
// 鎖定自 SmartCalendar.vue 剝離的 6 個純日期工具的「行為等價」(Pure Move regression lock)，
// 並針對日曆經典翻車點：跨年、跨月、2/29 閏日、星期日。
import assert from 'node:assert/strict';
import {
  formatLocalDate,
  getNextWeekdayYmd,
  getMondayOfMonthWeek,
  toYmd,
  addDays,
  getWeekNumberOfDate,
} from './calendarDateUtils.js';

let passed = 0;
function test(name, fn) {
  fn();
  passed++;
  console.log(`  ✓ ${name}`);
}

console.log('calendarDateUtils — 純日期工具');

// ── formatLocalDate ───────────────────────────────────────────────
test('formatLocalDate：個位數月/日補零', () => {
  assert.equal(formatLocalDate(new Date(2025, 0, 5)), '2025-01-05');
});
test('formatLocalDate：閏日 2/29（不靠 UTC，避免時區掉一天）', () => {
  assert.equal(formatLocalDate(new Date(2024, 1, 29)), '2024-02-29');
});

// ── toYmd ─────────────────────────────────────────────────────────
test('toYmd：ISO 字串只取日期段', () => {
  assert.equal(toYmd('2026-03-15T08:30:00.000Z'), '2026-03-15');
});
test('toYmd：null/undefined → 空字串', () => {
  assert.equal(toYmd(null), '');
  assert.equal(toYmd(undefined), '');
});

// ── addDays（跨月 / 跨年 / 閏日）───────────────────────────────────
test('addDays：跨月 2026-01-31 +1 → 2026-02-01', () => {
  assert.equal(addDays('2026-01-31', 1), '2026-02-01');
});
test('addDays：跨年 2026-12-31 +1 → 2027-01-01', () => {
  assert.equal(addDays('2026-12-31', 1), '2027-01-01');
});
test('addDays：閏年 2024-02-28 +1 → 2024-02-29', () => {
  assert.equal(addDays('2024-02-28', 1), '2024-02-29');
});
test('addDays：閏年 2024-02-29 +1 → 2024-03-01', () => {
  assert.equal(addDays('2024-02-29', 1), '2024-03-01');
});
test('addDays：非閏年 2025-02-28 +1 → 2025-03-01（無 2/29）', () => {
  assert.equal(addDays('2025-02-28', 1), '2025-03-01');
});
test('addDays：跨年回退 2026-01-01 -1 → 2025-12-31', () => {
  assert.equal(addDays('2026-01-01', -1), '2025-12-31');
});

// ── getMondayOfMonthWeek（星期日起始月 / 跨月 / 閏月）──────────────
test('getMondayOfMonthWeek：2025-06 第1週（6/1 為星期日）→ 回退上月 2025-05-26', () => {
  // 月首為週日時 diff=-6，第1週週一落在前一個月，是經典翻車點
  assert.equal(getMondayOfMonthWeek(2025, 6, 1), '2025-05-26');
});
test('getMondayOfMonthWeek：2025-06 第2週 → 2025-06-02', () => {
  assert.equal(getMondayOfMonthWeek(2025, 6, 2), '2025-06-02');
});
test('getMondayOfMonthWeek：閏月 2024-02 第1週 → 2024-01-29', () => {
  assert.equal(getMondayOfMonthWeek(2024, 2, 1), '2024-01-29');
});

// ── getWeekNumberOfDate（星期日 / 跨年首日 / 閏日 / clamp 上限）────
test('getWeekNumberOfDate：星期日 2025-06-01 歸入其週一所在週（=1）', () => {
  // 週日 dow=0 → toMonday=-6，應與同週週一同屬第1週，不可溢位成第0或第6週
  assert.equal(getWeekNumberOfDate('2025-06-01'), 1);
});
test('getWeekNumberOfDate：跨年首日 2026-01-01 → 第1週', () => {
  assert.equal(getWeekNumberOfDate('2026-01-01'), 1);
});
test('getWeekNumberOfDate：閏日 2024-02-29 → clamp 至第5週', () => {
  assert.equal(getWeekNumberOfDate('2024-02-29'), 5);
});
test('getWeekNumberOfDate：月底 2025-05-31 → clamp 至第5週（上限保護）', () => {
  assert.equal(getWeekNumberOfDate('2025-05-31'), 5);
});

// ── getNextWeekdayYmd（與「今天」相關 → 測不變量，非固定值）────────
test('getNextWeekdayYmd：輸出為合法 YYYY-MM-DD', () => {
  assert.match(getNextWeekdayYmd(3), /^\d{4}-\d{2}-\d{2}$/);
});
test('getNextWeekdayYmd：結果星期幾等於指定 dow（含星期日 dow=7）', () => {
  for (const dow of [1, 2, 3, 4, 5, 6, 7]) {
    const ymd = getNextWeekdayYmd(dow);
    const d = new Date(ymd + 'T12:00:00');
    const got = d.getDay() === 0 ? 7 : d.getDay();
    assert.equal(got, dow, `dow=${dow} 得到 ${ymd}（星期 ${got}）`);
  }
});
test('getNextWeekdayYmd：永遠落在未來（diff<=0 時 +7），不回傳今天或過去', () => {
  const todayY = formatLocalDate(new Date());
  for (const dow of [1, 2, 3, 4, 5, 6, 7]) {
    assert.ok(getNextWeekdayYmd(dow) > todayY, `dow=${dow} 應 > 今天 ${todayY}`);
  }
});

console.log(`\ncalendarDateUtils.test.js — ${passed} passed ✅`);
