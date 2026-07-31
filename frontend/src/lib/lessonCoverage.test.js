import assert from 'node:assert/strict';
import {
  toDecimalString,
  lessonEquivalent,
  hoursEquivalent,
  calculateCoverage,
  describeCoverage,
} from './lessonCoverage.js';

// ─────────────────────────────────────────────────────────────────────────────
// 每門課有自己的「標準一堂」。同樣的 180 分鐘，換算結果由「該課程」的標準決定。
// 沒有任何全公司統一的堂長；任何寫死 120 的假設都是 bug。
// ─────────────────────────────────────────────────────────────────────────────

assert.equal(lessonEquivalent(180, 120), '1.50', '標準 120／實際 180 → 1.50 堂');
assert.equal(lessonEquivalent(180, 180), '1.00', '標準 180／實際 180 → 1.00 堂（同一份時長，換算成 1 堂）');
assert.equal(lessonEquivalent(180, 90), '2.00', '標準 90／實際 180 → 2.00 堂');
assert.equal(lessonEquivalent(180, 60), '3.00', '標準 60／實際 180 → 3.00 堂');

// 8 個標準單位換算成分鐘，也完全跟著該課程的標準走
assert.equal(8 * 120, 960, '8 堂 × 標準 120 = 960 分鐘');
assert.equal(8 * 180, 1440, '8 堂 × 標準 180 = 1440 分鐘');
assert.equal(8 * 90, 720, '8 堂 × 標準 90 = 720 分鐘');

// ── toDecimalString：整數運算 + ROUND_HALF_UP，且輸出必為字串 ────────────────

assert.equal(toDecimalString(0, 120), '0.00', '0 分鐘 → 0.00');
assert.equal(toDecimalString(60, 120), '0.50', '半堂');
assert.equal(toDecimalString(30, 120), '0.25');
assert.equal(toDecimalString(100, 120), '0.83', '5/6 = 0.8333… → 0.83（無條件捨去到 0.83）');
assert.equal(toDecimalString(20, 120), '0.17', '1/6 = 0.1666… → 0.17（進位）');
assert.equal(toDecimalString(1, 200), '0.01', '恰好 0.005 → half-up 進位到 0.01');
assert.equal(toDecimalString(3, 200), '0.02', '0.015 → half-up 進位到 0.02');
assert.equal(toDecimalString(199, 200), '1.00', '進位跨越整數邊界時，整數位要 +1、小數位歸零');

assert.equal(typeof toDecimalString(180, 120), 'string', '換算結果必須是字串，不得是浮點數');
assert.notEqual(typeof toDecimalString(180, 120), 'number', '堂數不得以 binary float 形式外流');

// 不可用的輸入回傳 null（讓 UI 直接不要渲染，而不是顯示 NaN）
assert.equal(toDecimalString(120, 0), null, '標準堂長 0 不可換算');
assert.equal(toDecimalString(-1, 120), null, '負分鐘不可換算');
assert.equal(toDecimalString(120, Number.NaN), null);

// hoursEquivalent 就是以 60 分鐘為單位的同一套換算
assert.equal(hoursEquivalent(960), '16.00', '960 分鐘 = 16 小時');
assert.equal(hoursEquivalent(1080), '18.00', '1080 分鐘 = 18 小時');
assert.equal(hoursEquivalent(90), '1.50');

// ─────────────────────────────────────────────────────────────────────────────
// 驗收案例：買 8 個標準單位（標準 120 分）、排 6 次 180 分鐘的課
//   額度 = 8 × 120 = 960 分鐘；排課 = 6 × 180 = 1080 分鐘
//   前 5 次完整涵蓋（900 分），第 6 次只剩 60 分可用、缺 120 分
// ─────────────────────────────────────────────────────────────────────────────

const sixOf = (minutes) => Array.from({ length: 6 }, () => ({ duration_minutes: minutes }));

const acceptance = calculateCoverage({
  purchasedStandardUnits: 8,
  standardLessonMinutes: 120,
  occurrences: sixOf(180),
});

assert.equal(acceptance.entitlementMinutes, 960, '購買額度以分鐘為準：8 × 120');
assert.equal(acceptance.scheduledMinutes, 1080, '排課總分鐘：6 × 180');
assert.equal(acceptance.fullyCoveredOccurrences, 5, '前 5 次可完整涵蓋');
assert.equal(acceptance.firstPartiallyCoveredOccurrence, 6, '第 6 次是部分涵蓋的那一次');
assert.equal(acceptance.partialCoveredMinutes, 60, '第 6 次只剩 60 分鐘額度');
assert.equal(acceptance.partialUncoveredMinutes, 120, '第 6 次尚缺 120 分鐘');
assert.equal(acceptance.uncoveredMinutes, 120, '整體缺口 120 分鐘');
assert.equal(acceptance.uncoveredLessonEquivalent, '1.00', '缺口換算成該課程的堂數 = 1.00 堂');
assert.equal(acceptance.remainingEntitlementMinutes, 0, '額度已用盡');
assert.equal(acceptance.requiresOverageConfirmation, true, '超額 → 必須顯示確認');
assert.equal(acceptance.entitlementHours, '16.00');
assert.equal(acceptance.scheduledHours, '18.00');
assert.equal(acceptance.fullyUncoveredOccurrences, 0, '第 6 次算部分涵蓋，不是完全未涵蓋');

// 同一份排課，只是把「該課程的標準堂長」改成 180 → 從超額變成有餘額
const raisedStandard = calculateCoverage({
  purchasedStandardUnits: 8,
  standardLessonMinutes: 180,
  occurrences: sixOf(180),
});

assert.equal(raisedStandard.entitlementMinutes, 1440, '8 × 180 = 1440 分鐘');
assert.equal(raisedStandard.fullyCoveredOccurrences, 6, '6 次全部涵蓋');
assert.equal(raisedStandard.firstPartiallyCoveredOccurrence, null, '沒有部分涵蓋的一次');
assert.equal(raisedStandard.uncoveredMinutes, 0, '沒有缺口');
assert.equal(raisedStandard.remainingEntitlementMinutes, 360, '尚餘 360 分鐘');
assert.equal(raisedStandard.remainingLessonEquivalent, '2.00', '360 分鐘 = 該課程的 2.00 堂');
assert.equal(raisedStandard.requiresOverageConfirmation, false, '未超額 → 不需確認');

// 同一份排課，標準降成 90 → 缺口更大
const loweredStandard = calculateCoverage({
  purchasedStandardUnits: 8,
  standardLessonMinutes: 90,
  occurrences: sixOf(180),
});

assert.equal(loweredStandard.entitlementMinutes, 720, '8 × 90 = 720 分鐘');
assert.equal(loweredStandard.fullyCoveredOccurrences, 4, '720 / 180 = 剛好 4 次');
assert.equal(loweredStandard.firstPartiallyCoveredOccurrence, null, '第 4 次剛好用完，第 5 次一分鐘都沒有');
assert.equal(loweredStandard.fullyUncoveredOccurrences, 2, '第 5、6 次完全未涵蓋');
assert.equal(loweredStandard.uncoveredMinutes, 360);
assert.equal(loweredStandard.uncoveredLessonEquivalent, '4.00', '360 分鐘 ÷ 標準 90 = 4.00 堂');
assert.equal(loweredStandard.requiresOverageConfirmation, true);

// 三種標準、同一份排課 → 三種結果。證明沒有任何固定 120 的內建假設。
assert.notEqual(acceptance.uncoveredMinutes, raisedStandard.uncoveredMinutes);
assert.notEqual(acceptance.uncoveredMinutes, loweredStandard.uncoveredMinutes);

// ── 邊界：剛好用完不算超額 ───────────────────────────────────────────────────

const exact = calculateCoverage({
  purchasedStandardUnits: 3,
  standardLessonMinutes: 120,
  occurrences: [{ duration_minutes: 180 }, { duration_minutes: 180 }],
});
assert.equal(exact.entitlementMinutes, 360);
assert.equal(exact.scheduledMinutes, 360);
assert.equal(exact.fullyCoveredOccurrences, 2);
assert.equal(exact.remainingEntitlementMinutes, 0);
assert.equal(exact.requiresOverageConfirmation, false, '剛好用完（相等）不算超額');

// ── 依序分配：長度不同的堂次，順序會影響結果 ─────────────────────────────────

const mixed = calculateCoverage({
  purchasedStandardUnits: 2,
  standardLessonMinutes: 120, // 240 分鐘額度
  occurrences: [{ duration_minutes: 180 }, { duration_minutes: 120 }, { duration_minutes: 60 }],
});
assert.equal(mixed.fullyCoveredOccurrences, 1, '第 1 次 180 分鐘吃掉額度後只剩 60');
assert.equal(mixed.firstPartiallyCoveredOccurrence, 2, '第 2 次只涵蓋 60 分鐘');
assert.equal(mixed.partialCoveredMinutes, 60);
assert.equal(mixed.partialUncoveredMinutes, 60);
assert.equal(mixed.fullyUncoveredOccurrences, 1, '第 3 次完全未涵蓋');
assert.equal(mixed.uncoveredMinutes, 120, '60（第 2 次餘） + 60（第 3 次）');

// v1 不做跨期借用／自動拆堂：額度用完之後，後面的堂次一律不再回頭補
const noBorrowing = calculateCoverage({
  purchasedStandardUnits: 1,
  standardLessonMinutes: 120,
  occurrences: [{ duration_minutes: 180 }, { duration_minutes: 30 }],
});
assert.equal(noBorrowing.firstPartiallyCoveredOccurrence, 1);
assert.equal(noBorrowing.partialCoveredMinutes, 120);
assert.equal(
  noBorrowing.fullyUncoveredOccurrences,
  1,
  '第 2 次雖然只有 30 分鐘，也不會被前面用剩的額度回填（額度已為 0）'
);

// ── 尚未可算時回 null，讓表單直接不渲染預覽 ─────────────────────────────────

assert.equal(
  calculateCoverage({ purchasedStandardUnits: 8, standardLessonMinutes: null, occurrences: [] }),
  null,
  '還沒選標準堂長 → 不算、不顯示'
);
assert.equal(
  calculateCoverage({ purchasedStandardUnits: 8, standardLessonMinutes: 0, occurrences: [] }),
  null,
  '標準堂長 0 不可作為除數'
);
assert.equal(calculateCoverage({ purchasedStandardUnits: -1, standardLessonMinutes: 120, occurrences: [] }), null);

const noOccurrences = calculateCoverage({
  purchasedStandardUnits: 8,
  standardLessonMinutes: 120,
  occurrences: [],
});
assert.equal(noOccurrences.scheduledMinutes, 0, '尚未排課 → 排課分鐘 0');
assert.equal(noOccurrences.remainingEntitlementMinutes, 960, '額度原封不動');
assert.equal(noOccurrences.requiresOverageConfirmation, false);

// 零長度／壞資料的堂次不計入排課分鐘，也不會被算成一次涵蓋
const dirty = calculateCoverage({
  purchasedStandardUnits: 1,
  standardLessonMinutes: 120,
  occurrences: [{ duration_minutes: 0 }, { duration_minutes: null }, { duration_minutes: 120 }],
});
assert.equal(dirty.scheduledMinutes, 120);
assert.equal(dirty.fullyCoveredOccurrences, 1);
assert.equal(dirty.remainingEntitlementMinutes, 0);

// ── describeCoverage：預覽文案 ───────────────────────────────────────────────

assert.deepEqual(describeCoverage(null), [], '算不出來就不給文案');

const lines = describeCoverage(acceptance);
assert.ok(lines.some((l) => l.includes('標準一堂時長：120 分鐘')), '文案要說出「這門課的」標準堂長');
assert.ok(lines.some((l) => l.includes('960 分鐘')), '文案要出現購買分鐘');
assert.ok(lines.some((l) => l.includes('1080 分鐘')), '文案要出現排課分鐘');
assert.ok(lines.some((l) => l.includes('可完整涵蓋：5 次')), '文案要出現完整涵蓋次數');
assert.ok(lines.some((l) => l.includes('第 6 次可涵蓋：60 分鐘')), '文案要點名部分涵蓋的那一次');
assert.ok(lines.some((l) => l.includes('尚缺：120 分鐘／1.00 堂')), '文案要同時給分鐘與堂數');

const surplusLines = describeCoverage(raisedStandard);
assert.ok(surplusLines.some((l) => l.includes('剩餘：360 分鐘／2.00 堂')), '沒缺口時改顯示剩餘');
assert.ok(!surplusLines.some((l) => l.includes('尚缺')), '沒缺口時不得出現「尚缺」');

console.error('lessonCoverage.test: OK');
