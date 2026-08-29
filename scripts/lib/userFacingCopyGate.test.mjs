import assert from 'assert';
import { findUserFacingCopyIssues, assertUserFacingCopy } from './userFacingCopyGate.mjs';

const bad = [
  '55 復活判斷收斂為單一共用政策',
  '006 Phase 3A pool coverage planner（read-only）',
  '調課標記 IsContractException（防 realign 還原）',
  '頁改吃 DS tokens',
  'Continuity 群組 MVP',
  '優化系統使用體驗',
  '畫面出現 eview… 文字',
  '已通過正式 CI 與 production smoke',
  '暫時回傳 HTTP 422，請稍後再試。',
];

for (const phrase of bad) {
  assert.ok(findUserFacingCopyIssues(phrase).length > 0, `expected reject: ${phrase}`);
}

assert.strictEqual(
  findUserFacingCopyIssues('單堂調課後，課程不會再被系統自動拉回原本時段。').length,
  0,
  'good staff copy must pass',
);
assert.strictEqual(
  findUserFacingCopyIssues('方案目前有 56 堂，已使用 1 堂，剩餘 55 堂。').length,
  0,
  'normal quantities must not be treated as truncated copy',
);
assert.strictEqual(
  findUserFacingCopyIssues('請在 8 月 20 日重新整理行事曆。').length,
  0,
  'normal date quantities must not be treated as truncated copy',
);

assert.throws(
  () => assertUserFacingCopy(['fix(learning): R55 復活'], 'x'),
  /copy gate failed/,
);

console.log('userFacingCopyGate.test.mjs: ok');
