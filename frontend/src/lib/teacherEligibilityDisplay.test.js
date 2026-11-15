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
  recalculationSummary,
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
assert.deepEqual(inputRoster([{ teacher_id: 1, teacher_name: '甲' }], [{ teacher_id: 2, teacher_name: '乙', campus_id: 9, campus_name: '別的分校' }]).map((t) => [t.teacher_id, t.teacher_name]),
  [[1, '甲'], [2, '乙']]);
assert.deepEqual(inputRoster(undefined, undefined), []);
assert.deepEqual(monthWindow('2026-08'), { start: '2026-08-01', end: '2026-08-31' });
// 正課 150 raw + 輔導試聽 12 raw = 核薪總 20.25: the three columns add up once shown on the same scale.
assert.equal(formatSubjectUnits(150), '18.75');
assert.equal(formatSubjectUnits(12), '1.5');
assert.equal(formatSubjectUnits(null), '—');

console.log('teacher eligibility display tests passed');
