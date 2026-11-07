import assert from 'node:assert/strict';
import test from 'node:test';
import { rangesFromDiff } from '../ci/stryker-diff-ranges.mjs';

const D = `diff --git a/.github/scripts/review-gate.mjs b/.github/scripts/review-gate.mjs
--- a/.github/scripts/review-gate.mjs
+++ b/.github/scripts/review-gate.mjs
@@ -10,0 +11,3 @@ x
+a
@@ -20 +25 @@ y
-b
+c
@@ -30,2 +36,0 @@ z
-d
diff --git a/scripts/ci/gov.test.mjs b/scripts/ci/gov.test.mjs
--- a/scripts/ci/gov.test.mjs
+++ b/scripts/ci/gov.test.mjs
@@ -1 +1 @@
+t
`;

test('ranges: added/changed lines of target files only; pure deletions and other files skipped', () => {
  assert.deepEqual(rangesFromDiff(D), ['.github/scripts/review-gate.mjs:11-13', '.github/scripts/review-gate.mjs:25-25']);
  assert.deepEqual(rangesFromDiff(''), []);
});
