import assert from 'node:assert/strict';
import { courseRowWarningItems, courseRowWarningSummary } from './courseRowWarnings.js';

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
assert.match(multiSummary[0].title, /與另一堂時段重疊/);
assert.match(multiSummary[0].title, /補課例外/);
assert.match(multiSummary[0].title, /堂數對帳提示/);
