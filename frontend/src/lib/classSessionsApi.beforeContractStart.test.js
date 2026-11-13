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

describe('merging two sources for the same lesson', () => {
  const vm = (flag) => sessionViewModelFromClassSessionsRow(row({ before_contract_start: flag }));

  it.each([[true, false], [false, true]])('keeps true when one source says %s and the other %s', async (a, b) => {
    const { mergeSessionViewModels, mergeSessionsByCourse } = await import('./classSessionsApi.js');
    expect(mergeSessionViewModels([vm(a)], [vm(b)])[0].beforeContractStart).toBe(true);
    expect(mergeSessionsByCourse({ 2: [vm(a)] }, { 2: [vm(b)] })[2][0].beforeContractStart).toBe(true);
  });

  it('stays false when neither source says true', async () => {
    const { mergeSessionViewModels } = await import('./classSessionsApi.js');
    expect(mergeSessionViewModels([vm(false)], [vm(false)])[0].beforeContractStart).toBe(false);
  });
});
