import assert from 'node:assert/strict';
import {
  trustPeopleSlice,
  trustPeopleSummary,
  trustDecisionTitle,
  parseTrustFocus,
  filterGroupsByTrustFocus,
} from './trustDecisionDisplay.js';

const dupItem = {
  key: 'calendar_duplicate',
  title: '偵測到 3 組跨約重複堂',
  people_total: 3,
  people: [
    { student_id: 172, student_name: '高瑞璋', student_class_id: 315 },
    { student_id: 172, student_name: '高瑞璋', student_class_id: 315 },
    { student_id: 172, student_name: '高瑞璋', student_class_id: 315 },
  ],
};

assert.equal(trustPeopleSummary(dupItem), '高瑞璋', 'single student summary');
assert.equal(
  trustDecisionTitle(dupItem),
  '高瑞璋：3 組跨約重複堂',
  'single student title rewrite',
);

const multiItem = {
  key: 'calendar_duplicate',
  title: '偵測到 5 組跨約重複堂',
  people: [
    { student_name: '甲同學' },
    { student_name: '乙同學' },
  ],
};
assert.equal(trustPeopleSummary(multiItem), '甲同學、乙同學', 'multi student summary');
assert.equal(trustDecisionTitle(multiItem), '偵測到 5 組跨約重複堂', 'multi student keeps generic title');

assert.equal(trustPeopleSlice({ people: Array.from({ length: 12 }, (_, i) => ({ id: i })) }).length, 8);

const focus = parseTrustFocus(JSON.stringify({
  student_name: '高瑞璋',
  student_id: 172,
  decision_key: 'calendar_duplicate',
  at: Date.now(),
}));
assert.ok(focus);
assert.equal(focus.studentName, '高瑞璋');
assert.equal(focus.studentId, 172);

assert.equal(parseTrustFocus('not-json'), null);
assert.equal(parseTrustFocus(JSON.stringify({ at: Date.now(), decision_key: 'dormant_hold' })), null);

const groups = [
  { student_id: 172, student_name: '高瑞璋', _key: 'a' },
  { student_id: 99, student_name: '其他', _key: 'b' },
];
const filtered = filterGroupsByTrustFocus(groups, { studentName: '高瑞璋', studentId: 172 });
assert.equal(filtered.length, 1);
assert.equal(filtered[0].student_id, 172);

console.log('trustDecisionDisplay.test.js: all assertions passed');
