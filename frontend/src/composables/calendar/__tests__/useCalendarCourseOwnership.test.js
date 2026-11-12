import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { flushPromises } from '@vue/test-utils';

const mocks = vi.hoisted(() => ({ courses: vi.fn(), schedules: vi.fn(), projection: vi.fn(), from: vi.fn() }));
vi.mock('../../../supabase', () => ({ supabase: { from: mocks.from } }));
vi.mock('../../../lib/calendarCourseLoad', async (original) => ({
  ...await original(),
  fetchCalendarStudentClassesApi: mocks.courses,
  fetchCalendarSchedulesApi: mocks.schedules,
}));
vi.mock('../../../lib/classSessionsApi', async (original) => ({
  ...await original(), fetchClassSessionsProjection: mocks.projection,
}));

import { useCalendarDataLoad } from '../useCalendarDataLoad.js';

const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const query = (promise) => {
  const builder = { select: () => builder, eq: () => builder, then: (yes, no) => promise.then(yes, no) };
  return builder;
};
const course = (id) => ({ id, status: 'active', student_name: `synthetic-${id}` });
let requests;

function setup() {
  const branchId = ref(1);
  let window = { schedStart: '2026-11-02', schedEnd: '2026-11-08' };
  const api = useCalendarDataLoad({ branchId, userId: ref(7), isTeacher: () => false,
    viewMode: ref('week'), getCalendarDataFetchBoundsYmd: () => ({ ...window }) });
  const jump = () => { window = { schedStart: '2026-11-09', schedEnd: '2026-11-15' }; };
  const answer = async (index, id) => {
    requests[index].resolve({ list: [course(id)], apiSucceeded: true });
    await flushPromises();
  };
  return { api, branchId, jump, answer };
}

beforeEach(() => {
  vi.resetAllMocks(); requests = [];
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token' }));
  mocks.courses.mockImplementation((options) => {
    const held = { ...deferred(), options }; requests.push(held); return held.promise;
  });
  mocks.schedules.mockImplementation(async () => ({ list: [{ id: requests.length }], apiSucceeded: true }));
  mocks.projection.mockResolvedValue({ byClass: {} });
  mocks.from.mockImplementation(() => query(Promise.resolve({ data: [] })));
});
afterEach(() => { localStorage.clear(); });

describe('calendar load request ownership (#5183)', () => {
  it('keeps the jumped week when the old API response arrives last', async () => {
    const { api, jump, answer } = setup();
    const old = api.loadCourses(); jump(); const latest = api.loadCourses();
    await answer(1, 202); await latest;
    const fetchWindow = api.lastCalendarFetch.value;
    await answer(0, 101); await old;
    expect(api.courses.value.map(c => c.id)).toEqual([202]);
    expect(api.allCoursesUnfiltered.value.map(c => c.id)).toEqual([202]);
    expect(api.exceptions.value.map(c => c.id)).toEqual([2]);
    expect(api.lastCalendarFetch.value).toEqual(fetchWindow);
    expect(fetchWindow.schedStart).toBe('2026-11-09');
  });

  it.each([false, true])('old quiet=%s completion cannot clear the current loading state', async (quiet) => {
    const { api, jump, answer } = setup();
    const old = api.loadCourses({ quiet }); jump(); const latest = api.loadCourses();
    requests[1].options.onProgress(2, 10);
    const progress = api.calendarLoadProgress.value;
    requests[0].options.onProgress(3, 10);
    expect(api.calendarLoadProgress.value).toBe(progress);
    await answer(0, 101); await old;
    expect(api.calendarLoading.value).toBe(true);
    expect(api.courses.value).toEqual([]);
    await answer(1, 202); await latest;
    expect(api.calendarLoading.value).toBe(false);
    expect(api.calendarLoadProgress.value).toBe('');
  });

  it('does not expose stale errors or start legacy fallback for an obsolete failure', async () => {
    const { api, jump, answer } = setup();
    const old = api.loadCourses(); jump(); const latest = api.loadCourses();
    requests[0].reject(new Error('synthetic old API failure')); await old;
    expect(api.calendarLoadError.value).toBe('');
    expect(api.calendarLoading.value).toBe(true);
    expect(mocks.from).not.toHaveBeenCalled();
    await answer(1, 202); await latest;
  });

  it('ignores an old delayed projection after the new week settles', async () => {
    const projection = deferred(); mocks.projection.mockReturnValueOnce(projection.promise);
    const { api, jump, answer } = setup();
    const old = api.loadCourses(); await answer(0, 101);
    jump(); const latest = api.loadCourses(); await answer(1, 202); await latest;
    const fetchWindow = api.lastCalendarFetch.value;
    projection.resolve({ byClass: { 901: [] } }); await old;
    expect(api.sessionDatesByCourseId.value).toEqual({});
    expect(api.allCoursesUnfiltered.value.map(c => c.id)).toEqual([202]);
    expect(api.lastCalendarFetch.value).toEqual(fetchWindow);
  });

  it.each(['student-classes', 'schedules'])('ignores obsolete %s legacy fallback results', async (table) => {
    const held = deferred();
    mocks.from.mockImplementation(name => query(name === table ? held.promise : Promise.resolve({ data: [] })));
    if (table === 'schedules') mocks.schedules.mockResolvedValueOnce({ list: [], apiSucceeded: false });
    const { api, jump, answer } = setup();
    const old = api.loadCourses();
    if (table === 'student-classes') requests[0].resolve({ list: [], apiSucceeded: false });
    else requests[0].resolve({ list: [course(101)], apiSucceeded: true });
    await flushPromises();
    jump(); const latest = api.loadCourses(); await answer(1, 202); await latest;
    const fetchWindow = api.lastCalendarFetch.value;
    held.resolve({ data: table === 'student-classes' ? [course(101)] : [{ id: 101 }] }); await old;
    expect(api.courses.value.map(c => c.id)).toEqual([202]);
    expect(api.allCoursesUnfiltered.value.map(c => c.id)).toEqual([202]);
    expect(api.exceptions.value.map(c => c.id)).toEqual([2]);
    expect(api.lastCalendarFetch.value).toEqual(fetchWindow);
  });

  it('invalidates an in-flight load when the campus selection is cleared', async () => {
    const { api, branchId, answer } = setup();
    const old = api.loadCourses(); branchId.value = 0; await api.loadCourses();
    await answer(0, 101); await old;
    expect(api.courses.value).toEqual([]);
    expect(api.allCoursesUnfiltered.value).toEqual([]);
    expect(api.exceptions.value).toEqual([]);
    expect(api.sessionDatesByCourseId.value).toEqual({});
    expect(api.lastCalendarFetch.value).toBeNull();
    expect(api.calendarLoading.value).toBe(false);
  });

  it('still exposes a genuine latest failure and settles loading', async () => {
    const { api } = setup(); const latest = api.loadCourses();
    requests[0].reject(new Error('synthetic latest API failure')); await latest;
    expect(api.calendarLoadError.value).not.toBe('');
    expect(api.calendarLoading.value).toBe(false);
    expect(api.lastCalendarFetch.value).toBeNull();
  });
});
