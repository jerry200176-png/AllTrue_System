import assert from 'assert';
import { findUserFacingCopyIssues, assertUserFacingCopy } from './userFacingCopyGate.mjs';

const bad = [
  '55 復活判斷收斂為單一共用政策',
  '006 Phase 3A pool coverage planner（read-only）',
  '調課標記 IsContractException（防 realign 還原）',
  '頁改吃 DS tokens',
  'Continuity 群組 MVP',
  '優化系統使用體驗',
];

for (const phrase of bad) {
  assert.ok(findUserFacingCopyIssues(phrase).length > 0, `expected reject: ${phrase}`);
}

assert.strictEqual(
  findUserFacingCopyIssues('單堂調課後，課程不會再被系統自動拉回原本時段。').length,
  0,
  'good staff copy must pass',
);

assert.throws(
  () => assertUserFacingCopy(['fix(learning): R55 復活'], 'x'),
  /copy gate failed/,
);

console.log('userFacingCopyGate.test.mjs: ok');
