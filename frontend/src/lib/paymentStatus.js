// F7 S2: the server (StudentClassController / AlertController) is the only authority on
// paid state. These helpers read `payment_status`; they never recompute from Paid/Charge.
export const PAYMENT_NOTICE_STATUSES = ['unpaid', 'partial', 'pending_report'];

// Returns null (unknown) for rows without a canonical server status, e.g. direct-Supabase
// fallback rows (`_noncanonical`) built when /api/v1/student-classes fails. Callers must not
// treat unknown as unpaid.
export const isCourseSettled = (course) => {
  const status = String(course?.payment_status || '').toLowerCase();
  if (course?._noncanonical || !status) return null;
  return status === 'paid';
};

export const isPaymentNoticeStatus = (status) => PAYMENT_NOTICE_STATUSES.includes(status);

export const paymentCenterIntentFor = (student) => {
  const status = student?.payment_status;
  if (status === 'pending_report') return 'pending_report';
  if (status === 'pending_reconciliation') return 'pending_reconciliation';
  if (status === 'unpaid' || status === 'partial') return 'unpaid';
  if (student?.alert_type === 'low_sessions' || student?.alert_type === 'monthly_due_soon') return 'renewal';
  return 'pending';
};
