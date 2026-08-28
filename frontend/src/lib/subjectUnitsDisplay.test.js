import assert from 'node:assert/strict';
import { formatSubjectCount } from './subjectUnitsDisplay.js';

assert.equal(formatSubjectCount(1.5), '1.50');
assert.equal(formatSubjectCount('1.5'), '1.50');
assert.equal(formatSubjectCount(null), '0.00');
assert.equal(formatSubjectCount('not-a-number'), '0.00');

console.log('subjectUnitsDisplay tests passed');

