/**
 * scheduleDisplay — User Task: 排課／調課（同一 domain 使用者語言）
 */
import assert from 'node:assert/strict';
import {
  humanizeScheduleFieldTokens,
  formatScheduleErrorMessage,
  formatRescheduleConfirmDialog,
  formatRescheduleSuccessMessage,
  formatRescheduleConflictStudents,
  humanizeRescheduleFailure,
  MAKEUP_SLOT_QUERY_LABEL,
  RESCHEDULE_ACTION_DESC,
} from './scheduleDisplay.js';
import { normalizeUniversalScheduleErrorMessage } from './universalSchedulerErrorMessage.js';
import { primaryLeaksInternalId } from './studentClassDisplay.js';

assert.equal(
  humanizeScheduleFieldTokens('月結課程的 monthly_sessions 為必填，且須大於 0。'),
  '月結課程的 本月預排堂數 為必填，且須大於 0。',
);

const endDateMsg = formatScheduleErrorMessage(
  { errors: { end_date: ['指定期間內無任何排課日。'] } },
  '',
  422,
);
assert.equal(
  endDateMsg,
  '選擇的期間內沒有符合固定星期的上課日，請調整結束日或上課星期。',
);

const monthlyMsg = formatScheduleErrorMessage(
  {
    errors: {
      monthly_sessions: ['月結課程的 monthly_sessions 為必填，且須大於 0。'],
    },
  },
  '',
  422,
);
assert.equal(monthlyMsg, '請填寫本月預排堂數，且須大於 0。');
assert.ok(!monthlyMsg.includes('monthly_sessions'), 'must not leak snake_case field');
assert.equal(primaryLeaksInternalId(monthlyMsg), false);

const fallback = formatScheduleErrorMessage({}, 'internal server error', 500);
assert.equal(
  fallback,
  '排課沒有完成，請檢查學生、老師、日期與上課星期後再試一次。',
);
assert.ok(!fallback.includes('HTTP'), 'must not show HTTP status to directors');
assert.ok(!/internal server error/i.test(fallback));

const forbidden = formatScheduleErrorMessage({}, '', 403);
assert.ok(forbidden.includes('權限'));
assert.ok(!forbidden.includes('HTTP'));

// Back-compat alias still works
assert.equal(
  normalizeUniversalScheduleErrorMessage({}, 'internal server error', 500),
  fallback,
);

// --- User Task: 調課 — 確認／成功／撞課 人話 + 跨頁一致 ---
assert.equal(MAKEUP_SLOT_QUERY_LABEL, '查詢老師可補課時段');
assert.equal(RESCHEDULE_ACTION_DESC, '將原本的課程改到新的日期時間');

const confirmDlg = formatRescheduleConfirmDialog({
  studentName: '王小明',
  subject: '英文',
  originalDate: '2026-07-20',
  originalStart: '16:00',
  originalEnd: '18:00',
  newDate: '2026-07-22',
  newStart: '17:00',
  newEnd: '19:00',
});
assert.ok(confirmDlg.includes('王小明的英文'));
assert.ok(confirmDlg.includes('原本：2026-07-20 16:00~18:00'));
assert.ok(confirmDlg.includes('改為：2026-07-22 17:00~19:00'));
assert.ok(!confirmDlg.includes('原堂改期'));
assert.ok(!confirmDlg.includes('新堂排入'));
assert.ok(!confirmDlg.includes('課程編修'));
assert.equal(primaryLeaksInternalId(confirmDlg), false);

const success = formatRescheduleSuccessMessage({
  studentName: '王小明',
  subject: '英文',
  originalDate: '2026-07-20',
  originalStart: '16:00',
  originalEnd: '18:00',
  newDate: '2026-07-22',
  newStart: '17:00',
  newEnd: '19:00',
});
assert.equal(success, '王小明的英文已從 2026-07-20 16:00~18:00 改到 2026-07-22 17:00~19:00。');
assert.equal(primaryLeaksInternalId(success), false);

const conflict = formatRescheduleConflictStudents([
  { student_name: '林小華' },
  { student_id: 99 },
]);
assert.ok(conflict.includes('林小華'));
assert.ok(!conflict.includes('#99'));
assert.ok(!conflict.includes('學生 #'));

// #2006：同學生有多筆課程重疊時，帶出科目/期別避免與請假中的另一筆課程混淆
const conflictWithSubject = formatRescheduleConflictStudents([
  { student_name: '王品方', subject_name: '理化', course_period: '7/11期' },
]);
assert.equal(conflictWithSubject, '此時段已有：王品方（理化・7/11期）');

assert.equal(
  humanizeRescheduleFailure('無法寫入原堂次紀錄'),
  '無法更新原本的上課時間，請稍後再試。',
);
assert.equal(
  humanizeRescheduleFailure('SQLSTATE[23000]: Integrity constraint'),
  '調課沒有完成，請確認新日期與時間後再試。',
);

console.log('scheduleDisplay.test.js: all assertions passed');
