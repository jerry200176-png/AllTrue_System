import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchMonthlyAttendanceSummary } from '../../lib/monthlyAttendanceSummary.js';

const row = (id, status, extra = {}) => ({ id, student_class_id: 42, session_date: '2026-09-01', start_time: '10:00', end_time: '11:00', status, ...extra });
const page = (n, last, total, data) => ({ ok: true, status: 200, json: async () => ({ materialized: { data, current_page: n, last_page: last, total } }) });
function pages(responseMap) {
  const fetcher = vi.fn(async (url, options) => {
    const query = new URL(url, 'http://test.invalid').searchParams;
    expect(query.get('branch_id')).toBe('7');
    expect(query.get('student_class_id')).toBe('42');
    expect(query.get('exclude_history_future')).toBe('1');
    expect(query.has('start')).toBe(false);
    expect(query.has('end')).toBe(false);
    expect(options.headers.Authorization).toBe('Bearer test-token');
    return responseMap[Number(query.get('page'))];
  });
  vi.stubGlobal('fetch', fetcher);
  return fetcher;
}
afterEach(() => vi.unstubAllGlobals());
describe('monthly attendance complete-page read', () => {
  const opts = { branchId: 7, courseId: 42, token: 'test-token' };
  it('counts distinct attended rows across every page, including same-day and contract exceptions', async () => {
    const mock = pages({ 1: page(1, 2, 5, [row(1, 'attended'), row(2, 'scheduled'), row(3, 'cancelled')]), 2: page(2, 2, 5, [row(4, 'late', { is_contract_exception: true }), row(5, 'leave')]) });
    expect(await fetchMonthlyAttendanceSummary(opts)).toEqual({ count: 2, cancelled: 1 });
    expect(mock).toHaveBeenCalledTimes(2);
  });
  it('counts completed and excludes leave variants and internal cancelled placeholders', async () => {
    pages({ 1: page(1, 1, 5, [row(1, 'completed'), row(2, 'leave_adjusted'), row(3, 'excused'), row(4, 'cancelled', { note: 'cancelled-duplicate-reschedule-placeholder' }), row(5, 'absent')]) });
    expect(await fetchMonthlyAttendanceSummary(opts)).toEqual({ count: 1, cancelled: 0 });
  });
  it('does not assert a count for an unknown status', async () => {
    pages({ 1: page(1, 1, 1, [row(1, 'unknown-status')]) });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
  it('uses one status normalization for validation, attendance and cancellation', async () => {
    pages({ 1: page(1, 1, 2, [row(1, ' Attended '), row(2, ' Cancelled ')]) });
    expect(await fetchMonthlyAttendanceSummary(opts)).toEqual({ count: 1, cancelled: 1 });
  });
  it('returns zero only for a valid successful empty result', async () => {
    pages({ 1: page(1, 1, 0, []) });
    expect(await fetchMonthlyAttendanceSummary(opts)).toEqual({ count: 0, cancelled: 0 });
  });
  it('rejects a failed later page rather than publishing a partial result', async () => {
    pages({ 1: page(1, 2, 2, [row(1, 'attended')]), 2: { ok: false, status: 503 } });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
  it.each([
    ['missing metadata', { materialized: { data: [] } }],
    ['wrong page', { materialized: { data: [], current_page: 2, last_page: 1, total: 0 } }],
    ['malformed items', { materialized: { data: {}, current_page: 1, last_page: 1, total: 0 } }],
    ['over budget', { materialized: { data: [row(1, 'attended')], current_page: 1, last_page: 51, total: 51 } }],
    ['wrong course', { materialized: { data: [row(1, 'attended', { student_class_id: 43 })], current_page: 1, last_page: 1, total: 1 } }],
  ])('rejects %s', async (_label, payload) => {
    pages({ 1: { ok: true, status: 200, json: async () => payload } });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
  it('rejects repeated IDs, even if the total row count matches', async () => {
    pages({ 1: page(1, 2, 2, [row(1, 'attended')]), 2: page(2, 2, 2, [row(1, 'attended')]) });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
  it('rejects invalid JSON and mismatched reported row counts', async () => {
    pages({ 1: { ok: true, status: 200, json: async () => { throw new Error('invalid JSON'); } } });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
    pages({ 1: page(1, 1, 2, [row(1, 'attended')]) });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
  it('does not request later pages after cancellation', async () => {
    const controller = new AbortController();
    const mock = pages({ 1: { ok: true, status: 200, json: async () => { controller.abort(); return { materialized: { data: [row(1, 'attended')], current_page: 1, last_page: 2, total: 2 } }; } } });
    await expect(fetchMonthlyAttendanceSummary({ ...opts, signal: controller.signal })).rejects.toThrow();
    expect(mock).toHaveBeenCalledTimes(1);
  });
  it('rejects metadata drift between pages', async () => {
    pages({ 1: page(1, 2, 2, [row(1, 'attended')]), 2: page(2, 2, 3, [row(2, 'attended')]) });
    await expect(fetchMonthlyAttendanceSummary(opts)).rejects.toThrow();
  });
});
