import { describe, expect, it } from 'vitest';
import {
  buildTransferableSessionOption,
  isRecoverableCancelledSession,
} from '../../../lib/sessionTransferEligibility';

describe('sessionTransferEligibility', () => {
  it('keeps ordinary completed sessions transferable', () => {
    expect(buildTransferableSessionOption({ id: 1, date: '2026-08-01', status: 'attended' }))
      .toMatchObject({ id: 1, status: 'attended', recoverableCancelled: false });
  });

  it('exposes cancelled sessions only when historical evidence exists', () => {
    expect(isRecoverableCancelledSession({ status: 'cancelled' })).toBe(false);
    expect(buildTransferableSessionOption({
      id: 2,
      date: '2026-08-08',
      status: 'cancelled',
      hasLearningRecordHistory: true,
    })).toMatchObject({ id: 2, status: 'cancelled_recoverable', recoverableCancelled: true });
  });

  it('does not turn scheduled, leave, or absent sessions into recovery candidates', () => {
    for (const status of ['scheduled', 'leave', 'absent']) {
      expect(buildTransferableSessionOption({ id: 3, date: '2026-08-01', status, hasLearningRecordHistory: true }))
        .toBeNull();
    }
  });
});
