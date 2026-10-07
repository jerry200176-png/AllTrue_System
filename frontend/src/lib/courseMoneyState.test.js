import assert from 'node:assert/strict';
import * as M from './courseMoneyState.js';
import { packageMemberSessionSummary } from './packageSessions.js';

// ─── LEGACY ORACLES: verbatim copies of the page code this module replaced. ───
// The fixture table below asserts lib === each legacy copy for every fixture, and the
// DIVERGENCES block pins where the legacy copies disagree with each other.
const parseCourseNumber = (value) => { const n = Number(value); return Number.isFinite(n) ? n : null; };
const legacy = {
  // StudentsList.vue
  slRemaining: (c) => parseCourseNumber(c?.remaining_sessions ?? c?.RemainingSessions),
  slPkgMember: (c) => Number(c?.PackageID ?? c?.package_id ?? 0) > 0,
  slPkgTotal: (c) => { const t = Number(c?.package_total_sessions ?? c?.PackageTotalSessions ?? c?.sessions_purchased ?? 0); return Number.isFinite(t) && t > 0 ? t : 0; },
  slProgress(course) {
    if (legacy.slPkgMember(course)) return null;
    if (String(course?.payment_type || '').toLowerCase() === 'monthly') return null;
    const total = parseCourseNumber(course?.PackageID ? course?.package_total_sessions : course?.sessions_purchased);
    const remaining = parseCourseNumber(course?.PackageID ? course?.package_remaining_sessions : (course?.remaining_sessions ?? course?.RemainingSessions));
    if (total == null || total <= 0 || remaining == null || remaining < 0) return null;
    const reportedUsed = parseCourseNumber(course?.PackageID ? course?.package_used_sessions : (course?.used_sessions ?? course?.sessions_used));
    const used = Math.max(0, reportedUsed == null ? total - remaining : reportedUsed);
    const boundedUsed = Math.min(total, used);
    return { total, remaining, used: boundedUsed, percent: Math.min(100, Math.max(0, Math.round((boundedUsed / total) * 100))) };
  },
  slLow(course) {
    if (String(course?.payment_type || '').toLowerCase() === 'monthly') return false;
    if (course?.PackageID) { const pr = Number(course?.package_remaining_sessions ?? NaN); if (!Number.isFinite(pr)) return false; return pr <= 2; }
    const r = legacy.slRemaining(course); if (r == null) return false; return r <= 2;
  },
  slModalRemaining: (s) => (s?.PackageID ? (s?.package_remaining_sessions ?? 0) : (s?.remaining_sessions ?? 0)),
  // CourseManagement.vue + useCourseSessionsDisplay.js
  cmCloseRemaining: (c) => { const v = Number(c?.remaining_sessions ?? c?.RemainingSessions); return Number.isFinite(v) ? v : null; },
  cmRaw: (c) => { const v = c?.remaining_sessions ?? c?.RemainingSessions; return Number.isFinite(Number(v)) ? Number(v) : null; },
  cmPkgTotal: (c) => { const t = Number(c?.package_total_sessions ?? c?.PackageTotalSessions ?? c?.sessions_purchased ?? 0); return Number.isFinite(t) && t > 0 ? t : 0; },
  cmPkgUsed(c) {
    const total = legacy.cmPkgTotal(c);
    const remaining = Number(c?.package_remaining_sessions ?? c?.PackageRemainingSessions ?? 0);
    const used = total - (Number.isFinite(remaining) ? remaining : 0);
    return used > 0 ? used : 0;
  },
  cmIsSessionMode: (c) => { const t = String(c?.payment_type || '').trim(); if (t) return t === 'session'; return Number(c?.sessions_purchased ?? c?.SessionCount ?? 0) > 0; },
  cmPkgDisplay: (c) => Math.max(0, Number(c?.package_remaining_sessions ?? 0) || 0),
  cmPurchased: (c) => Math.max(0, Number(c?.sessions_purchased ?? c?.SessionCount ?? 0) || 0),
  // ParentPortal.vue
};

// ─── fixture table ───
const F = {
  sessionCourse: { id: 1, payment_type: 'session', sessions_purchased: 10, remaining_sessions: 7, used_sessions: 3 },
  sessionLow: { id: 2, payment_type: 'session', sessions_purchased: 10, remaining_sessions: 2 },
  sessionEmpty: { id: 3, payment_type: 'session', sessions_purchased: 10, remaining_sessions: 0 },
  sessionNoRemaining: { id: 4, payment_type: 'session', sessions_purchased: 10 },
  legacyCapitalized: { id: 5, payment_type: 'session', SessionCount: 12, RemainingSessions: 1 },
  packageMember: { id: 6, payment_type: 'session', PackageID: 9, package_total_sessions: 12, package_remaining_sessions: 2, sessions_purchased: 12, remaining_sessions: 99 },
  packageMemberHealthy: { id: 7, payment_type: 'session', PackageID: 9, package_total_sessions: 12, package_remaining_sessions: 8 },
  packageNoPool: { id: 8, payment_type: 'session', PackageID: 9, sessions_purchased: 12 },
  packageLowercaseIdOnly: { id: 9, payment_type: 'session', package_id: 9, package_total_sessions: 6, package_remaining_sessions: 1 },
  packagePascalPool: { id: 10, payment_type: 'session', PackageID: 9, PackageTotalSessions: 6, PackageRemainingSessions: 1 },
  monthly: { id: 11, payment_type: 'monthly', remaining_sessions: 0, schedule_mode: 'date', payment_status: 'unpaid' },
  monthlyCapitalized: { id: 12, payment_type: 'Monthly', remaining_sessions: 0 },
  blankType: { id: 13, payment_type: '', sessions_purchased: 5, remaining_sessions: 5 },
  noTypeNoSessions: { id: 14 },
  hourly: { id: 15, payment_type: 'hourly', remaining_sessions: 1 },
  paid: { id: 16, payment_type: 'session', sessions_purchased: 4, remaining_sessions: 4, payment_status: 'paid', Paid: 4000, Charge: 4000 },
  partial: { id: 17, payment_type: 'session', sessions_purchased: 4, remaining_sessions: 4, payment_status: 'partial', Paid: 1000, Charge: 4000 },
  waived: { id: 18, payment_type: 'session', sessions_purchased: 4, remaining_sessions: 4, payment_status: 'waived' },
  stringRemaining: { id: 19, payment_type: 'session', sessions_purchased: '8', remaining_sessions: '3' },
  nullRemainingFallsToPascal: { id: 20, payment_type: 'session', sessions_purchased: 8, remaining_sessions: null, RemainingSessions: 6 },
  nullRemaining: { id: 22, payment_type: 'session', sessions_purchased: 10, remaining_sessions: null },
  bothRemainingNull: { id: 23, payment_type: 'session', sessions_purchased: 10, remaining_sessions: null, RemainingSessions: null },
  sessionsUsedNull: { id: 24, payment_type: 'session', sessions_purchased: 10, remaining_sessions: 4, sessions_used: null, used_sessions: null },
  packageNullPool: { id: 25, payment_type: 'session', PackageID: 9, package_total_sessions: 12, package_remaining_sessions: null },
  junk: { id: 21, payment_type: 'session', sessions_purchased: 'x', remaining_sessions: 'abc' },
};

for (const [name, c] of Object.entries(F)) {
  const at = (label) => `${name}: ${label}`;
  assert.equal(M.ownRemainingSessions(c), legacy.slRemaining(c), at('own remaining == StudentsList'));
  assert.equal(M.ownRemainingSessions(c), legacy.cmRaw(c), at('own remaining == CourseManagement raw'));
  assert.equal(M.ownRemainingSessions(c), legacy.cmCloseRemaining(c), at('own remaining == close helper'));
  assert.equal(M.isPackageMember(c), legacy.slPkgMember(c), at('isPackageMember'));
  assert.equal(M.poolTotalSessions(c, { fallbackToPurchased: true }), legacy.slPkgTotal(c), at('pool total (SL)'));
  assert.equal(M.poolTotalSessions(c, { fallbackToPurchased: true }), legacy.cmPkgTotal(c), at('pool total (CM)'));
  assert.equal(M.poolUsedSessions(c), legacy.cmPkgUsed(c), at('pool used'));
  if (name !== 'packageLowercaseIdOnly') { // unified below: package_id alone now counts as a package everywhere
    assert.deepEqual(M.courseProgress(c), legacy.slProgress(c), at('courseProgress'));
    assert.equal(M.isSessionPaymentLow(c), legacy.slLow(c), at('list low badge'));
    assert.equal(M.modalRemainingSessions(c), legacy.slModalRemaining(c), at('加購 modal remaining'));
  }
  assert.equal(M.isSessionPayment(c), legacy.cmIsSessionMode(c), at('CM isSessionMode'));
  assert.equal(M.purchasedSessions(c), legacy.cmPurchased(c), at('purchased'));
  assert.equal(M.isMonthlyPaymentType(c), String(c?.payment_type || '').toLowerCase() === 'monthly', at('monthly type'));
}

// packageMemberSessionSummary keeps its exact outputs after switching to the shared normalizers
assert.deepEqual(packageMemberSessionSummary(F.packageMember, { completed: 3, cancelled: 1 }), {
  isPackage: true, total: 12, used: 10, remaining: 2, purchased: 12,
  text: '本科已上 3 堂｜方案共用 12 堂（已用 10 / 剩 2），1 堂已取消',
});
assert.equal(packageMemberSessionSummary(F.packagePascalPool).remaining, 1);
assert.equal(packageMemberSessionSummary(F.packageNoPool).total, 0, 'summary does NOT fall back to sessions_purchased');
assert.equal(packageMemberSessionSummary(F.sessionCourse, { completed: 3 }).text, '已上 3 / 購買 10 堂');
assert.equal(packageMemberSessionSummary(F.monthly, { completed: 2 }).text, '已上 2 堂');

// ─── UNIFIED (Founder-approved 2026-10-06) ───
// Rule 4: monthly is payment_type === 'monthly', case-insensitive, on every page (one function, one answer).
assert.equal(M.isMonthlyPaymentType(F.monthlyCapitalized), true);
assert.equal(M.isMonthlyPaymentType(F.monthly), true);
assert.equal(M.isMonthlyPaymentType(F.hourly), false, 'unknown billing type is not monthly');
assert.equal(M.isMonthlyPaymentType(F.noTypeNoSessions), false);
assert.equal(M.isSessionPayment(F.noTypeNoSessions), false);
// the backend emits only 'session' | 'monthly' (ScheduleMode count -> session), so no real course changes category
for (const [mode, type] of [['count', 'session'], ['date', 'monthly']]) {
  assert.equal(M.isMonthlyPaymentType({ payment_type: type, schedule_mode: mode }), mode !== 'count');
}
// D2 pool total: summary never falls back to sessions_purchased; StudentsList/CM do; progress ignores Pascal alias.
assert.equal(M.poolTotalSessions(F.packageNoPool), null);
assert.equal(M.poolTotalSessions(F.packageNoPool, { fallbackToPurchased: true }), 12);
// Rule 5: PackageID or package_id counts everywhere (list badge / progress / modal follow the pool too).
assert.equal(M.isPackageMember(F.packageLowercaseIdOnly), true);
assert.equal(M.isPackageMember({ PackageID: 3 }), true);
assert.equal(M.isPackageMember({ PackageID: 0, package_id: 0 }), false);
assert.equal(M.isSessionPaymentLow(F.packageLowercaseIdOnly), true, 'pool remaining 1 is low for a package_id-only course');
assert.equal(M.courseProgress(F.packageLowercaseIdOnly), null);
assert.equal(M.modalRemainingSessions(F.packageLowercaseIdOnly), 1);
// D4 加購 modal: missing remaining counts as 0 -> low hint; list badge says not low (null).
assert.equal(M.modalRemainingSessions(F.sessionNoRemaining), 0);
assert.equal(M.isSessionPaymentLow(F.sessionNoRemaining), false);
// D5 加購 modal has no monthly exclusion and ignores the Pascal alias.
assert.equal(M.modalRemainingSessions(F.monthly), 0);
assert.equal(M.modalRemainingSessions(F.legacyCapitalized), 0);
assert.equal(M.ownRemainingSessions(F.legacyCapitalized), 1);
// D6 Pascal pool aliases: honored by summary/used/total, ignored by progress, list badge and CM display (server never emits them).
assert.equal(M.poolRemainingSessions(F.packagePascalPool), 1);
for (const [name, c] of Object.entries(F)) {
  assert.equal(Math.max(0, M.poolRemainingSessions(c, { pascalAlias: false }) ?? 0), legacy.cmPkgDisplay(c), `${name}: CM display ignores Pascal pool alias`);
}
// D9 explicit null coerces to 0 in every legacy copy (parseCourseNumber / Number(null))
assert.equal(M.ownRemainingSessions(F.bothRemainingNull), 0);
assert.equal(M.ownRemainingSessions(F.nullRemaining), null, 'null ?? undefined -> undefined -> no value');
assert.equal(M.courseProgress(F.sessionsUsedNull).used, 0, 'sessions_used:null -> reported used 0, not total-remaining');
assert.equal(M.isSessionPaymentLow(F.packagePascalPool), false);
assert.equal(legacy.cmPkgDisplay(F.packagePascalPool), 0);

// ─── thresholds / tone ───
assert.equal(M.LOW_SESSIONS_THRESHOLD, 2);
assert.deepEqual([3, 2, 1, 0, -1].map((n) => M.isLowRemaining(n)), [false, true, true, true, true]);
assert.equal(M.isLowRemaining(null), false);
assert.deepEqual([0, 1, 2, 3, 4, 5, null].map((n) => M.remainingTone(n)), ['empty', 'low', 'low', 'watch', 'watch', 'ok', 'empty']);
assert.equal(M.remainingTone(8, { watchAt: 8 }), 'watch');
assert.equal(M.remainingTone(9, { watchAt: 8 }), 'ok');

// ─── payment status labels: one wording per status (Rule 6) ───
assert.equal(M.TUITION_STATUS_CONFIG.paid.label, '已確認入帳');
assert.equal(M.TUITION_STATUS_CONFIG.partial.label, '部分繳');
assert.equal(M.TUITION_STATUS_CONFIG.waived.label, '確認不收', 'Rule 1: the alert ladder shows waived too');
assert.equal(M.WAIVED_LABEL, '確認不收');
assert.equal(M.TUITION_STATUS_CONFIG.pending_report.cls, 'st-pending');
assert.equal(M.TUITION_STATUS_CONFIG.pending_reconciliation.label, '結案／待查帳');
assert.equal(M.INVOICE_STATUS_LABELS.partial, '部分繳');
assert.equal(M.REPORT_STATUS_LABELS.confirmed, '已入帳');
assert.equal(M.REPORT_STATUS_LABELS.voided, '已撤銷', 'ledger keeps its voided label');

// ─── folded monthlyPaymentDisplay ───
assert.equal(M.monthlyPaymentLabel({ monthly_payment: { billing_period: '2026-09', payment_status: 'unpaid' }, payment_status: 'paid' }), '2026-09 未繳費');
assert.equal(M.monthlyPaymentLabel({ monthly_payment: { review_required: true }, payment_status: 'paid' }), '付款期間待確認');
assert.equal(M.monthlyPaymentLabel({ payment_status: 'paid' }), null);
assert.equal(M.monthlyPaymentLabel({ monthly_payment: { billing_period: '2026-09', payment_status: 'unpaid' }, payment_status: 'pending_report' }), '2026-09 待對帳');
assert.equal(M.periodPaymentLabel('partial'), '部分繳');

// ─── closed-reason verdict: legacy oracles (verbatim page copies) + pinned divergences ───
const cmClosed = (c) => {
  if (c.closed_reason) return c.closed_reason;
  if (c.status === 'inactive' && M.isSessionPayment(c) && c.payment_status === 'paid' && Number(c.remaining_sessions ?? 0) <= 0) return 'completed';
  if (c.status === 'inactive' && !M.isSessionPayment(c)) return 'completed';
  return null;
};
const slClosed = (course) => {
  const settled = (x) => { const st = String(x?.payment_status || '').toLowerCase(); return x?._noncanonical || !st ? null : st === 'paid'; };
  if (course?.closed_reason) return course.closed_reason;
  if (String(course?.status || '').toLowerCase() === 'inactive' && course?.payment_type === 'session' && settled(course)
    && M.ownRemainingSessions(course) != null && M.ownRemainingSessions(course) <= 0) return 'completed';
  if (String(course?.status || '').toLowerCase() === 'inactive' && course?.payment_type !== 'session') return 'completed';
  return null;
};
const base = { status: 'inactive', payment_type: 'session', payment_status: 'paid', remaining_sessions: 0 };
// [name, course, unified, cm legacy, sl legacy]
const closedCases = [
  ['server reason wins', { ...base, closed_reason: 'waived' }, 'waived', 'waived', 'waived'],
  ['active course', { ...base, status: 'active' }, null, null, null],
  ['paid + used up', base, 'completed', 'completed', 'completed'],
  ['unpaid + used up', { ...base, payment_status: 'unpaid' }, null, null, null],
  ['paid, sessions left', { ...base, remaining_sessions: 3 }, null, null, null],
  ['monthly inactive', { ...base, payment_type: 'monthly' }, 'completed', 'completed', 'completed'],
  // DIVERGENCES (unified rule = SL, the stricter one)
  ['remaining missing', { ...base, remaining_sessions: undefined }, null, 'completed', null],
  ['_noncanonical paid', { ...base, _noncanonical: true }, null, 'completed', null],
  ['Pascal remaining', { ...base, remaining_sessions: undefined, RemainingSessions: 0 }, 'completed', 'completed', 'completed'],
  ['status case', { ...base, status: 'Inactive' }, 'completed', null, 'completed'],
  ['no payment_type, purchased>0, unpaid', { status: 'inactive', sessions_purchased: 8, payment_status: 'unpaid', remaining_sessions: 2 }, null, null, 'completed'],
  ['no payment_type, purchased=0', { status: 'inactive', payment_status: 'paid', remaining_sessions: 0 }, 'completed', 'completed', 'completed'],
];
for (const [name, c, unified, cm, sl] of closedCases) {
  assert.equal(M.closedReason(c), unified, `unified: ${name}`);
  assert.equal(cmClosed(c), cm, `cm legacy: ${name}`);
  assert.equal(slClosed(c), sl, `sl legacy: ${name}`);
}
assert.equal(M.closedReason(null), null);
assert.deepEqual(['settled', 'settled_pending', 'waived', 'contract_amended', 'completed', 'converted_trial', 'x', null].map(M.isClosedReason),
  [true, true, true, true, true, true, false, false]);
const hist = (reason) => ({ closed_reason: reason });
assert.deepEqual(['settled', 'completed', 'waived', 'settled_pending', 'contract_amended', 'converted_trial', null].map((r) => M.isHistoryCourse(hist(r))),
  [true, true, true, false, false, false, false], 'CourseManagement set');
assert.deepEqual(['settled', 'completed', 'waived', 'settled_pending', 'contract_amended', 'converted_trial', null].map((r) => M.isHistoryCourse(hist(r), { includePending: true })),
  [true, true, true, true, false, false, false], 'StudentsList set adds settled_pending');

console.log('courseMoneyState passed');
