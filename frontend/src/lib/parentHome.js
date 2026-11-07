import { canRequestParentLeave } from './parentLeavePolicy';

const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];
const ymd = (v) => String(v ?? '').substring(0, 10);
const dayNumber = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000 : null;
};
const md = (s) => { const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(s); return m ? `${+m[1]}/${+m[2]}` : ''; };

/** 下一堂課: first upcoming session still on the calendar. `today` is a YYYY-MM-DD string. */
export function pickNextLesson(sessions, today, { actionsEnabled = true } = {}) {
  const s = (Array.isArray(sessions) ? sessions : []).find((x) => (
    ['scheduled', 'rescheduled', 'leave_requested'].includes(String(x?.Status || '').toLowerCase())
    && ymd(x?.SessionDate) >= today
    && x?.is_past_or_started !== true
  ));
  if (!s) return null;
  const date = ymd(s.SessionDate);
  const d = dayNumber(date);
  const t = dayNumber(today);
  return {
    session: s,
    dateLabel: md(date),
    weekday: d == null ? '' : WEEKDAYS[new Date(d * 86400000).getUTCDay()],
    time: String(s.StartTime || '').substring(0, 5),
    subject: s.Subject || '課程',
    teacher: s.teacher_name || '',
    daysLeft: d == null || t == null ? null : d - t,
    leaveRequested: String(s.Status).toLowerCase() === 'leave_requested',
    canLeave: canRequestParentLeave(s, actionsEnabled),
  };
}

const netPaid = (inv) => (inv.payments || []).reduce((sum, p) => {
  const a = Number(p?.Amount) || 0;
  return sum + (String(p?.Method || '') === 'void' ? -Math.abs(a) : a);
}, 0);

/**
 * 要繳的錢: unpaid invoices summed. A stored-"paid" invoice whose Payment rows fall short may still owe
 * (backend Invoice::mayOweSql, #3781); this card does not re-derive that rule, it shows `review: true` (確認中).
 */
// NT$ has no minor unit: round to whole dollars so float noise (7200.300000000001) never shows. Change CURRENCY_DECIMALS if the currency changes.
const CURRENCY_DECIMALS = 0;
const roundMoney = (n) => Number(Math.round(Number(`${n}e${CURRENCY_DECIMALS}`)) + `e-${CURRENCY_DECIMALS}`);

export function pickAmountDue(invoices, paymentAlerts) {
  const list = Array.isArray(invoices) ? invoices : [];
  const isPaid = (i) => String(i?.Status ?? i?.status ?? '').toLowerCase() === 'paid';
  const total = (i) => Number(i.TotalAmount ?? i.total_amount) || 0;
  const staleSuspect = list.some((i) => isPaid(i) && Array.isArray(i.payments) && netPaid(i) < total(i));
  const open = list.filter((i) => !isPaid(i))
    .map((i) => ({ due: Math.max(0, total(i) - Math.max(0, netPaid(i))), dueDate: ymd(i.DueDate), classId: Number(i.StudentClassID ?? i.student_class_id) }))
    .filter((i) => i.due > 0);
  const dates = open.map((i) => i.dueDate).filter(Boolean).sort();
  const dueDateLabel = dates[0] ? md(dates[0]) : '';
  // An unpaid course with no open bill (resolver `unbilled`) still owes, but has no invoice amount here.
  // Matched by course: any alerted course without an open bill makes the total unknown, never a short sum.
  const billed = new Set(open.map((i) => i.classId));
  const unbilled = (Array.isArray(paymentAlerts) ? paymentAlerts : []).some((a) => !billed.has(Number(a?.class_id)));
  // Nothing billed yet: owed but amount unknown (有課程待繳). Some billed, some not: 確認中 rather than a short sum.
  if (staleSuspect || (unbilled && open.length)) return { amount: null, dueDateLabel, review: true, count: open.length };
  if (open.length) return { amount: roundMoney(open.reduce((sum, i) => sum + i.due, 0)), dueDateLabel, count: open.length };
  return unbilled ? { amount: null, dueDateLabel: '', count: 0 } : null;
}

/** 老師的新回饋: latest record with an unread teacher reply, else the latest record. */
export function pickLatestFeedback(records) {
  const list = Array.isArray(records) ? records : [];
  const rec = list.find((r) => r?.parent_feedback?.has_unread_reply) || list[0];
  if (!rec) return null;
  const date = ymd(rec.SessionDate);
  return {
    isNew: !!rec.parent_feedback?.has_unread_reply,
    dateLabel: md(date),
    subject: rec.Subject || '課程',
    text: String(
      (rec.parent_feedback?.has_unread_reply && [...rec.parent_feedback.replies].reverse().find((r) => r.author_role !== 'parent')?.content)
      || rec.Comment || rec.Progress || rec.Content || '',
    ).trim(),
  };
}
