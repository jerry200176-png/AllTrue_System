// Presentation-only mapping for the director home bento (#4949 Phase A). Inputs are the values the page already loads.
const WAITING = new Set(['pending_report', 'amount_review']);
const isWaiting = (s) => WAITING.has(s?.payment_status);
const isRenewal = (s) => s?.alert_type === 'low_sessions'
  || (s?.alert_type === 'monthly_due_soon' && !(Number(s.days_until_settlement) < 0));

// Owed today = overdue (unpaid / monthly past due) + waiting for confirmation, from /alerts/tuition rows.
export function owedCard(alerts = []) {
  const waiting = alerts.filter(isWaiting).length;
  const overdue = alerts.filter((s) => !isWaiting(s) && !isRenewal(s)).length;
  return { overdue, waiting, total: overdue + waiting, intent: overdue ? 'unpaid' : 'pending_report' };
}

export const renewalCount = (alerts = []) => alerts.filter((s) => !isWaiting(s) && isRenewal(s)).length;

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
