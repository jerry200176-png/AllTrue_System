import assert from 'node:assert/strict';
import { buildParentActionItems } from './parentActionItems.js';

const baseSummary = {
  pending_actions: [
    { key: 'payment', count: 1 },
    { key: 'feedback', count: 2 },
  ],
  next_session: null,
};

const items = buildParentActionItems({
  progressSummary: baseSummary,
  paymentAlerts: [{ subject: '英文' }],
  upcomingSessions: [{ Status: 'leave_requested' }],
  learningRecords: [{ parent_feedback: { has_unread_reply: true } }],
});

assert.deepEqual(items.map((item) => item.key), ['leave', 'feedback_reply', 'payment', 'feedback']);
assert.equal(items[0].target, 'schedule');
assert.equal(items[1].detail, '1 則回覆等您查看。');
assert.equal(items[2].detail, '1 筆項目需要查看。');

const todayItems = buildParentActionItems({
  progressSummary: {
    pending_actions: [],
    next_session: { is_today: true, subject: '數學', start_time: '18:30' },
  },
});
assert.equal(todayItems.length, 1);
assert.equal(todayItems[0].detail, '數學，18:30 開始。');

assert.deepEqual(buildParentActionItems(), []);
console.log('parent action items tests passed');
