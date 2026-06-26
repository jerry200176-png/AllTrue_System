import assert from 'node:assert/strict';
import { normalizeUniversalScheduleErrorMessage } from './universalSchedulerErrorMessage.js';
import { isDuplicateInterceptCode } from './universalSchedulerDuplicateCode.js';

// #174：同科目同老師日期重疊（overlapping_active_course）也必須被視為「重複課程攔截」，
// 才會走前端的「仍要新增課程」modal；否則訊息只會被原生 alert 丟出，使用者被叫去
// 「勾選強制建立」卻找不到任何選項（in-app #174）。
assert.equal(isDuplicateInterceptCode('duplicate_active_course'), true,
  'duplicate_active_course should trigger the in-app force-create intercept');
assert.equal(isDuplicateInterceptCode('overlapping_active_course'), true,
  'overlapping_active_course (同老師日期重疊) should also trigger the intercept, not a dead-end alert');
assert.equal(isDuplicateInterceptCode('some_other_error'), false,
  'unrelated error codes should not be treated as duplicate-course intercepts');
assert.equal(isDuplicateInterceptCode(undefined), false,
  'missing code should not be treated as a duplicate-course intercept');

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
