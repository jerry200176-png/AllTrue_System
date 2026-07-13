/**
 * duplicateReviewApi.test.js — P2 審核 API 純函式測試
 *
 * 測試 STATUS_LABELS / SESSION_STATUS_LABELS 常數正確性。
 * 執行：node src/lib/duplicateReviewApi.test.js
 */
import assert from 'node:assert/strict';
import { STATUS_LABELS, SESSION_STATUS_LABELS } from './duplicateReviewApi.js';

// ── STATUS_LABELS ──
assert.equal(STATUS_LABELS.pending, '待審核', 'pending → 待審核');
assert.equal(STATUS_LABELS.decided, '已決策', 'decided → 已決策');
assert.equal(STATUS_LABELS.executed, '已執行', 'executed → 已執行');
assert.equal(Object.keys(STATUS_LABELS).length, 3, 'STATUS_LABELS 只有 3 個 key');

// ── SESSION_STATUS_LABELS ──
assert.equal(SESSION_STATUS_LABELS.scheduled, '已排定', 'scheduled → 已排定');
assert.equal(SESSION_STATUS_LABELS.attended, '已出席', 'attended → 已出席');
assert.equal(SESSION_STATUS_LABELS.completed, '已完成', 'completed → 已完成');
assert.equal(SESSION_STATUS_LABELS.late, '遲到', 'late → 遲到');
assert.equal(SESSION_STATUS_LABELS.leave, '請假', 'leave → 請假');
assert.equal(SESSION_STATUS_LABELS.cancelled, '已取消', 'cancelled → 已取消');
assert.equal(SESSION_STATUS_LABELS.absent, '缺席', 'absent → 缺席');
assert.equal(Object.keys(SESSION_STATUS_LABELS).length, 7, 'SESSION_STATUS_LABELS 有 7 個 key');

console.log('✅ duplicateReviewApi.test.js — all assertions passed');
