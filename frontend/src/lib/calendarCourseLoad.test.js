import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fetchAllPages } from './pagedFetchAll.js';
import {
  mapCalendarCourse,
  buildStudentClassesApiUrl,
  buildSchedulesApiUrl,
  resolveCalendarRoleScopeParams,
  fetchCalendarCoursesAndSchedulesParallel,
  estimateParallelLoadSavingsMs,
  fetchCalendarStudentClassesApi,
} from './calendarCourseLoad.js';

// R98 root-cause guard: this is the ONE place both endpoint builders must derive their
// role-scope params from — a future edit here is what both builders will inherit,
// instead of two independently hand-edited if/if pairs drifting apart again.
assert.deepEqual(
  resolveCalendarRoleScopeParams({ isTeacher: true, userId: 99, branchId: 4 }),
  { teacherId: '99', branchId: null },
  'teacher role scopes by teacher_id only',
);
assert.deepEqual(
  resolveCalendarRoleScopeParams({ isTeacher: false, userId: 280, branchId: 4 }),
  { teacherId: null, branchId: '4' },
  'non-teacher (director/admin) role scopes by branch_id only — never its own user id as teacher_id',
);
assert.deepEqual(
  resolveCalendarRoleScopeParams({ isTeacher: false, userId: null, branchId: null }),
  { teacherId: null, branchId: null },
  'no branch selected yet → no scope params',
);

assert.equal(
  mapCalendarCourse({ id: 1, day_of_week: '3', rate_unit: null }).day_of_week,
  3,
  'mapCalendarCourse should coerce day_of_week to int',
);

assert.equal(
  mapCalendarCourse({ id: 1, student_name: 'A' }).student_name,
  'A',
  'mapCalendarCourse should preserve student_name',
);

assert.match(
  buildStudentClassesApiUrl({ baseUrl: '/api', branchId: 2, isTeacher: false, userId: null }),
  /\/api\/v1\/student-classes\?branch_id=2$/,
  'student-classes URL should include branch_id for director view',
);

assert.match(
  buildStudentClassesApiUrl({ baseUrl: '/api', branchId: 0, isTeacher: true, userId: 99 }),
  /teacher_id=99/,
  'student-classes URL should include teacher_id for teacher view',
);

assert.match(
  buildStudentClassesApiUrl({
    baseUrl: '/api',
    branchId: 1,
    isTeacher: false,
    userId: null,
    schedStart: '2026-04-13',
    schedEnd: '2026-05-31',
  }),
  /start=2026-04-13.*end=2026-05-31/,
  'student-classes URL should include calendar window when provided',
);

assert.match(
  buildSchedulesApiUrl({
    baseUrl: '/api',
    schedStart: '2026-04-01',
    schedEnd: '2026-05-31',
    branchId: 1,
    isTeacher: true,
    userId: 5,
  }),
  /start=2026-04-01.*end=2026-05-31.*teacher_id=5/,
  'schedules URL should include date window and teacher_id for teacher view',
);

// Regression guard for a real production bug: director/admin (非 teacher) 帳號的
// schedules 請求絕不能帶上自己的 userId 當 teacher_id — ScheduleController::index()
// 對 teacher_id 是無條件套用 where()，帶了等於永遠查不到任何真正老師的排程，
// 整個 schedules 層在「主任視角」下永遠回傳空陣列（in-app #219 根因之一）。
const directorSchedulesUrl = buildSchedulesApiUrl({
  baseUrl: '/api',
  schedStart: '2026-04-01',
  schedEnd: '2026-05-31',
  branchId: 1,
  isTeacher: false,
  userId: 280,
});
assert.match(
  directorSchedulesUrl,
  /branch_id=1/,
  'schedules URL should include branch_id for director/admin view',
);
assert.doesNotMatch(
  directorSchedulesUrl,
  /teacher_id/,
  'schedules URL must NOT include teacher_id for director/admin view — backend applies it unconditionally and would zero out results',
);

// Parallel orchestration: total wall time ≈ max(individual), not sum
const delay = (ms, value) => new Promise((resolve) => {
  setTimeout(() => resolve(value), ms);
});

const t0 = Date.now();
const parallel = await fetchCalendarCoursesAndSchedulesParallel({
  fetchCourses: () => delay(80, { list: [{ id: 1 }], apiSucceeded: true }),
  fetchSchedules: () => delay(40, { list: [{ id: 's1' }], apiSucceeded: true }),
});
const elapsed = Date.now() - t0;

assert.equal(parallel.courses.list.length, 1, 'parallel courses result preserved');
assert.equal(parallel.schedules.list.length, 1, 'parallel schedules result preserved');
assert.ok(elapsed < 120, `parallel fetch should finish near max delay, got ${elapsed}ms`);

// One side failure should not block the other
const partial = await fetchCalendarCoursesAndSchedulesParallel({
  fetchCourses: () => Promise.reject(new Error('courses down')),
  fetchSchedules: () => Promise.resolve({ list: [{ id: 's2' }], apiSucceeded: true }),
});
assert.equal(partial.courses.apiSucceeded, false, 'failed courses fetch returns empty');
assert.equal(partial.schedules.list[0].id, 's2', 'schedules fetch still succeeds when courses fail');

const savings = estimateParallelLoadSavingsMs(1200, 350);
assert.equal(savings.serialMs, 1550, 'serial total is sum');
assert.equal(savings.parallelMs, 1200, 'parallel total is max');
assert.equal(savings.savedMs, 350, 'saved ms equals shorter leg');

console.log('calendarCourseLoad.test.js — 19 passed ✅');

// #4490: a later-page outage must not turn a partial calendar into canonical success.
test('paged course reads preserve every row and scope at page boundaries', async (t) => {
  const originalFetch = globalThis.fetch;
  t.after(() => { globalThis.fetch = originalFetch; });
  for (const total of [0, 1, 500, 501, 1001]) {
    const calls = [];
    globalThis.fetch = async (input, options) => {
      const url = new URL(input, 'https://fixture.invalid');
      calls.push(url);
      assert.equal(url.searchParams.get('branch_id'), '16');
      assert.equal(url.searchParams.get('start'), '2026-10-01');
      assert.equal(options.headers.Authorization, 'Bearer fixture-token');
      const size = Number(url.searchParams.get('per_page'));
      const offset = (Number(url.searchParams.get('page')) - 1) * size;
      return { ok: true, json: async () => ({ total, last_page: Math.max(1, Math.ceil(total / size)),
        data: Array.from({ length: Math.max(0, Math.min(size, total - offset)) }, (_, i) => ({ id: offset + i + 1 })) }) };
    };
    const result = await fetchAllPages('/api/v1/student-classes?branch_id=16&start=2026-10-01', 'fixture-token');
    assert.equal(result.total, total);
    assert.deepEqual(result.data.map((row) => row.id), Array.from({ length: total }, (_, i) => i + 1));
    assert.equal(calls.length, Math.max(1, Math.ceil(total / 200)));
  }
});

test('later-page failure does not claim a successful course source', async (t) => {
  const originalFetch = globalThis.fetch;
  t.after(() => { globalThis.fetch = originalFetch; });
  globalThis.fetch = async (input) => new URL(input, 'https://fixture.invalid').searchParams.get('page') === '1'
    ? { ok: true, json: async () => ({ data: [{ id: 1 }], total: 2, last_page: 2 }) }
    : { ok: false, status: 503 };
  const result = await fetchCalendarCoursesAndSchedulesParallel({
    fetchCourses: () => fetchCalendarStudentClassesApi({ baseUrl: '/api', branchId: 16, token: 'fixture-token', fetchAllPages }),
    fetchSchedules: async () => ({ list: [{ id: 'still-available' }], apiSucceeded: true }),
  });
  assert.equal(result.courses.apiSucceeded, false);
  assert.deepEqual(result.courses.list, []);
  assert.equal(result.schedules.list[0].id, 'still-available');
});

test('page budget and malformed page data reject incomplete results', async (t) => {
  const originalFetch = globalThis.fetch;
  t.after(() => { globalThis.fetch = originalFetch; });
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: [{ id: 1 }], total: 51, last_page: 51 }) });
  await assert.rejects(fetchAllPages('/courses', null), /page.*limit/i);
  for (const body of [{ message: 'missing data' }, { data: [], last_page: 'unknown' }, { data: [], last_page: 0 }]) {
    globalThis.fetch = async () => ({ ok: true, json: async () => body });
    await assert.rejects(fetchAllPages('/courses', null), /pagination|page data/i);
  }
  globalThis.fetch = async (input) => new URL(input, 'https://fixture.invalid').searchParams.get('page') === '1'
    ? { ok: true, json: async () => ({ data: [{ id: 1 }], total: 2, last_page: 2 }) }
    : { ok: true, json: async () => ({ message: 'missing data' }) };
  await assert.rejects(fetchAllPages('/courses', null), /page data/i);
});

test('legacy arrays and meta pagination retain their contract', async (t) => {
  const originalFetch = globalThis.fetch;
  t.after(() => { globalThis.fetch = originalFetch; });
  globalThis.fetch = async () => ({ ok: true, json: async () => [{ id: 1 }] });
  assert.deepEqual(await fetchAllPages('/courses', null), { data: [{ id: 1 }], total: 1 });
  globalThis.fetch = async (input) => {
    const page = Number(new URL(input, 'https://fixture.invalid').searchParams.get('page'));
    return { ok: true, json: async () => ({ data: [{ id: page }], meta: { total: 2, last_page: 2 } }) };
  };
  assert.deepEqual(await fetchAllPages('/courses', null), { data: [{ id: 1 }, { id: 2 }], total: 2 });
});
