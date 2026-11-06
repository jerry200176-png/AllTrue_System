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

// Lessons per start hour; `open` = not yet marked (status 'scheduled', same rule as pendingAttendanceCount).
export function hourBuckets(schedules = []) {
  const map = new Map();
  for (const s of schedules) {
    const h = Number(String(s?.start_time || '').slice(0, 2));
    if (!Number.isFinite(h) || !s?.start_time) continue;
    const b = map.get(h) || { hour: h, count: 0, open: 0 };
    b.count += 1;
    if (s.status === 'scheduled') b.open += 1;
    map.set(h, b);
  }
  return [...map.values()].sort((a, b) => a.hour - b.hour);
}
