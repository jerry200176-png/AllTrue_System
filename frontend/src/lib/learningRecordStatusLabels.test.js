import assert from 'node:assert/strict';
import {
  fillStatusLabel,
  reviewStatusLabel,
  isAmbiguousDirectorFillLabel,
  isAmbiguousDirectorReviewLabel,
  AMBIGUOUS_DIRECTOR_FILL_LABELS,
  AMBIGUOUS_DIRECTOR_REVIEW_LABELS,
} from './learningRecordStatusLabels.js';

assert.equal(fillStatusLabel(false), '未填');
assert.equal(fillStatusLabel(true), '已填');
assert.equal(reviewStatusLabel('pending'), '待審核');
assert.equal(reviewStatusLabel('changes_requested'), '需修改');

assert.equal(fillStatusLabel(false, { director: true }), '評量內容未填');
assert.equal(fillStatusLabel(true, { director: true }), '評量內容已填');
assert.equal(reviewStatusLabel('pending', { director: true }), '審核：待主任核准');
assert.equal(reviewStatusLabel('changes_requested', { director: true }), '審核：老師需修改');
assert.equal(reviewStatusLabel('approved', { director: true }), '審核：已核准');
assert.equal(reviewStatusLabel('rejected', { director: true }), '審核：已退回');

for (const label of AMBIGUOUS_DIRECTOR_FILL_LABELS) {
  assert.equal(isAmbiguousDirectorFillLabel(label), true);
  assert.equal(
    isAmbiguousDirectorFillLabel(fillStatusLabel(label === '已填', { director: true })),
    false,
    `director fill must not collapse to ambiguous ${label}`,
  );
}

for (const label of AMBIGUOUS_DIRECTOR_REVIEW_LABELS) {
  assert.equal(isAmbiguousDirectorReviewLabel(label), true);
}

for (const status of ['pending', 'approved', 'rejected', 'changes_requested']) {
  const directorLabel = reviewStatusLabel(status, { director: true });
  assert.equal(isAmbiguousDirectorReviewLabel(directorLabel), false);
  assert.equal(directorLabel.startsWith('審核：'), true);
}

console.log('learningRecordStatusLabels.test.js: ok');
