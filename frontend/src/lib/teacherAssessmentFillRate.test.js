import assert from 'node:assert/strict';
import test from 'node:test';
import {
  getTeacherAssessmentFillRateStatus,
  normalizeTeacherAssessmentFillRate,
  sortTeacherAssessmentFillRates,
} from './teacherAssessmentFillRate.js';

test('samples below the threshold stay neutral even when no record is filled', () => {
  const row = normalizeTeacherAssessmentFillRate({
    teacher_id: 8,
    teacher_name: '老師甲',
    sessions_attended: 2,
    learning_records_filled: 0,
    fill_rate_pct: 0,
  });

  assert.deepEqual(row, {
    teacherId: 8,
    teacherName: '老師甲',
    sessions: 2,
    filled: 0,
    recordsPresent: 2,
    missing: 0,
    pending: 2,
    fillRate: 0,
    status: 'building',
  });
  assert.equal(getTeacherAssessmentFillRateStatus(row.status).tone, 'neutral');
});

test('clamps inconsistent API counts and identifies follow-up work', () => {
  const row = normalizeTeacherAssessmentFillRate({
    teacher_name: '老師乙',
    sessions_attended: 10,
    learning_records_filled: 13,
    fill_rate_pct: 140,
  });

  assert.equal(row.filled, 10);
  assert.equal(row.pending, 0);
  assert.equal(row.fillRate, 100);
  assert.equal(row.status, 'on_track');

  const needsFollowUp = normalizeTeacherAssessmentFillRate({
    teacher_name: '老師丙',
    sessions_attended: 10,
    learning_records_filled: 2,
    fill_rate_pct: 20,
  });
  assert.equal(needsFollowUp.status, 'follow_up');
});

test('separates missing assessment forms from existing forms that are not complete', () => {
  const row = normalizeTeacherAssessmentFillRate({
    teacher_name: '老師丁',
    sessions_attended: 10,
    learning_records_present: 8,
    learning_records_filled: 6,
    missing_evaluations: 2,
    pending_evaluations: 2,
  });

  assert.equal(row.missing, 2);
  assert.equal(row.pending, 2);
  assert.equal(row.recordsPresent, 8);
  assert.equal(row.status, 'follow_up');
});

test('sorts actionable rows first without presenting a competitive rank', () => {
  const rows = [
    normalizeTeacherAssessmentFillRate({ teacher_name: '穩定', sessions_attended: 10, learning_records_filled: 10, fill_rate_pct: 100 }),
    normalizeTeacherAssessmentFillRate({ teacher_name: '資料少', sessions_attended: 2, learning_records_filled: 0, fill_rate_pct: 0 }),
    normalizeTeacherAssessmentFillRate({ teacher_name: '需跟進', sessions_attended: 10, learning_records_filled: 1, fill_rate_pct: 10 }),
  ];

  assert.deepEqual(sortTeacherAssessmentFillRates(rows).map((row) => row.teacherName), ['需跟進', '資料少', '穩定']);
});
