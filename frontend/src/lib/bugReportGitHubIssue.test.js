import assert from 'node:assert/strict';

// GitHub Issue 狀態判斷（與 BugReportsPage.vue 內 githubIssueOpenClass / githubIssueStatusLabel 邏輯一致）
// open  status: new, triaged, in_progress
// closed status: resolved, closed

const GITHUB_OPEN_STATUSES = new Set(['new', 'triaged', 'in_progress']);

function githubIssueOpenClass(status) {
  if (!status) return '';
  return GITHUB_OPEN_STATUSES.has(status) ? 'is-open' : 'is-closed';
}

function githubIssueStatusLabel(status) {
  if (!status) return '';
  return GITHUB_OPEN_STATUSES.has(status) ? 'Issue 開啟中' : 'Issue 已關閉';
}

// ── open statuses ────────────────────────────────────────────────────
assert.equal(githubIssueOpenClass('new'), 'is-open', 'new → open');
assert.equal(githubIssueOpenClass('triaged'), 'is-open', 'triaged → open');
assert.equal(githubIssueOpenClass('in_progress'), 'is-open', 'in_progress → open');

// ── closed statuses ──────────────────────────────────────────────────
assert.equal(githubIssueOpenClass('resolved'), 'is-closed', 'resolved → closed');
assert.equal(githubIssueOpenClass('closed'), 'is-closed', 'closed → closed');

// ── edge: null / empty / unknown ─────────────────────────────────────
assert.equal(githubIssueOpenClass(null), '', 'null → empty string');
assert.equal(githubIssueOpenClass(undefined), '', 'undefined → empty string');
assert.equal(githubIssueOpenClass(''), '', 'empty string → empty string');
assert.equal(githubIssueOpenClass('nonexistent'), 'is-closed', 'unknown status → closed (fallback)');

// ── statusLabel ──────────────────────────────────────────────────────
assert.equal(githubIssueStatusLabel('new'), 'Issue 開啟中', 'new label');
assert.equal(githubIssueStatusLabel('resolved'), 'Issue 已關閉', 'resolved label');
assert.equal(githubIssueStatusLabel(null), '', 'null label → empty');
assert.equal(githubIssueStatusLabel(undefined), '', 'undefined label → empty');

// ── Edge case: github_issue_number 為 null / 0 判斷 ──────────────────
// 前端 v-if 用 `bug.github_issue_number != null` 做顯示判斷：
// null / undefined → 不渲染；0 視為有效值（實際不會出現，但保護不 crash）
function shouldShowGitHubIssue(issueNumber) {
  return issueNumber != null;
}

assert.equal(shouldShowGitHubIssue(null), false, 'null → 不顯示');
assert.equal(shouldShowGitHubIssue(undefined), false, 'undefined → 不顯示');
assert.equal(shouldShowGitHubIssue(1234), true, '正整數 → 顯示');
assert.equal(shouldShowGitHubIssue(0), true, '0 → 顯示（邊界保護）');

// ── Edge case: github_issue_url 需與 number 一致出現 ─────────────────
// 後端 contract 保證兩者要嘛同時 null、要嘛同時有值
// 前端不額外驗證 url 格式，僅透傳給 href

console.log('✅ bugReportGitHubIssue.test.js — all assertions passed');
