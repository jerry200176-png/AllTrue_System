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

/** 要繳的錢: unpaid invoices summed; falls back to "has unpaid courses, amount unknown". */
export function pickAmountDue(invoices, paymentAlerts) {
  const open = (Array.isArray(invoices) ? invoices : [])
    .filter((i) => String(i?.Status ?? i?.status ?? '').toLowerCase() !== 'paid')
    .map((i) => ({ due: Math.max(0, (Number(i.TotalAmount ?? i.total_amount) || 0) - Math.max(0, netPaid(i))), dueDate: ymd(i.DueDate) }))
    .filter((i) => i.due > 0);
  if (open.length) {
    const dates = open.map((i) => i.dueDate).filter(Boolean).sort();
    return { amount: open.reduce((s, i) => s + i.due, 0), dueDateLabel: dates[0] ? md(dates[0]) : '' };
  }
  return Array.isArray(paymentAlerts) && paymentAlerts.length ? { amount: null, dueDateLabel: '' } : null;
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
