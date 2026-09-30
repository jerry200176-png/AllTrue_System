import { getRenewalPreviewAmount } from './coursePricing.js';

export function invalidateMonthlyRenewalPreview(form, endDate) {
  Object.assign(form, { preview_status: 'loading', preview_end_date: endDate, preview_start_date: '',
    preview_billing_period: '', preview_due_date: '', preview_blocked: true, preview_error: '' });
}

export function applyMonthlyRenewalPreview(form, preview) {
  const amount = getRenewalPreviewAmount(preview);
  if (amount != null) form.original_amount = amount;
  Object.assign(form, { preview_status: 'ready', preview_start_date: preview?.proposed_course?.start_date || '',
    preview_billing_period: preview?.billing?.invoice?.billing_period || '', preview_due_date: preview?.billing?.invoice?.due_date || '',
    preview_blocked: preview?.severity === 'blocked', preview_error: '' });
}

export function canSubmitMonthlyRenewal(form, endDate) {
  return form?.preview_status === 'ready' && !form?.preview_blocked && Boolean(form?.preview_start_date)
    && Boolean(endDate) && form.preview_end_date === endDate;
}

/**
 * Next period end after `months` months. A month-end end date stays month-end
 * (09-30 + 1 -> 10-31); other days clamp to the target month (01-31 + 1 -> 02-28).
 * No/invalid base date counts from today.
 */
export function addMonthsToPeriodEnd(ymd, months = 1, today = new Date()) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
  const [y, mo, d] = m ? [Number(m[1]), Number(m[2]) - 1, Number(m[3])] : [today.getFullYear(), today.getMonth(), today.getDate()];
  const lastDay = (year, month) => new Date(year, month + 1, 0).getDate();
  const target = new Date(y, mo + Number(months || 1), 1);
  const ty = target.getFullYear(), tm = target.getMonth();
  const day = d >= lastDay(y, mo) ? lastDay(ty, tm) : Math.min(d, lastDay(ty, tm));
  return `${ty}-${String(tm + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

const ymdOf = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parseYmd = (ymd) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
};

/** First day of the next period (old end + 1); no end date means tomorrow. */
export function nextPeriodStart(ymd, today = new Date()) {
  const base = parseYmd(ymd) || new Date(today.getFullYear(), today.getMonth(), today.getDate());
  return ymdOf(new Date(base.getFullYear(), base.getMonth(), base.getDate() + 1));
}

/**
 * End of the next one-month period. With a settlement day the period ends on that day
 * (10-01 end, day 31 -> 10-02..10-31); without one it is old end + 1 month.
 */
export function nextPeriodEnd(ymd, settlementDay, today = new Date()) {
  const day = Number(settlementDay);
  if (!(day >= 1 && day <= 31)) return addMonthsToPeriodEnd(ymd, 1, today);
  const start = parseYmd(nextPeriodStart(ymd, today));
  const onDay = (y, m) => new Date(y, m, Math.min(day, new Date(y, m + 1, 0).getDate()));
  let end = onDay(start.getFullYear(), start.getMonth());
  // A cycle shorter than two weeks means the settlement day already passed this month.
  if ((end - start) / 86400000 < 14) end = onDay(start.getFullYear(), start.getMonth() + 1);
  return ymdOf(end);
}

/** One-cycle renewal end for the batch dialog, or null when the next period starts after `targetYm`. */
export function batchRenewalEnd(ymd, settlementDay, targetYm, today = new Date()) {
  if (!/^\d{4}-\d{2}$/.test(String(targetYm || ''))) return null;
  return nextPeriodStart(ymd, today).slice(0, 7) <= targetYm ? nextPeriodEnd(ymd, settlementDay, today) : null;
}

/** Default batch month: the current month, or later if every course already starts after it. */
export function nextRenewalMonth(endDates, today = new Date()) {
  const current = ymdOf(today).slice(0, 7);
  const starts = endDates.map((d) => nextPeriodStart(d, today).slice(0, 7)).sort();
  return starts[0] && starts[0] > current ? starts[0] : current;
}

/** Readable message from a Laravel error body (validation errors before the generic message). */
export function renewalErrorMessage(json, fallback) {
  const details = json?.errors ? Object.values(json.errors).flat().filter(Boolean).join(' ') : '';
  return details || json?.message || fallback;
}
