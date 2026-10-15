// Billing redesign PRD v2 D1/D3/D8/D12: one row per student, built from the
// existing per-contract alert rows (same numbers as the queue), plus every
// student of the campus so a fully paid student is still findable.

const OWING = new Set(['unpaid', 'partial', 'pending_reconciliation', 'monthly_due_soon', 'renew_needed']);

const dayStart = (d) => new Date(`${String(d).slice(0, 10)}T00:00:00`);
const todayYmd = () => new Date().toISOString().slice(0, 10);

/** D8: a future due date is 'owed later'; no due date or due by today counts now. One rule for list and panel. */
export const isDueLater = (dueDate, today = todayYmd()) => Boolean(dueDate) && dayStart(dueDate) > dayStart(today);

/** Split open invoice balances [{ amount, due_date }] into owed-now / owed-later (same rule as the student list). */
export function splitOwed(items, today = todayYmd()) {
  let now = 0; let later = 0;
  for (const it of items || []) {
    const amount = Number(it.amount || 0);
    if (amount <= 0) continue;
    if (isDueLater(it.due_date, today)) later += amount; else now += amount;
  }
  return { now, later };
}

/**
 * What one alert row still owes, per bill: every open period when the server sends `open_periods` (same invoices the
 * student panel sums), else the single bill `payable_outstanding` describes (package rows, older payloads).
 */
export function owedItems(r) {
  if (Array.isArray(r.open_periods) && r.open_periods.length) {
    return r.open_periods.map((p) => ({ amount: Number(p.outstanding || 0), due_date: p.due_date || null }));
  }
  return [{ amount: Number(r.payable_outstanding ?? 0), due_date: r.due_date || null }];
}

/**
 * Founder D2 (in-app #394): an open bill whose amount is still to be confirmed (server `amount_review`).
 * The amount is the server's `pending_confirmation_amount`, the same resolver field 已結清 → 待核對 shows.
 */
export const isPendingConfirmation = (r) => r?.payment_status === 'amount_review' || r?.amount_pending_confirmation === true;
export const pendingReviewAmount = (r) => (isPendingConfirmation(r) ? Math.max(0, Number(r.pending_confirmation_amount ?? 0)) : 0);

/** Total a row still owes (all open bills); the campus-wide totals use the same number as the student list. */
export const rowOwedTotal = (r) => owedItems(r).reduce((sum, it) => sum + Math.max(0, it.amount), 0);

/** 「課程 000123」: the one contract reference the queue, the student list and the course page all show. */
export function formatCourseRef(id) {
  const n = Number(id);
  return Number.isInteger(n) && n > 0 ? `課程 ${String(n).padStart(6, '0')}` : '課程';
}

/** "2026-09-01 ~ 2026-12-31"; a missing end shows as an em dash (same as the queue table). */
export const formatCoursePeriod = (start, end) => (start || end ? `${start ? String(start).slice(0, 10) : '—'} ~ ${end ? String(end).slice(0, 10) : '—'}` : '—');

/**
 * @param {Array} alertRows rows from GET /alerts/tuition
 * @param {Array} students  [{ id, name }] of the campus (may be empty)
 * @param {string} today    YYYY-MM-DD
 */
export function buildStudentBillingRows(alertRows, students = [], today = new Date().toISOString().slice(0, 10)) {
  const byStudent = new Map();
  const ensure = (id, name) => {
    if (!byStudent.has(id)) {
      byStudent.set(id, {
        student_id: id, student_name: name || '', owed_now: 0, owed_later: 0, open_contracts: 0,
        waiting_confirm: 0, pending_review_amount: 0, pending_review_count: 0,
        overdue_days: 0, last_paid_at: null, first_class_id: null, contracts: [],
      });
    }
    return byStudent.get(id);
  };
  const now = dayStart(today);

  for (const r of alertRows || []) {
    const id = Number(r.student_id);
    if (!id) continue;
    const row = ensure(id, r.student_name);
    row.first_class_id ??= r.id != null ? Number(r.id) : null;
    if (r.last_paid_at && (!row.last_paid_at || r.last_paid_at > row.last_paid_at)) row.last_paid_at = r.last_paid_at;
    if (r.id != null) {
      row.contracts.push({
        id: Number(r.id), ref: formatCourseRef(r.id), period: formatCoursePeriod(r.course_start_date, r.course_end_date),
        owed: OWING.has(r.payment_status) && !isPendingConfirmation(r) ? rowOwedTotal(r) : 0, paid: Number(r.paid_amount ?? 0),
        pending_review: pendingReviewAmount(r),
      });
    }
    if (r.payment_status === 'pending_report') row.waiting_confirm += 1;
    // Founder D2: 金額待確認 is its own line, never part of owed_now / owed_later.
    if (pendingReviewAmount(r) > 0) {
      row.pending_review_amount += pendingReviewAmount(r);
      row.pending_review_count += 1;
    }
    if (!OWING.has(r.payment_status) || isPendingConfirmation(r)) continue;
    const items = owedItems(r);
    if (!items.some((it) => it.amount > 0)) continue;
    row.open_contracts += 1;
    for (const it of items) {
      if (it.amount <= 0) continue;
      // D8: due by today counts now; a future due date is "之後還會到期".
      if (isDueLater(it.due_date, today)) {
        row.owed_later += it.amount;
      } else {
        row.owed_now += it.amount;
        if (it.due_date) row.overdue_days = Math.max(row.overdue_days, Math.round((now - dayStart(it.due_date)) / 86400000));
      }
    }
  }
  for (const s of students || []) ensure(Number(s.id), s.name);

  return [...byStudent.values()].sort((a, b) => b.owed_now - a.owed_now
    || b.waiting_confirm - a.waiting_confirm
    || b.owed_later - a.owed_later
    || a.student_name.localeCompare(b.student_name, 'zh-Hant'));
}

export const STUDENT_FILTERS = {
  all: { label: '全部', test: () => true },
  owing: { label: '有未繳', test: (r) => r.owed_now > 0 },
  confirm: { label: '待核對入帳', test: (r) => r.waiting_confirm > 0 },
  pending_review: { label: '金額待確認', test: (r) => r.pending_review_count > 0 },
  clear: { label: '已繳清', test: (r) => r.owed_now === 0 && r.owed_later === 0 && r.waiting_confirm === 0 && !r.pending_review_count },
};
