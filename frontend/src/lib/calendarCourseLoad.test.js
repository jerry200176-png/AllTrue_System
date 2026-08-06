import assert from 'node:assert/strict';
import {
  mapCalendarCourse,
  buildStudentClassesApiUrl,
  buildSchedulesApiUrl,
  fetchCalendarCoursesAndSchedulesParallel,
  estimateParallelLoadSavingsMs,
} from './calendarCourseLoad.js';

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

console.log('calendarCourseLoad.test.js — 10 passed ✅');
