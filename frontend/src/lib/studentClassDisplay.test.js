/**
 * studentClassDisplay — in-app #200 Minimal Fix
 * Directors must identify the correct course without understanding SC.
 */
import assert from 'node:assert/strict';
import {
  formatStudentClassOpenDate,
  formatStudentClassDisplay,
  formatStudentClassSideDisplays,
  formatTuitionSettleSummary,
  formatTuitionNewerCourseHint,
  formatDirectorPersonName,
  formatRenewSuccessMessage,
  formatDuplicatePurchaseHint,
  formatLedgerCourseLabel,
  formatLedgerInvoiceLabel,
  formatLedgerReceiptBillLine,
  formatLedgerAnomalyDetail,
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

// --- UXID-002: tuition settle / newer-course hints (display only) ---
assert.equal(primaryLeaksInternalId('課程 #382，剩餘 2 堂'), true);
assert.equal(primaryLeaksInternalId('已偵測到新課程 #999（開課日 7/4）'), true);

const settle = formatTuitionSettleSummary({
  id: 382,
  subject: '英文',
  student_name: '王小明',
  remaining_sessions: 2,
  start_date: '2026-03-01',
});
assert.ok(settle.primary.includes('剩餘 2 堂'));
assert.ok(settle.primary.includes('開課 3/1'));
assert.equal(primaryLeaksInternalId(settle.primary), false);
assert.equal(settle.techId, 'SC #382');

const newer = formatTuitionNewerCourseHint({
  newer_course_id: 999,
  newer_course_start_date: '2026-07-04',
  newer_course_remaining: 8,
});
assert.ok(newer.primary.includes('後續同科目'));
assert.ok(newer.primary.includes('開課 7/4'));
assert.ok(newer.primary.includes('剩 8 堂'));
assert.equal(primaryLeaksInternalId(newer.primary), false);
assert.equal(newer.shortBadge, '已有新課程');
assert.equal(newer.techId, 'SC #999');

// Director settle decision without course id:
// 「有後續同科目、開課較新」→ 可安心結案舊課
assert.ok(newer.primary.includes('開課 7/4') && !newer.primary.includes('#'));

// --- User Task: 續報完成確認 ---
const renewMsg = formatRenewSuccessMessage({
  kind: 'monthly',
  studentName: '王小明',
  subject: '英文',
});
assert.equal(renewMsg, '王小明的英文已建立新一期。舊期已結算，請到新一期帳單核帳。');
assert.equal(primaryLeaksInternalId(renewMsg), false);

const purchaseMsg = formatRenewSuccessMessage({
  kind: 'purchase',
  studentName: '王小明',
  subject: '數學',
  sessions: 8,
  firstDate: '2026-08-01',
  lastDate: '2026-09-20',
});
assert.ok(purchaseMsg.includes('加購 8 堂'));
assert.ok(purchaseMsg.includes('2026-08-01'));
assert.equal(primaryLeaksInternalId(purchaseMsg), false);

assert.ok(!formatDuplicatePurchaseHint({ subject: '理化' }).includes('#'));
assert.equal(formatDirectorPersonName({}), '名單上的學生');
assert.equal(formatDirectorPersonName({ student_name: '高瑞璞' }), '高瑞璞');

// --- User Task: 收費／看帳本 ---
assert.equal(formatLedgerCourseLabel({ course_ref: 'COURSE-000382' }), '本課程');
assert.equal(formatLedgerCourseLabel({ course_ref: '高中英文｜王老師' }), '高中英文｜王老師');
assert.equal(formatLedgerInvoiceLabel({ invoice_no: 'INV-1' }), 'INV-1');
assert.equal(formatLedgerInvoiceLabel({ id: 9 }), '帳單');
assert.equal(formatLedgerReceiptBillLine({ invoice_id: 1, course_ref: 'COURSE-1' }), '已套用帳單 · 本課程');
assert.ok(!formatLedgerAnomalyDetail({ payment_id: 3, invoice_id: 1, report_id: 2 }).includes('Payment'));

console.log('studentClassDisplay.test.js: all assertions passed');
