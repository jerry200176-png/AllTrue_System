export const periodPaymentLabel = (status) => ({
  paid: '已繳費', unpaid: '未繳費', partial: '部分繳', pending_report: '待對帳',
  review_required: '付款期間待確認', unknown: '付款期間待確認',
})[status] || '付款期間待確認';

export function monthlyPaymentLabel(course) {
  const summary = course?.monthly_payment;
  if (!summary) return null;
  if (summary.review_required) return '付款期間待確認';
  if (course.payment_status === 'pending_report' && summary.payment_status === 'unpaid') return `${summary.billing_period} 待對帳`;
  return `${summary.billing_period} ${periodPaymentLabel(summary.payment_status)}`;
}
