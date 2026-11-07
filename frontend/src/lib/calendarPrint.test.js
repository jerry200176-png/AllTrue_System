import assert from 'node:assert/strict';
import {
  getWeekRange, getMonthRange, getRange, buildDayGrid, exceptionMarkers, projectPrintRows, filterPrintRows, summarizeRows, chunkPrintRows, serializePrintableRows,
} from './calendarPrint.js';

assert.deepEqual(getWeekRange('2026-09-20'), { start: '2026-09-14', end: '2026-09-20' });
assert.deepEqual(getWeekRange('2027-01-01'), { start: '2026-12-28', end: '2027-01-03' });
assert.deepEqual(getMonthRange('2028-02-10'), { start: '2028-02-01', end: '2028-02-29' });

const range = { start: '2026-09-14', end: '2026-09-20' };
const rows = projectPrintRows({
  range,
  courses: [{ id: 1, student_id: 2, student_name: '王小明', teacher_id: 9, teacher_name: '林老師', subject: '數學', class_type: '一對一', room_id: 3, branch_id: 11 }],
  rooms: [{ id: 3, name: 'A101' }],
  teachers: [{ id: 9, username: '林老師' }],
  schedules: [
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'leave' },
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'extra' },
  ],
  sessions: [
    { id: 4, student_class_id: 1, session_date: '2026-09-15', start_time: '16:00', end_time: '18:00', status: 'scheduled', teacher_id: 9, student_name: '王小明' },
    { student_class_id: 1, session_date: '2026-09-15', start_time: '16:00', status: 'projected', isProjected: true },
  ],
});
assert.equal(rows.length, 1);
assert.equal(rows[0].studentName, '王小明');
assert.deepEqual(rows[0].markers, ['請假', '補課']);
assert.equal(rows[0].roomLabel, 'A101');
assert.equal(rows[0].campusLabel, undefined, 'campus is not printed (in-app #385)');
const enumClassTypeRows = projectPrintRows({
  range,
  courses: [{ id: 2, student_id: 3, teacher_id: 9, class_type: 'one_on_three' }],
  sessions: [{ id: 5, student_class_id: 2, session_date: '2026-09-15', start_time: '16:00', end_time: '18:00', status: 'scheduled', teacher_id: 9 }],
});
assert.equal(enumClassTypeRows[0].classTypeLabel, '一對三');
assert.deepEqual(exceptionMarkers({}, { teacher_id: 10 }, 9), ['代課']);
assert.deepEqual(exceptionMarkers({}, { teacher_id: 9 }, 9), []);
assert.equal(summarizeRows(rows, range, 'week').total, 1);
const overflowSheets = chunkPrintRows(Array.from({ length: 25 }, (_, i) => ({ ...rows[0], occurrenceKey: String(i), date: '2026-09-15' })));
assert.equal(overflowSheets.length, 3);
assert.equal(overflowSheets[2].date, '2026-09-15');
assert.equal(overflowSheets[2].continuation, true);
const crossDateSheets = chunkPrintRows([
  { ...rows[0], occurrenceKey: 'day-one', date: '2026-09-02' },
  { ...rows[0], occurrenceKey: 'day-two', date: '2026-09-03' },
]);
assert.deepEqual(crossDateSheets.filter((sheet) => sheet.kind === 'detail').map((sheet) => ({
  titleDate: sheet.date,
  rowDates: sheet.rows.map((row) => row.date),
  continuation: sheet.continuation,
})), [
  { titleDate: '2026-09-02', rowDates: ['2026-09-02'], continuation: false },
  { titleDate: '2026-09-03', rowDates: ['2026-09-03'], continuation: false },
]);
assert.equal(serializePrintableRows(rows)[0].occurrenceKey, undefined);
// in-app #385: stored subject code (calendar course keeps only subject='Math') prints in Chinese
const codeRows = projectPrintRows({
  range,
  courses: [{ id: 3, student_id: 4, teacher_id: 9, subject: 'Math', class_type: 'one_on_one' }],
  sessions: [{ id: 6, student_class_id: 3, session_date: '2026-09-15', start_time: '16:00', end_time: '18:00', status: 'attended', teacher_id: 9 }],
});
assert.equal(codeRows[0].subjectName, '數學');
// in-app #385: unticking 已取消 must not hide attended/plain rows, only the cancelled one
const cancelled = { ...codeRows[0], occurrenceKey: 'c', statusLabel: '已取消', markers: ['已取消'] };
const leave = { ...codeRows[0], occurrenceKey: 'l', statusLabel: '請假', markers: ['請假'] };
const kept = filterPrintRows([codeRows[0], cancelled, leave], { statuses: ['請假', '補課', '代課', '調課'] });
assert.deepEqual(kept.map((r) => r.occurrenceKey), [codeRows[0].occurrenceKey, 'l']);
assert.equal(filterPrintRows([codeRows[0], cancelled], { statuses: [] }).length, 1, 'no ticks = plain rows only');
assert.equal(filterPrintRows([codeRows[0], cancelled], {}).length, 2, 'no filter = everything');
console.log('calendarPrint tests passed');

assert.deepEqual(getRange('day', '2026-11-07'), { start: '2026-11-07', end: '2026-11-07' });
const dayRows = [
  { startTime: '13:00', endTime: '15:00', effectiveTeacherName: '乙', roomLabel: 'R1', studentName: 'a' },
  { startTime: '14:30', endTime: '15:30', effectiveTeacherName: '甲', roomLabel: 'R1', studentName: 'b' },
  { startTime: '14:00', endTime: '15:00', effectiveTeacherName: '乙', roomLabel: 'R2', studentName: 'c' },
];
const byTeacher = buildDayGrid(dayRows, {});
assert.equal(byTeacher.by, '老師');
assert.equal(byTeacher.columns.length, 2);
assert.deepEqual(byTeacher.slots.map((slot) => slot.label), ['13:00', '14:00', '15:00']);
assert.equal(byTeacher.slots[1].cells.flat().length, 2, 'cards sit in their start hour');
assert.equal(buildDayGrid(dayRows, { room: 'R' }).by, '教室');
assert.equal(buildDayGrid(dayRows, { room: 'R', teacher: '乙' }).by, '老師');
assert.deepEqual(buildDayGrid([], {}).slots, []);
