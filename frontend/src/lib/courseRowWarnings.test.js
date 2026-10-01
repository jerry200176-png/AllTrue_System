import assert from 'node:assert/strict';
import { courseRowWarningItems, courseRowWarningSummary, usageBalanceWarningTitle as realUsageBalanceWarningTitle } from './courseRowWarnings.js';

const usageBalanceWarningTitle = () => '堂數對帳提示';

// No warnings at all.
assert.deepEqual(courseRowWarningItems({}, usageBalanceWarningTitle), []);
assert.deepEqual(courseRowWarningSummary({}, usageBalanceWarningTitle), []);

// Exactly one warning: summary passes it through unchanged.
const oneWarning = { hasSlotConflict: true };
const oneItems = courseRowWarningItems(oneWarning, usageBalanceWarningTitle);
assert.equal(oneItems.length, 1);
assert.equal(oneItems[0].tone, 'warning');
const oneSummary = courseRowWarningSummary(oneWarning, usageBalanceWarningTitle);
assert.deepEqual(oneSummary, oneItems);
assert.equal(oneItems[0].label, '⚠ 另一門課仍在同時段');
assert.match(oneItems[0].title, /不是重複課堂/);
assert.match(oneItems[0].title, /結束課程（不再續課）/);

// schedule_drift wins over contract_exception_count (else-if in source).
const driftOnly = courseRowWarningItems(
  { schedule_drift: true, contract_exception_count: 3 },
  usageBalanceWarningTitle,
);
assert.equal(driftOnly.length, 1);
assert.match(driftOnly[0].label, /堂次偏移/);
assert.match(driftOnly[0].title, /補課例外/); // drift title still mentions the exception count

// Multiple warnings collapse to one chip; tone picks the worst (danger > warning > info).
const multi = {
  hasSlotConflict: true, // warning
  contract_exception_count: 2, // info (schedule_drift is false)
  usage_balance_status: 'review_required', // danger
};
const multiSummary = courseRowWarningSummary(multi, usageBalanceWarningTitle);
assert.equal(multiSummary.length, 1);
assert.equal(multiSummary[0].tone, 'danger');
assert.equal(multiSummary[0].label, '⚠ 3 個提醒');
assert.match(multiSummary[0].title, /另一門課仍在同時段/);
assert.match(multiSummary[0].title, /補課例外/);
assert.match(multiSummary[0].title, /堂數對帳提示/);

const monthlyDiagnostic = {
  stored_remaining_sessions: 0,
  expected_remaining_sessions: null,
  class_session_used_sessions: 1,
  ledger_used_sessions: 0,
  cancelled_usage_artifacts: 0,
};
assert.doesNotMatch(realUsageBalanceWarningTitle({ usage_balance_diagnostic: monthlyDiagnostic }), /原始記錄為剩/);
assert.match(realUsageBalanceWarningTitle({ usage_balance_diagnostic: monthlyDiagnostic }), /已用堂數或扣堂紀錄不一致/);
assert.match(realUsageBalanceWarningTitle({ usage_balance_diagnostic: {
  ...monthlyDiagnostic, cancelled_usage_artifacts: 1,
} }), /已取消課堂/);
assert.match(realUsageBalanceWarningTitle({ usage_balance_diagnostic: {
  ...monthlyDiagnostic, stored_remaining_sessions: 1, expected_remaining_sessions: 0,
} }), /原始記錄為剩 1 堂/);
