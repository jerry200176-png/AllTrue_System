// in-app #536 / #5198: the server-derived "dated before the contract StartDate" flag reaches the view model,
// is separate from isContractException (weekly pattern), and never touches quota counting.
import { describe, expect, it } from 'vitest';
import { sessionViewModelFromClassSessionsRow } from './classSessionsApi.js';
import { rowOccupiesPurchasedQuota } from './sessionOccurrenceFilter.js';

const row = (extra = {}) => ({ id: 1, student_class_id: 2, session_date: '2026-09-27', start_time: '10:00', end_time: '12:00', status: 'attended', ...extra });

describe('beforeContractStart', () => {
  it('maps before_contract_start only when strictly true', () => {
    expect(sessionViewModelFromClassSessionsRow(row({ before_contract_start: true })).beforeContractStart).toBe(true);
    expect(sessionViewModelFromClassSessionsRow(row({ before_contract_start: false })).beforeContractStart).toBe(false);
    expect(sessionViewModelFromClassSessionsRow(row()).beforeContractStart).toBe(false);
  });

  it('is independent of isContractException and does not change quota counting', () => {
    const vm = sessionViewModelFromClassSessionsRow(row({ before_contract_start: true, is_contract_exception: false }));
    expect(vm.isContractException).toBe(false);
    expect(rowOccupiesPurchasedQuota(vm, { exceptionsOccupyQuota: false })).toBe(true);
  });
});
