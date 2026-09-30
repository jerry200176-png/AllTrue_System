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

/** Period end that lands in `targetYm` (YYYY-MM), or null when `ymd` already reaches that month. */
export function periodEndInMonth(ymd, targetYm) {
  const e = /^(\d{4})-(\d{2})/.exec(String(ymd || ''));
  const t = /^(\d{4})-(\d{2})/.exec(String(targetYm || ''));
  if (!e || !t) return null;
  const months = (Number(t[1]) - Number(e[1])) * 12 + (Number(t[2]) - Number(e[2]));
  return months > 0 ? addMonthsToPeriodEnd(ymd, months) : null;
}

/** Earliest month any course would renew into (YYYY-MM); used as the batch default. */
export function nextRenewalMonth(endDates, today = new Date()) {
  const months = endDates.map((d) => addMonthsToPeriodEnd(d, 1, today).slice(0, 7)).sort();
  return months[0] || addMonthsToPeriodEnd('', 1, today).slice(0, 7);
}
