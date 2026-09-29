import assert from 'node:assert/strict';
import { formatSubjectCount, formatSubjectTotalDiv8 } from './subjectUnitsDisplay.js';

assert.equal(formatSubjectCount(1.5), '1.50');
assert.equal(formatSubjectCount('1.5'), '1.50');
assert.equal(formatSubjectCount(null), '0.00');
assert.equal(formatSubjectCount('not-a-number'), '0.00');

assert.equal(formatSubjectTotalDiv8(12), '1.50');
assert.equal(formatSubjectTotalDiv8('12.4'), '1.55'); // divide once, round once
assert.equal(formatSubjectTotalDiv8(null), '0.00');
assert.equal(formatSubjectTotalDiv8('x'), '0.00');

console.log('subjectUnitsDisplay tests passed');

