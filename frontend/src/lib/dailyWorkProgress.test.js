import assert from 'node:assert/strict';
import { getDailyWorkProgress } from './dailyWorkProgress.js';

assert.deepEqual(getDailyWorkProgress(3, 8), {
  completed: 3,
  total: 8,
  remaining: 5,
  percent: 38,
  hasWork: true,
  isComplete: false,
});

assert.equal(getDailyWorkProgress(12, 8).completed, 8);
assert.equal(getDailyWorkProgress(12, 8).percent, 100);
assert.deepEqual(getDailyWorkProgress(-1, 0), {
  completed: 0,
  total: 0,
  remaining: 0,
  percent: 0,
  hasWork: false,
  isComplete: false,
});

console.log('dailyWorkProgress tests passed');
