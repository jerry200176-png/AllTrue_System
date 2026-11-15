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
  recalculationSummary,
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
assert.equal(recalculationSummary([{ recalculated: { difference: 1200 } }, { recalculated: { difference: -300 } }, { recalculated: { difference: 0 } }, { recalculated: null }]),
  '照現在的算法重算，2 位老師金額不同，合計多 $900。鎖定的數字沒有變。');
assert.equal(recalculationSummary([{ recalculated: { difference: 0 } }]), '照現在的算法重算，每位老師的金額都和鎖定時相同。');
assert.equal(recalculationSummary([{}]), '');
assert.deepEqual(monthWindow('2026-08'), { start: '2026-08-01', end: '2026-08-31' });
// 正課 150 raw + 輔導試聽 12 raw = 核薪總 20.25: the three columns add up once shown on the same scale.
assert.equal(formatSubjectUnits(150), '18.75');
assert.equal(formatSubjectUnits(12), '1.5');
assert.equal(formatSubjectUnits(null), '—');

console.log('teacher eligibility display tests passed');
