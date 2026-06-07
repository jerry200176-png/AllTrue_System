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
  buildSchedulesApiUrl({
    baseUrl: '/api',
    schedStart: '2026-04-01',
    schedEnd: '2026-05-31',
    branchId: 1,
    isTeacher: false,
    userId: 5,
  }),
  /start=2026-04-01.*end=2026-05-31.*branch_id=1.*teacher_id=5/,
  'schedules URL should include date window and filters',
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

console.log('calendarCourseLoad.test.js — 9 passed ✅');
