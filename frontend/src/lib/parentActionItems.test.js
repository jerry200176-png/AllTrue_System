import assert from 'node:assert/strict';
import { buildParentActionItems, buildParentHomeSummary } from './parentActionItems.js';

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

const home = buildParentHomeSummary({
  learningRecords: [{
    Subject: '英文', SessionDate: '2026-09-08', Progress: '完成句型練習',
    Comment: '下次持續練習', NextHomework: '複習本次錯題', NextWeekTestScope: '第三單元',
  }],
  assessmentItems: [{ outcome_label: '建議再練習', focus_areas: ['單字'] }],
  progressSummary: { next_session: { date: '2026-09-10', start_time: '18:00', subject: '數學' } },
  actionItems: [{ title: '帳務有提醒', action: '查看帳務' }],
});
assert.equal(home.latestText, '完成句型練習');
assert.equal(home.focusText, '建議再練習 · 單字');
assert.equal(home.teacherText, '下次持續練習');
assert.equal(home.homeworkText, '複習本次錯題');
assert.deepEqual(home.nextSteps, ['帳務有提醒：查看帳務', '下次課程：2026-09-10 18:00 數學']);
console.log('parent action items tests passed');
