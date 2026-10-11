/**
 * OCC-P0 CT-3 (frontend half): pins how the three frontend key spaces address ONE occurrence before and after it moves.
 * Docs: ARCH-OCCURRENCE-IDENTITY-20261011. Characterization only: no source change. Assertions tagged
 * "DOCUMENTS CURRENT BUG" pin the latent behavior (F8) and flip in P5 (single identity key).
 * Run: node src/lib/occurrenceSlotKeyCharacterization.test.js (wired into npm run test:calendar)
 */
import assert from 'node:assert/strict';
import { mergeWeekCalendarOccurrences } from './calendarOccurrenceMerge.js';
import { createSessionViewModel, sessionViewModelKey } from './classSessionsApi.js';
import { lessonMatches } from '../composables/course-management/useCourseRowActions.js';

const course = {
  id: 501, student_id: 9, student_name: 's', teacher_id: 17, subject: 'Math', class_type: 'one_on_one',
  days_of_week: [2], day_time_slots: [{ day: 2, start_time: '16:30', duration_hours: 2 }], duration_hours: 2,
};
const weekDatesByDow = { 1: '2026-07-13', 2: '2026-07-14', 3: '2026-07-15', 4: '2026-07-16', 5: '2026-07-17', 6: '2026-07-18', 7: '2026-07-19' };
const sessionRow = (date) => ({ id: 77, student_course_id: 501, session_date: date, start_time: '16:30', end_time: '18:30', status: 'scheduled' });

const merge = (rows) => mergeWeekCalendarOccurrences({
  courses: [course], allCourses: [course], exceptions: [],
  sessionDatesByCourseId: { 501: rows }, weekDatesByDow, courseLastSessionDate: {},
  resolveAllCourseGridTimesForDate: (c, dow, ymd) => {
    const same = rows.filter((r) => r.session_date === ymd);
    return same.length
      ? same.map((r) => ({ start_time: r.start_time, end_time: r.end_time, duration_hours: 2 }))
      : c.day_time_slots.filter((s) => s.day === dow).map((s) => ({ start_time: s.start_time, end_time: '18:30', duration_hours: 2 }));
  },
  computeEndTime: () => '18:30',
});
const keysOf = (out) => out.map((r) => r.occurrence_key).sort();

// 1. Materialized occurrence: calendar key is the session id, before and after the move Tue -> Wed.
assert.deepEqual(keysOf(merge([sessionRow('2026-07-14')])), ['session:77'], 'on its series slot');
assert.deepEqual(keysOf(merge([sessionRow('2026-07-15')])), ['session:77'], 'moved to Wed: same key (id based)');

// 2. Course-lookup view model and lesson picker also follow the session id across a move.
const vm = (date) => createSessionViewModel({ id: 77, studentClassId: 501, date, startTime: '16:30', endTime: '18:30', status: 'scheduled' });
assert.equal(sessionViewModelKey(vm('2026-07-14')), 'id:77');
assert.equal(sessionViewModelKey(vm('2026-07-15')), 'id:77', 'view model key survives the move');
assert.equal(lessonMatches({ id: 77, date: '2026-07-14' }, { id: 77, date: '2026-07-15', start: '16:30' }), true, 'picker matches by id');

// 3. DOCUMENTS CURRENT BUG (F8), fixed in P5: the same series occurrence BEFORE materialization is addressed by its
// CURRENT slot, a different key space than session:<id>, so projected and materialized never share a key.
assert.deepEqual(keysOf(merge([])), ['base:501|2026-07-14|16:30'], 'projected occurrence key is slot based');
assert.notEqual(keysOf(merge([]))[0], keysOf(merge([sessionRow('2026-07-14')]))[0], 'projected key != materialized key');
assert.equal(sessionViewModelKey(createSessionViewModel({ kind: 'projected', isProjected: true, studentClassId: 501, date: '2026-07-14', startTime: '16:30' })), '2026-07-14|16:30');
// Without an id the picker can only match by current date/start, which a move invalidates.
assert.equal(lessonMatches({ date: '2026-07-14', unit: { startTime: '16:30' } }, { date: '2026-07-14', start: '16:30' }), true);
assert.equal(lessonMatches({ date: '2026-07-14', unit: { startTime: '16:30' } }, { date: '2026-07-15', start: '16:30' }), false);

console.log('occurrenceSlotKeyCharacterization: ok');
