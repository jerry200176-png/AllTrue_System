import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
const projection = vi.hoisted(() => vi.fn());
vi.mock('../../../supabase', () => ({ supabase: {} }));
vi.mock('../../../lib/classSessionsApi', async original => ({ ...await original(), fetchClassSessionsProjection: projection }));
vi.mock('../../../lib/calendarCourseLoad', async original => ({
  ...await original(),
  fetchCalendarStudentClassesApi: async () => ({ apiSucceeded: true, list: [99, 100].map(id => ({ id, student_id: 1, student_name: 'Synthetic student', status: 'active' })) }),
  fetchCalendarSchedulesApi: async () => ({ apiSucceeded: true, list: [] }),
}));
import { normalizeClassSessionsPayload, mapSessionViewModelsForCalendar } from '../../../lib/classSessionsApi';
import { useCalendarDataLoad } from '../useCalendarDataLoad';

const old = { id: 7001, student_class_id: 99, student_id: 1, teacher_id: 5, session_date: '2026-11-21', start_time: '10:00', end_time: '12:00', status: 'scheduled' };
const moved = { ...old, id: 7002, student_class_id: 100 };
const normalize = rows => normalizeClassSessionsPayload({ data: rows });
const loader = () => useCalendarDataLoad({ branchId: ref(1), userId: ref(9001), isTeacher: ref(false), viewMode: ref('week'),
  getCalendarDataFetchBoundsYmd: () => ({ schedStart: '2026-11-01', schedEnd: '2026-12-15' }) });
beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-local-read' }));
  projection.mockReset();
});

describe('in-app568 cancelled old-course cache', () => {
  it('applies explicit cancellation before display filtering without losing other weeks or courses', async () => {
    const data = loader();
    projection.mockResolvedValueOnce(normalize([old])); await data.loadCourses();
    const history = mapSessionViewModelsForCalendar(normalize([{ ...old, id: 6000, session_date: '2026-05-01', status: 'attended' }]).byClass)[99][0];
    const other = mapSessionViewModelsForCalendar(normalize([{ ...old, id: 6001, student_class_id: 88, session_date: '2026-07-01' }]).byClass)[88][0];
    data.sessionDatesByCourseId.value[99].unshift(history);
    data.sessionDatesByCourseId.value[88] = [other];
    const originalHistory = structuredClone(history), originalOther = structuredClone(other);
    projection.mockResolvedValueOnce(normalize([{ ...old, status: 'cancelled' }, moved]));
    await data.loadCourses();
    expect(data.sessionDatesByCourseId.value[99].map(s => s.id)).toEqual([6000]);
    expect(data.sessionDatesByCourseId.value[99][0]).toEqual(originalHistory);
    expect(data.sessionDatesByCourseId.value[88]).toEqual([originalOther]);
    expect(data.sessionDatesByCourseId.value[100].map(s => s.id)).toEqual([7002]);
  });
  it('keeps two genuine live occurrences rather than hiding a possible booking problem', async () => {
    const data = loader();
    projection.mockResolvedValueOnce(normalize([old, moved])); await data.loadCourses();
    expect(Object.values(data.sessionDatesByCourseId.value).flat().map(s => s.id).sort()).toEqual([7001, 7002]);
  });
  it('retains existing history and lessons when the projection fails', async () => {
    const data = loader();
    projection.mockResolvedValueOnce(normalize([old])); await data.loadCourses();
    const before = JSON.parse(JSON.stringify(data.sessionDatesByCourseId.value));
    projection.mockRejectedValueOnce(new Error('synthetic unavailable')); await data.loadCourses();
    expect(data.sessionDatesByCourseId.value).toEqual(before);
    expect(data.calendarLoading.value).toBe(false);
  });
});
