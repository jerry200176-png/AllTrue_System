import assert from 'node:assert/strict';
import {
  assessmentProgressPercentLabel,
  assessmentProgressScoreLabel,
  formatAssessmentProgressDate,
} from './parentAssessmentProgress.js';

assert.equal(formatAssessmentProgressDate('2026-08-21T10:30:00+08:00'), '2026/8/21');
assert.equal(formatAssessmentProgressDate('not-a-date'), '—');
assert.equal(assessmentProgressScoreLabel({ score: 62, max_score: 100 }), '62/100 分');
assert.equal(assessmentProgressScoreLabel({ score: 'bad', max_score: 100 }), '—');
assert.equal(assessmentProgressPercentLabel({ percent: 62 }), '62%');
assert.equal(assessmentProgressPercentLabel({ percent: null }), '—');
console.log('parent assessment progress tests passed');
