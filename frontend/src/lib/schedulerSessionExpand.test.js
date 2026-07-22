/**
 * UniversalClassScheduler 摘要堂數 vs session_plan 展開一致性
 * Bug：同日多時段時「補登已上（堂）」曾用 dates.length（天數）誤當堂數。
 */
import assert from 'node:assert/strict';
import {
  countSessionsForDates,
  expandManualDatesToSessionPlan,
  expandYmdToSlotEntries,
  formatSessionsWithDays,
  sessionCountForYmd,
  weekdayOneToSevenFromYmd,
} from './schedulerSessionExpand.js';

// 2024-07-14 / 21 = 週日(7)；2024-07-15 = 週一(1)
assert.equal(weekdayOneToSevenFromYmd('2024-07-14'), 7);
assert.equal(weekdayOneToSevenFromYmd('2024-07-15'), 1);
assert.equal(weekdayOneToSevenFromYmd('2024-07-21'), 7);

const dualSundaySlots = [
  { day: 7, start_time: '10:00', subject: 'Math' },
  { day: 7, start_time: '14:00', subject: 'Math' },
  { day: 1, start_time: '16:00', subject: 'Math' },
];
const ctx = {
  dayTimeSlots: dualSundaySlots,
  defaultStartTime: '16:00',
  defaultSubject: 'Math',
};

// --- 必要案例：購買 16；補登 3 天其中兩天雙時段 → 5 堂（3 天）；剩餘 11 ---
const confirmedDates = ['2024-07-14', '2024-07-15', '2024-07-21'];
const confirmedSessionCount = countSessionsForDates(confirmedDates, ctx);
assert.equal(confirmedSessionCount, 5, '雙時段週日×2 + 單時段週一 = 5 堂');
assert.equal(confirmedDates.length, 3, '天數仍為 3');
assert.equal(
  formatSessionsWithDays(confirmedSessionCount, confirmedDates.length),
  '5 堂（3 天）',
  'C-lite 顯示格式'
);

const purchased = 16;
const remaining = purchased - confirmedSessionCount;
assert.equal(remaining, 11, '剩餘／預排 = 11');
assert.equal(confirmedSessionCount + remaining, purchased, '5 + 11 = 16');

// 摘要堂數 === session_plan kind=confirmed 筆數（同源 expand）
const plan = expandManualDatesToSessionPlan(confirmedDates, () => true, ctx);
const confirmedPlanRows = plan.filter((r) => r.kind === 'confirmed');
assert.equal(confirmedPlanRows.length, 5, 'session_plan confirmed 必須 5 筆');
assert.equal(
  confirmedSessionCount,
  confirmedPlanRows.length,
  'summary confirmed count 必須等於 session_plan confirmed 筆數'
);

// 雙時段補登日必須兩筆不同開始時間
const jul14 = confirmedPlanRows.filter((r) => r.session_date === '2024-07-14');
assert.equal(jul14.length, 2);
assert.deepEqual(
  jul14.map((r) => r.start_time).sort(),
  ['10:00', '14:00'],
  '同日雙時段 → 兩個不同 start_time'
);

// --- 每日單時段：天數 = 堂數（回歸）---
const singleSlotCtx = {
  dayTimeSlots: [
    { day: 7, start_time: '10:00', subject: 'Math' },
    { day: 1, start_time: '16:00', subject: 'Math' },
  ],
  defaultStartTime: '16:00',
  defaultSubject: 'Math',
};
assert.equal(countSessionsForDates(confirmedDates, singleSlotCtx), 3);
assert.equal(
  formatSessionsWithDays(3, 3),
  '3 堂（3 天）'
);
assert.equal(
  expandManualDatesToSessionPlan(confirmedDates, () => true, singleSlotCtx)
    .filter((r) => r.kind === 'confirmed').length,
  3
);

// --- 混合：過去補登 + 未來手動 +（自動預排由呼叫端 remaining 補）---
// 假設今天之後才算 future；此處只驗證手動段分類與總堂數可加總到購買數
const mixedConfirmed = ['2024-07-14']; // 2 堂
const mixedManualFuture = ['2024-07-22']; // 2024-07-22 週一 → 1 堂
assert.equal(weekdayOneToSevenFromYmd('2024-07-22'), 1);
const mixedConfirmedCount = countSessionsForDates(mixedConfirmed, ctx);
const mixedManualFutureCount = countSessionsForDates(mixedManualFuture, ctx);
assert.equal(mixedConfirmedCount, 2);
assert.equal(mixedManualFutureCount, 1);
assert.equal(
  formatSessionsWithDays(mixedManualFutureCount, mixedManualFuture.length),
  '1 堂（1 天）'
);

const mixedPlan = expandManualDatesToSessionPlan(
  [...mixedConfirmed, ...mixedManualFuture],
  (ymd) => mixedConfirmed.includes(ymd),
  ctx
);
assert.equal(mixedPlan.filter((r) => r.kind === 'confirmed').length, 2);
assert.equal(mixedPlan.filter((r) => r.kind === 'future').length, 1);

const autoFutureSessions = purchased - mixedConfirmedCount - mixedManualFutureCount;
assert.equal(autoFutureSessions, 13);
assert.equal(
  mixedConfirmedCount + mixedManualFutureCount + autoFutureSessions,
  purchased,
  '混合案例：補登 + 手動未來 + 自動預排 = 購買總堂數'
);

// --- 不在固定星期的手動日：fallback 1 堂 ---
assert.equal(sessionCountForYmd('2024-07-16', ctx), 1); // 週二，無 slot
assert.equal(expandYmdToSlotEntries('2024-07-16', ctx).length, 1);

// 舊 bug 對照：dates.length 不可當堂數
assert.notEqual(
  confirmedDates.length,
  confirmedSessionCount,
  '回歸鎖定：天數≠堂數（同日多時段）'
);

console.error('schedulerSessionExpand.test: OK');
