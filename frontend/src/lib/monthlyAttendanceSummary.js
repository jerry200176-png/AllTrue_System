import { fetchAllPages } from './pagedFetchAll.js';
import { sessionViewModelFromClassSessionsRow } from './classSessionsApi.js';
import { filterEffectiveSessions, filterVisibleCancelledSessions } from './sessionOccurrenceFilter.js';

const ATTENDED = new Set(['completed', 'attended', 'late']);
const KNOWN_STATUSES = new Set([...ATTENDED, 'absent', 'scheduled', 'rescheduled', 'leave', 'leave_adjusted', 'leave_requested', 'excused', 'cancelled']);

/** Read the materialized rows for one monthly course, refusing partial results. */
export async function fetchMonthlyAttendanceSummary({ branchId, courseId, token, signal } = {}) {
  const branch = Number(branchId);
  const course = Number(courseId);
  if (!Number.isSafeInteger(branch) || branch < 1 || !Number.isSafeInteger(course) || course < 1 || !token) {
    throw new Error('Missing course attendance scope');
  }
  const params = new URLSearchParams({ branch_id: String(branch), student_class_id: String(course), exclude_history_future: '1' });
  let expectedLast;
  let expectedTotal;
  const seen = new Set();
  const fetcher = async (url, options) => {
    if (signal?.aborted) throw new Error('Attendance request cancelled');
    const res = await fetch(url, { ...options, signal });
    if (signal?.aborted) throw new Error('Attendance request cancelled');
    if (!res.ok) return res;
    const json = await res.json();
    if (signal?.aborted) throw new Error('Attendance request cancelled');
    const block = json?.materialized ?? json;
    const page = Number(new URL(url, 'http://localhost').searchParams.get('page'));
    if (!Array.isArray(block?.data) || block.current_page == null || block.last_page == null || block.total == null) {
      throw new Error('Missing attendance pagination data');
    }
    const current = Number(block.current_page);
    const last = Number(block.last_page);
    const total = Number(block.total);
    if (!Number.isSafeInteger(current) || current !== page || !Number.isSafeInteger(last) || last < current || !Number.isSafeInteger(total) || total < 0) {
      throw new Error('Invalid attendance pagination data');
    }
    if (expectedLast == null) { expectedLast = last; expectedTotal = total; }
    if (last !== expectedLast || total !== expectedTotal) throw new Error('Attendance pages changed during loading');
    for (const raw of block.data) {
      const id = Number(raw?.id);
      const studentClass = Number(raw?.student_class_id ?? raw?.StudentClassID);
      const status = String(raw?.status ?? raw?.Status ?? '').trim().toLowerCase();
      if (!Number.isSafeInteger(id) || id < 1 || studentClass !== course || !KNOWN_STATUSES.has(status) || !sessionViewModelFromClassSessionsRow(raw) || seen.has(id)) {
        throw new Error('Invalid or repeated attendance row');
      }
      seen.add(id);
    }
    return { ok: true, status: res.status, json: async () => ({ data: block.data, current_page: current, last_page: last, total }) };
  };
  const { data } = await fetchAllPages(`/api/v1/class-sessions?${params}`, token, { perPage: 2000, maxPages: 50, concurrency: 4, fetcher });
  const rows = data.map((raw) => sessionViewModelFromClassSessionsRow({ ...raw, status: String(raw.status ?? raw.Status).trim().toLowerCase() }));
  const count = filterEffectiveSessions(rows).filter((row) => ATTENDED.has(String(row.status || '').toLowerCase())).length;
  return { count, cancelled: filterVisibleCancelledSessions(rows).length };
}
