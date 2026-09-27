import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildGradePromotionConfirmPayload,
  countActionableSelected,
  createGradePromotionIdempotencyKey,
  gradePromotionSuccessMessage,
  toggleGradePromotionExclude,
} from './gradePromotionUi.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const studentsListSource = readFileSync(resolve(__dirname, '../pages/StudentsList.vue'), 'utf8');

{
  const payload = buildGradePromotionConfirmPayload({
    branchId: '3',
    seasonYear: 2026,
    idempotencyKey: 'gp-test-key-0001',
    excludeStudentIds: new Set([11, 22]),
  });
  assert.deepEqual(payload, {
    branch_id: 3,
    season_year: 2026,
    idempotency_key: 'gp-test-key-0001',
    exclude_student_ids: [11, 22],
  });
}

{
  const payload = buildGradePromotionConfirmPayload({
    branchId: 1,
    seasonYear: null,
    idempotencyKey: 'gp-test-key-0002',
    excludeStudentIds: [],
  });
  assert.equal(payload.season_year, undefined);
  assert.equal(payload.branch_id, 1);
}

{
  const rows = [
    { student_id: 1, actionable: true },
    { student_id: 2, actionable: true },
    { student_id: 3, actionable: false },
  ];
  assert.equal(countActionableSelected(rows, new Set([2])), 1);
  assert.equal(countActionableSelected(rows, []), 2);
}

{
  let excluded = new Set();
  excluded = toggleGradePromotionExclude(excluded, 9, false);
  assert.deepEqual([...excluded], [9]);
  excluded = toggleGradePromotionExclude(excluded, 9, true);
  assert.deepEqual([...excluded], []);
}

{
  const key = createGradePromotionIdempotencyKey(1_700_000_000_000, () => 0.123456);
  assert.ok(key.startsWith('gp-'));
  assert.ok(key.length >= 8 && key.length <= 64);
}

assert.match(gradePromotionSuccessMessage({ replayed: true }, 3), /重試安全/);
assert.match(gradePromotionSuccessMessage({ replayed: false, summary: { applied: 5 } }, 3), /5/);

assert.match(studentsListSource, /@click="openGradePromotion"/);
assert.match(studentsListSource, /\/api\/v1\/grade-promotions\/preview/);
assert.match(studentsListSource, /\/api\/v1\/grade-promotions\/confirm/);
assert.match(studentsListSource, /gradePromotionIdempotencyKey/);
assert.match(studentsListSource, /excludeStudentIds/);
assert.match(studentsListSource, /buildGradePromotionConfirmPayload/);
assert.match(studentsListSource, /createGradePromotionIdempotencyKey/);
assert.doesNotMatch(
  studentsListSource.slice(
    studentsListSource.indexOf('async function openGradePromotion'),
    studentsListSource.indexOf('const executeGradePromotion')
  ),
  /confirm\(/
);

{
  const start = studentsListSource.indexOf('// Grade promotion — canonical API');
  const end = studentsListSource.indexOf('// --- Data Loading ---');
  assert.ok(start >= 0 && end > start, 'grade promotion API block markers missing');
  const promoBlock = studentsListSource.slice(start, end);
  assert.doesNotMatch(promoBlock, /student-classes[\s\S]*inactive/);
  assert.doesNotMatch(promoBlock, /\.from\(['"]students['"]\)\.update/);
  assert.doesNotMatch(promoBlock, /\.from\(['"]student-classes['"]\)/);
  assert.match(promoBlock, /畢業|H3|grade-promotions/);
}

assert.match(studentsListSource, /gradePromotionActionableSelectedCount === 0/);
assert.match(studentsListSource, /gradePromotionLoading/);
assert.match(studentsListSource, /確認升級（\{\{ gradePromotionActionableSelectedCount \}\}/);

console.log('gradePromotionUi.test.js: ok');
