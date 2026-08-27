import assert from 'node:assert/strict';
import test from 'node:test';
import { compareUnusedBaseline, summarizeUnusedMessages } from '../check-eslint-unused-baseline.mjs';

test('summarizes only no-unused-vars messages by frontend-relative file', () => {
  const summary = summarizeUnusedMessages([
    { filePath: '/repo/frontend/src/App.vue', messages: [{ ruleId: 'no-unused-vars' }, { ruleId: 'no-undef' }] },
    { filePath: '/repo/frontend/src/api.js', messages: [{ ruleId: 'no-unused-vars' }] },
  ], '/repo/frontend');

  assert.deepEqual(summary, { total: 2, files: { 'src/App.vue': 1, 'src/api.js': 1 } });
});

test('fails only when a file exceeds its recorded baseline', () => {
  const violations = compareUnusedBaseline(
    { total: 4, files: { 'src/App.vue': 3, 'src/New.vue': 1 } },
    { total: 3, files: { 'src/App.vue': 2 } },
  );

  assert.deepEqual(violations, ['src/App.vue: 3 > baseline 2', 'src/New.vue: 1 > baseline 0']);
  assert.deepEqual(compareUnusedBaseline({ total: 1, files: { 'src/App.vue': 1 } }, { files: { 'src/App.vue': 2 } }), []);
});
