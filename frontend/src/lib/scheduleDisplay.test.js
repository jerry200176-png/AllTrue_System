/**
 * scheduleDisplay — User Task: 排課
 * Directors must never see snake_case fields or HTTP crumbs when create fails.
 */
import assert from 'node:assert/strict';
import {
  humanizeScheduleFieldTokens,
  formatScheduleErrorMessage,
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

console.log('scheduleDisplay.test.js: all assertions passed');
