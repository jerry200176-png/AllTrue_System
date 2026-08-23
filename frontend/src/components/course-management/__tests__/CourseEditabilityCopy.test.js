import { describe, expect, it } from 'vitest';
import {
  editabilityActionDescription,
  editabilityActionLabel,
  editabilityFieldLabel,
  editabilityNextStepForError,
  editabilityNextStepLabel,
  editabilityReasonSummary,
} from '../../../lib/courseEditability';

describe('course editability copy', () => {
  it('turns protected state codes into an actionable director instruction', () => {
    const reason = {
      message: '已有扣堂紀錄。',
      next_step: 'billing_correction',
    };

    expect(editabilityReasonSummary(reason)).toContain('合約／堂次調整');
    expect(editabilityFieldLabel('sessions_purchased')).toBe('購買堂數');
    expect(editabilityNextStepForError({ code: 'payment_record_locked' })).toBe('void_payment');
    expect(editabilityNextStepLabel('unknown')).toBe('');
    expect(editabilityActionLabel('billing_correction')).toBe('更正未付款堂數');
    expect(editabilityActionDescription('transfer_sessions')).toContain('評量紀錄');
  });
});
