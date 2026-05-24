import assert from 'node:assert/strict';
import { normalizeUniversalScheduleErrorMessage } from './universalSchedulerErrorMessage.js';

const mappedMessage = normalizeUniversalScheduleErrorMessage(
  {
    errors: {
      end_date: ['指定期間內無任何排課日。'],
    },
  },
  '',
  422
);

assert.equal(
  mappedMessage,
  '選擇的期間內沒有符合固定星期的上課日，請調整結束日或上課星期。',
  'end_date no-occurrence validation should map to user-friendly guidance'
);

const labeledMessage = normalizeUniversalScheduleErrorMessage(
  {
    errors: {
      monthly_sessions: ['月結課程的 monthly_sessions 為必填，且須大於 0。'],
    },
  },
  '',
  422
);

assert.equal(
  labeledMessage,
  '本月預排堂數：月結課程的 monthly_sessions 為必填，且須大於 0。',
  'known validation fields should use user-facing labels'
);

const fallbackMessage = normalizeUniversalScheduleErrorMessage({}, 'internal server error', 500);
assert.equal(
  fallbackMessage,
  '排課請求失敗 (HTTP 500) - internal server error',
  'raw text should be used as fallback when structured payload is absent'
);
