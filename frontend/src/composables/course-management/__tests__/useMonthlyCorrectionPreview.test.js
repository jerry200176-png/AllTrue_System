import { expect, it, vi } from 'vitest';
vi.mock('../../../supabase', () => ({ supabase: { auth: { getSession: async () => ({ data: { session: null } }) } } }));
import { useMonthlyCorrectionPreview } from '../useMonthlyCorrectionPreview.js';

it('does not prefill disputed contract amounts from a paid legacy flag', () => {
  const state = useMonthlyCorrectionPreview();
  state.open({ ID: 3304, Charge: 7500, Paid: 1, monthly_payment: { review_required: true, periods: [{ payment_status: 'paid', period_end: '2026-07-31' }] } });
  expect(state.form.value.source_charge).toBeNull();
  expect(state.form.value.target_charge).toBeNull();
  expect(state.preview.value).toBeNull();
  state.close();
});

it('explains unsupported out-of-contract lessons before asking for guessed split dates', async () => {
  const state = useMonthlyCorrectionPreview();
  state.open({ ID: 3304, monthly_payment: { review_required: true, session_review: [{ outside_contract_sessions: 2 }] } });
  expect(state.blocked.value).toBe(true);
  expect(state.error.value).toContain('需先由管理者核對日期更正清單');
  await state.check();
  expect(state.loading.value).toBe(false);
  expect(state.preview.value).toBeNull();
  state.close();
});
