/**
 * studentClassDisplay — in-app #200 Minimal Fix
 * Directors must identify the correct course without understanding SC.
 */
import assert from 'node:assert/strict';
import {
  formatStudentClassOpenDate,
  formatStudentClassDisplay,
  formatStudentClassSideDisplays,
  primaryLeaksInternalId,
} from './studentClassDisplay.js';

// --- unit: open date ---
assert.equal(formatStudentClassOpenDate('2026-05-02 00:00:00'), '5/2');
assert.equal(formatStudentClassOpenDate('2026-07-04'), '7/4');
assert.equal(formatStudentClassOpenDate(''), '');
assert.equal(formatStudentClassOpenDate(null), '');

// --- unit: primary never leads with SC ---
const sideA = {
  student_class_id: 315,
  subject_name: '理化',
  teacher_name: '邱御碩',
  start_date: '2025-09-01 00:00:00',
  session_count: 8,
  remaining_sessions: 0,
};
const sideB = {
  student_class_id: 1409,
  subject_name: '理化',
  teacher_name: '邱御碩',
  start_date: '2026-05-01 00:00:00',
  session_count: 16,
  remaining_sessions: 0,
};

const a = formatStudentClassDisplay(sideA);
assert.ok(a.primary.includes('理化'));
assert.ok(a.primary.includes('邱御碩'));
assert.ok(a.primary.includes('開課 9/1'));
assert.equal(a.techId, 'SC #315');
assert.equal(primaryLeaksInternalId(a.primary), false, 'primary must not contain SC #id');

const b = formatStudentClassDisplay(sideB);
assert.ok(b.primary.includes('開課 5/1'));
assert.equal(b.techId, 'SC #1409');
assert.equal(primaryLeaksInternalId(b.primary), false);

// --- unit: missing human fields — still no SC in primary ---
const bare = formatStudentClassDisplay({ student_class_id: 99 });
assert.equal(primaryLeaksInternalId(bare.primary), false);
assert.equal(bare.techId, 'SC #99');

// --- E2E-style (no SC assertion on decision path): director can tell sides apart ---
const displays = formatStudentClassSideDisplays([sideA, sideB]);
assert.equal(displays.length, 2);
assert.notEqual(displays[0].primary, displays[1].primary, 'director must see two different human labels');
assert.equal(primaryLeaksInternalId(displays[0].primary), false);
assert.equal(primaryLeaksInternalId(displays[1].primary), false);
// Decision rule a director can follow without SC:
// 「保留開課較新、堂數較多的那一側」→ sideB
const keepByHuman = displays.find((d) => d.primary.includes('開課 5/1') && d.primary.includes('16 堂'));
assert.ok(keepByHuman, 'director can pick renewal side by open date + session count');
assert.equal(keepByHuman.techId, 'SC #1409'); // tech id only for engineers / API keep_sc_id

// Collision with identical human fields — still must not put SC in primary
const twin = formatStudentClassSideDisplays([
  { student_class_id: 1, subject_name: '英文', teacher_name: '王老師', session_count: 8, remaining_sessions: 3 },
  { student_class_id: 2, subject_name: '英文', teacher_name: '王老師', session_count: 8, remaining_sessions: 7 },
]);
assert.notEqual(twin[0].primary, twin[1].primary);
assert.equal(primaryLeaksInternalId(twin[0].primary), false);
assert.equal(primaryLeaksInternalId(twin[1].primary), false);

console.log('studentClassDisplay.test.js: all assertions passed');
