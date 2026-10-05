import { describe, it, expect } from 'vitest';
import { isCourseSettled, isPaymentNoticeStatus, paymentCenterIntentFor } from './paymentStatus.js';

// Reference copies of the pre-F7-S2 client recomputes (StudentsList / CourseManagement / DirectorDashboard).
const oldSettled = (c) => {
  if (String(c?.payment_status || '').toLowerCase() === 'paid') return true;
  const paid = Number(c?.Paid ?? c?.paid);
  const charge = Number(c?.Charge ?? c?.charge ?? c?.Pay ?? c?.pay);
  if (Number.isFinite(paid) && Number.isFinite(charge)) return paid >= charge && charge > 0;
  return Number.isFinite(paid) && paid > 0;
};
const oldNotice = (s) => ['unpaid', 'partial', 'pending_report'].includes(s?.payment_status)
  || (s?.payment_status == null && s?.alert_type === 'unpaid');

describe('F7 S2 payment status helpers', () => {
  it.each(['paid', 'unpaid', 'partial', 'pending_report'])('server payload %s: same boolean as before', (status) => {
    // Server payloads always carry payment_status; Paid/Charge are the consistent flag/charge.
    const course = { payment_status: status, Paid: status === 'paid' ? 1 : 0, Charge: 3000 };
    expect(isCourseSettled(course)).toBe(oldSettled(course));
    expect(isPaymentNoticeStatus(status)).toBe(oldNotice(course));
  });

  it('intended change: no Paid >= Charge fallback when payment_status is missing', () => {
    // Old: Paid=1,Charge=0 is false (charge>0 required), Paid=1 without charge was true.
    const legacy = { Paid: 1 };
    expect(oldSettled(legacy)).toBe(true);
    expect(isCourseSettled(legacy)).toBe(false);
    expect(isCourseSettled({ payment_status: 'unpaid', Paid: 1 })).toBe(false);
  });

  it('intended change: alert without payment_status is no longer treated as unpaid', () => {
    const alert = { alert_type: 'unpaid' };
    expect(oldNotice(alert)).toBe(true);
    expect(isPaymentNoticeStatus(alert.payment_status)).toBe(false);
  });

  it('payment center intent comes from server status', () => {
    expect(paymentCenterIntentFor({ payment_status: 'partial' })).toBe('unpaid');
    expect(paymentCenterIntentFor({ payment_status: 'pending_report' })).toBe('pending_report');
    expect(paymentCenterIntentFor({ payment_status: 'pending_reconciliation' })).toBe('pending_reconciliation');
    expect(paymentCenterIntentFor({ alert_type: 'low_sessions' })).toBe('renewal');
    expect(paymentCenterIntentFor({})).toBe('pending');
  });
});

describe('degraded load (direct Supabase fallback rows)', () => {
  it('returns unknown, not unpaid/paid, without a canonical server status', () => {
    expect(isCourseSettled({ _noncanonical: true, Paid: 1, payment_status: 'unpaid' })).toBeNull();
    expect(isCourseSettled({ _noncanonical: true, payment_status: 'paid' })).toBeNull();
    expect(isCourseSettled({ Paid: 1 })).toBeNull();
    expect(isCourseSettled({ payment_status: 'paid' })).toBe(true);
    expect(isCourseSettled({ payment_status: 'unpaid' })).toBe(false);
  });
});
