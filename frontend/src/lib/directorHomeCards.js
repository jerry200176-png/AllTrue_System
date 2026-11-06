// Presentation-only mapping for the director home bento (#4949 Phase A). Inputs are the values the page already loads.
// Classify by the alert's own payment_status (server: ContractMoneyState::alertStatus), not alert_type alone:
// an unpaid/partial monthly invoice is owed money, not a renewal.
const OWED = new Set(['unpaid', 'partial', 'pending_report', 'amount_review', 'pending_reconciliation']);
const isOwed = (s) => (s?.payment_status ? OWED.has(s.payment_status) : s?.alert_type === 'unpaid');
const isRenewal = (s) => !isOwed(s) && (s?.alert_type === 'low_sessions' || s?.alert_type === 'monthly_due_soon');

// Owed today from /alerts/tuition rows. `intent` = the 帳務中心 filter of the biggest group
// (unpaid -> todo, pending_report -> confirm, amount_review -> review: LIST_FILTER_FOR_TAB).
export function owedCard(alerts = []) {
  const owed = alerts.filter(isOwed);
  const confirm = owed.filter((s) => s.payment_status === 'pending_report').length;
  const review = owed.filter((s) => s.payment_status === 'amount_review').length;
  const overdue = owed.length - confirm - review;
  const intent = [['unpaid', overdue], ['pending_report', confirm], ['amount_review', review]].sort((a, b) => b[1] - a[1])[0][0];
  return { overdue, waiting: confirm + review, total: owed.length, intent };
}

export const renewalCount = (alerts = []) => alerts.filter(isRenewal).length;

// Oldest pending evaluation, in whole days before `todayYmd` (null when nothing is dated).
export function oldestEvaluationAge(evaluations = [], todayYmd) {
  const days = evaluations
    .map((e) => String(e?.SessionDate || e?.session_date || '').slice(0, 10))
    .filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(d))
    .sort();
  if (!days.length || !todayYmd) return null;
  return Math.max(0, Math.round((Date.parse(`${todayYmd}T00:00:00Z`) - Date.parse(`${days[0]}T00:00:00Z`)) / 864e5));
}

// Plain-word row titles for the 今天要處理 list; unknown ids keep their own title.
const PLAIN_TITLES = Object.freeze({
  attendance: '沒點名的課',
  'parent-leave': '家長請假',
  tuition: '催繳與續課',
  makeup: '補點名要補登',
  'schedule-discrepancy': '老師回報課表不對',
  'duplicate-review': '重疊的課要審',
  evaluations: '評量要審',
  'parent-feedback': '家長新回饋',
  inbox: '新通知',
});
export const plainTaskTitle = (task) => PLAIN_TITLES[task?.id] || task?.title || '待辦';

// Pending evaluations per lesson day for the last 7 days (oldest first), from the already-loaded list. Null when nothing falls in range.
export function sevenDayEvalCounts(evaluations = [], todayYmd) {
  if (!todayYmd) return null;
  const base = Date.parse(`${todayYmd}T00:00:00Z`);
  const counts = Array(7).fill(0);
  for (const e of evaluations) {
    const d = String(e?.SessionDate || e?.session_date || '').slice(0, 10);
    const back = Math.round((base - Date.parse(`${d}T00:00:00Z`)) / 864e5);
    if (Number.isFinite(back) && back >= 0 && back < 7) counts[6 - back] += 1;
  }
  return counts.some((n) => n > 0) ? counts : null;
}
