import assert from 'node:assert/strict';
import {
  eventKindHint,
  eventKindLabel,
  eventSubtitle,
  formatMoney,
  formatSubjects,
  weightedBonusFormula,
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
assert.deepEqual(monthWindow('2026-08'), { start: '2026-08-01', end: '2026-08-31' });

console.log('teacher eligibility display tests passed');
