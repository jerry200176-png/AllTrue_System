import assert from 'node:assert/strict';
import {
  answerMapFromAttempt,
  attemptStatusLabel,
  buildAnswerPayload,
  isManualQuestion,
} from './assessmentRunner.js';

const attempt = {
  answers: [
    { question_id: 11, answer: 'B' },
    { question_id: 12, answer: ['A', 'C'] },
  ],
};

assert.deepEqual(answerMapFromAttempt(attempt), { 11: 'B', 12: ['A', 'C'] });
assert.deepEqual(
  buildAnswerPayload([{ id: 11 }, { id: 12 }, { id: 13 }], answerMapFromAttempt(attempt)),
  [
    { question_id: 11, answer: 'B' },
    { question_id: 12, answer: ['A', 'C'] },
    { question_id: 13, answer: null },
  ]
);
assert.equal(isManualQuestion({ question_type: 'short_answer' }), true);
assert.equal(isManualQuestion({ question_type: 'single_choice' }), false);
assert.equal(attemptStatusLabel('submitted'), '待人工複核');
assert.equal(attemptStatusLabel('in_progress'), '作答中');

console.log('assessmentRunner.test: OK');
