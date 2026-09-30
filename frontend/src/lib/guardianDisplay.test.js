import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { guardianRoleLabel, lineBindingDisplay } from './guardianDisplay.js';

assert.equal(guardianRoleLabel('guardian'), '監護人');
assert.equal(guardianRoleLabel('mother'), '媽媽');
assert.equal(guardianRoleLabel('grandpa'), 'grandpa');
assert.deepEqual(lineBindingDisplay({ bound_at: '2026-09-03T06:41:27.000000Z' }), { label: '已綁定 LINE', date: '綁定日 2026/09/03' });
assert.equal(lineBindingDisplay({}).date, '');

const src = readFileSync(new URL('../pages/StudentsList.vue', import.meta.url), 'utf8');
assert.doesNotMatch(src, /\{\{ b\.line_user_id_masked \}\}/);
assert.doesNotMatch(src, /\{\{ b\.bound_at \}\}/);
assert.match(src, /guardianRoleLabel\(g\.role\)/);
assert.match(src, /key === 'Escape'[\s\S]{0,120}closeStudentModal\(\)/);
console.log('guardianDisplay.test.js: all assertions passed');
