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
