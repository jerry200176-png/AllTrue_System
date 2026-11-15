import assert from 'node:assert/strict';
import {
  eventKindHint,
  eventKindLabel,
  eventSubtitle,
  formatMoney,
  formatSubjects,
  formatSubjectUnits,
  weightedBonusFormula,
  formatDifference,
  inputRoster,
  attentionItems,
  cashAdjustments,
  multiplierPendingNote,
  multiplierValue,
  oneToThreeFormula,
  hasNoLessons,
  multiplierBreakdown,
  teacherSummaryLine,
  recalculationSummary,
  weekExplanation,
  recalculationText,
  monthWindow,
} from './teacherEligibilityDisplay.js';

assert.match(eventKindHint('holiday'), /課程管理/);
assert.match(eventKindHint('holiday'), /連假批次請假/);
assert.match(eventKindHint('holiday'), /自動/);
assert.match(eventKindHint('leave'), /自動/);
assert.equal(eventKindLabel('holiday'), '假日');
assert.equal(eventSubtitle({ event_type: 'holiday', event_date: '2026-08-31' }, '王老師'), '2026-08-31｜全分校');
assert.equal(eventSubtitle({ event_type: 'leave', event_date: '2026-08-31' }, '王老師'), '2026-08-31｜王老師');
assert.equal(formatMoney(35287), '$35,287');
assert.equal(formatSubjects(5.625), '5.625');
assert.equal(weightedBonusFormula({
  subject_count_bonus: 0,
  one_to_three_bonus: 450,
  multiplier_pct: 114.5,
  weighted_bonus_amount: 515.25,
}), '($0 + $450) × 114.5% = $515');
assert.equal(weightedBonusFormula({
  subject_count_bonus: 0,
  one_to_three_bonus: 200,
  multiplier_pct: 105,
  weighted_bonus_amount: 2000,
  weighted_bonus_before_guarantee: 210,
  new_hire_guarantee_applied: true,
}), '($0 + $200) × 105% = $210，新進保底 $2,000');
assert.equal(formatDifference(1200), '多 $1,200');
assert.equal(formatDifference(-300), '少 $300');
assert.equal(formatDifference(0), '相同');
assert.equal(formatDifference(null), '無法比較');
assert.equal(recalculationSummary([{ recalculated: { difference: 1200 } }, { recalculated: { difference: -300 } }, { recalculated: { difference: 0 } }]),
  '照現在的算法重算，2 位老師金額不同，合計多 $900。鎖定的數字沒有變。');
// A teacher who could not be compared is counted, never passed over: no "everyone is the same" on a partial comparison.
assert.equal(recalculationSummary([{ recalculated: { difference: 0 } }, { recalculated: null }, { recalculated: { difference: null } }]),
  '照現在的算法重算，比較得到的 1 位老師金額都和鎖定時相同。另有 2 位無法比較（已不在這個分校，或現在還有待確認項目）。');
assert.equal(recalculationSummary([{ recalculated: { difference: 500 } }, { recalculated: null }]),
  '照現在的算法重算，1 位老師金額不同，合計多 $500。另有 1 位無法比較（已不在這個分校，或現在還有待確認項目）。鎖定的數字沒有變。');
assert.equal(recalculationSummary([{ recalculated: null }]), '照現在的算法重算：另有 1 位無法比較（已不在這個分校，或現在還有待確認項目）。');
assert.equal(recalculationSummary([{}]), '');
assert.equal(recalculationText({ recalculated: { calculated_payout: 36500, difference: 500 } }), '現在的算法 $36,500（多 $500）');
assert.equal(recalculationText({ recalculated: { calculated_payout: 1200, difference: null } }), '現在的算法：還有待確認項目，暫不比較');
assert.equal(recalculationText({ recalculated: null }), '現在的算法：無法比較（已不在這個分校）');
assert.equal(recalculationText({}), '');
// A short week says how far short it is, what each day held and what was left out.
assert.deepEqual(weekExplanation({
  week_start: '2026-10-05', week_end: '2026-10-11', total_segments: 12.5, segment_threshold: 16, meets_16_segments: false, finished: true,
  closure_dates: [], daily_hours: { '2026-10-05': 4, '2026-10-06': 5.5, '2026-10-11': 15.5 }, uncounted: { cancelled: 3, moved: 2 },
}), {
  verdict: '12.5 段・未達（差 3.5 段）',
  detail: '每天排課時數：一 4、二 5.5、三 0、四 0、五 0、六 0、日 15.5。另有 3 堂取消、2 堂改期（算在改到的那一天），不算在內',
});
assert.equal(weekExplanation({ week_start: '2026-10-05', total_segments: 18, meets_16_segments: true, finished: true }).verdict, '18 段・達標');
assert.equal(weekExplanation({ week_start: '2026-10-05', total_segments: 3, meets_16_segments: true, closure_dates: ['2026-10-10'] }).verdict, '3 段・停課週不列入');
assert.equal(weekExplanation({ week_start: '2026-11-30', total_segments: 2, meets_16_segments: false, finished: false }).verdict, '2 段・還沒結束，先不判定');
assert.equal(weekExplanation({ week_start: '2026-10-05', total_segments: 3 }).detail, '每天排課時數：一 0、二 0、三 0、四 0、五 0、六 0、日 0');
assert.equal(weekExplanation({ week_start: '2026-10-05', total_segments: 3, closure_dates: ['2026-10-10'], near_closures: [{ date: '2026-10-09', total: 120, open: 2 }] }).detail,
  '每天排課時數：一 0、二 0、三 0、四 0、五 0、六 0、日 0');
// A week that crosses a month end still finds its days.
assert.equal(weekExplanation({ week_start: '2026-11-30', daily_hours: { '2026-11-30': 2, '2026-12-06': 8 } }).detail, '每天排課時數：一 2、二 0、三 0、四 0、五 0、六 0、日 8');
assert.equal(weekExplanation({ week_start: '2026-10-05', total_segments: 3, near_closures: [{ date: '2026-10-10', total: 120, open: 2 }] }).detail,
  '每天排課時數：一 0、二 0、三 0、四 0、五 0、六 0、日 0。10/10 全公司 120 堂課有 118 堂停課，還有 2 堂照常上課，所以這一週沒有當成停課週');
assert.deepEqual(inputRoster([{ teacher_id: 1, teacher_name: '甲' }], [{ teacher_id: 2, teacher_name: '乙', campus_id: 9, campus_name: '別的分校' }]).map((t) => [t.teacher_id, t.teacher_name]),
  [[1, '甲'], [2, '乙']]);
assert.deepEqual(inputRoster(undefined, undefined), []);
assert.deepEqual(attentionItems([
  { teacher_id: 1, teacher_name: '甲', pending_items: [{ code: 'holiday_16_hours', label: '假日16小時倍率', reason: '倍率調整尚未完成主任確認及總部核准。' }] },
  { teacher_id: 2, teacher_name: '乙', review_required: true },
  { teacher_id: 3, teacher_name: '丙', settlement: { pending_items: [{ code: 'cash_adjustments', label: '現金加扣款' }] } },
  { teacher_id: 4, teacher_name: '丁' },
]), [
  { key: '1-holiday_16_hours', teacherName: '甲', label: '假日16小時倍率', reason: '倍率調整尚未完成主任確認及總部核准。' },
  { key: '2-review', teacherName: '乙', label: '待人工確認', reason: '有項目還沒確認，請看下方的資料補登與審核。' },
  { key: '3-cash_adjustments', teacherName: '丙', label: '現金加扣款', reason: '資料尚待確認。' },
]);
// One line per teacher: what the amount rests on, then the first open item.
const weekly = (metrics) => ({ weekly_16_segments: { metrics } });
assert.equal(teacherSummaryLine({ settlement: { payroll_subject_count: 18.9375 }, components: weekly({ week_count: 4, qualifying_weeks: 1 }) }), '科目數 18.9375 科・4 週裡有 3 週沒滿 16 段');
assert.equal(teacherSummaryLine({ settlement: { payroll_subject_count: 20, new_hire_guarantee_applied: true }, components: weekly({ week_count: 4, qualifying_weeks: 4 }) }), '科目數 20 科・每週都滿 16 段・新進保底');
assert.equal(teacherSummaryLine({ settlement: { payroll_subject_count: 3 }, components: weekly({ week_count: 5, qualifying_weeks: 1, unfinished_weeks: 2 }), pending_items: [{ label: '現金加扣款' }] }),
  '科目數 3 科・還有 2 週沒結束・現金加扣款還沒處理完');
assert.equal(teacherSummaryLine({}), '科目數 — 科');
assert.equal(multiplierBreakdown({ multiplier_parts: [{ label: '基本', pct: 100 }, { label: '假日', pct: 0 }, { label: '平日下午', pct: 2.94 }, { label: '扣除', pct: -10 }] }), '基本 100%、平日下午 +2.94%、扣除 -10%');
assert.equal(multiplierBreakdown(undefined), '');
assert.equal(oneToThreeFormula({ one_to_three_count: 24.5, one_to_three_bonus: 2450 }), '一對三總計 24.5 × 100');
// A month locked under the earlier table: the stored amount is not count × 100, so the line does not claim it is.
assert.equal(oneToThreeFormula({ one_to_three_count: 20, one_to_three_bonus: 4600 }), '一對三總計 20（金額是鎖定當時的算法）');
// An unconfirmed multiplier stays unknown; the trial rate is said beside it, not shown as the figure.
assert.equal(multiplierValue({ multiplier_pct: 105 }), '105%');
assert.equal(multiplierValue({ multiplier_pct: null, known_multiplier_pct: 103 }), '—');
assert.equal(multiplierPendingNote({ multiplier_pct: null, known_multiplier_pct: 103 }), '還有項目沒確認，先用 103% 試算');
assert.equal(multiplierPendingNote({ multiplier_pct: 105, known_multiplier_pct: 105 }), '');
// The 16 段課 bonus has its own line, so it is not repeated among the 加扣款 lines.
assert.deepEqual(cashAdjustments({ weekly_segment_bonus_amount: 4000, adjustments: [{ label: '16段課', amount: 4000 }, { label: '代班補貼', amount: 1500 }] }), [{ label: '代班補貼', amount: 1500 }]);
// A cash adjustment that happens to carry the same name is a real line and stays; so does everything when no bonus was paid.
assert.deepEqual(cashAdjustments({ weekly_segment_bonus_amount: 4000, adjustments: [{ label: '16段課', amount: 4000 }, { label: '16段課', amount: 4000 }, { label: '16段課', amount: 300 }] }),
  [{ label: '16段課', amount: 4000 }, { label: '16段課', amount: 300 }]);
assert.deepEqual(cashAdjustments({ weekly_segment_bonus_amount: 0, adjustments: [{ label: '16段課', amount: 300 }] }), [{ label: '16段課', amount: 300 }]);
assert.deepEqual(cashAdjustments(undefined), []);
assert.equal(hasNoLessons({ settlement: { total_payout: 0 }, components: weekly({ total_segments: 0 }) }), true);
assert.equal(hasNoLessons({ settlement: { total_payout: 0 }, components: weekly({ total_segments: 3 }) }), false);
assert.equal(hasNoLessons({ settlement: { total_payout: 500 }, components: weekly({ total_segments: 0 }) }), false);
assert.equal(hasNoLessons({ review_required: true, settlement: { total_payout: 0 } }), false);
// Lessons only in the days before the first Monday: no week, no payout, but 科目數 says the teacher did teach.
assert.equal(hasNoLessons({ settlement: { total_payout: 0, payroll_subject_count: 0.5 }, components: weekly({ total_segments: 0 }) }), false);
// In a locked month a teacher whose result differs today, or cannot be compared, is not folded away.
const idleRow = { settlement: { total_payout: 0 }, components: weekly({ total_segments: 0 }) };
assert.equal(hasNoLessons({ ...idleRow, recalculated: { calculation_status: 'calculated', difference: 0 } }), true);
assert.equal(hasNoLessons({ ...idleRow, recalculated: { calculation_status: 'calculated', difference: 4000 } }), false);
assert.equal(hasNoLessons({ ...idleRow, recalculated: { calculation_status: 'review', difference: 0 } }), false);
assert.equal(hasNoLessons({ ...idleRow, recalculated: null }), false);
assert.deepEqual(monthWindow('2026-08'), { start: '2026-08-01', end: '2026-08-31' });
// 正課 150 raw + 輔導試聽 12 raw = 核薪總 20.25: the three columns add up once shown on the same scale.
assert.equal(formatSubjectUnits(150), '18.75');
assert.equal(formatSubjectUnits(12), '1.5');
assert.equal(formatSubjectUnits(null), '—');

console.log('teacher eligibility display tests passed');
