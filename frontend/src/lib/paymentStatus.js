// F7 S2: the server (StudentClassController / AlertController) is the only authority on
// paid state. These helpers read `payment_status`; they never recompute from Paid/Charge.
export const PAYMENT_NOTICE_STATUSES = ['unpaid', 'partial', 'pending_report'];

export const isCourseSettled = (course) =>
  String(course?.payment_status || '').toLowerCase() === 'paid';

export const isPaymentNoticeStatus = (status) => PAYMENT_NOTICE_STATUSES.includes(status);

export const paymentCenterIntentFor = (student) => {
  const status = student?.payment_status;
  if (status === 'pending_report') return 'pending_report';
  if (status === 'pending_reconciliation') return 'pending_reconciliation';
  if (status === 'unpaid' || status === 'partial') return 'unpaid';
  if (student?.alert_type === 'low_sessions' || student?.alert_type === 'monthly_due_soon') return 'renewal';
  return 'pending';
};
