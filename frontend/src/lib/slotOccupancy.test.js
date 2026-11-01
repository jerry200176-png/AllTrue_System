import assert from 'node:assert/strict';
import {
  occupancyBadge,
  incomingClassConflict,
  isOneOnThreeSlot,
  pickerSlotConflict,
  uniqueStudentCount,
  coursesWithSlotConflicts,
} from './slotOccupancy.js';
import { buildReschedulePreview } from './reschedulePreview.js';
import { TRIAL_TARGET_CLASS_TYPE_OPTIONS, trialTargetPayload } from './trialTarget.js';
import { mapCalendarCourse } from './calendarCourseLoad.js';

const mixedTwo = [
  { id: 1, student_id: 341, class_type: 'one_on_three' },
  { id: 2, student_id: 346, class_type: 'one_on_two' },
];

const badge = occupancyBadge({ courses: mixedTwo });
assert.equal(uniqueStudentCount(mixedTwo), 2);
assert.equal(badge.count, 2);
assert.equal(badge.max, 3);
assert.equal(badge.label, '2/3');
assert.match(badge.tooltip, /本分校 2 位/);
assert.match(badge.tooltip, /一對三可再收 1/);
assert.doesNotMatch(badge.tooltip, /其他分校/);
assert.notEqual(badge.label, '2/2');

const fullThree = occupancyBadge({
  courses: [
    ...mixedTwo,
    { id: 3, student_id: 350, class_type: 'one_on_three' },
  ],
});
assert.equal(fullThree.count, 3);
assert.equal(fullThree.max, 3);
assert.match(fullThree.tooltip, /已滿/);

const oneOnOneBlocks = occupancyBadge({
  courses: [{ id: 9, student_id: 1, class_type: 'one_on_one' }],
});
assert.equal(oneOnOneBlocks.max, 1);
assert.equal(oneOnOneBlocks.label, '1/1');

const addThree = incomingClassConflict({
  incomingType: 'one_on_three',
  overlappingCourses: mixedTwo,
});
assert.equal(addThree.blocked, false);

const addTwo = incomingClassConflict({
  incomingType: 'one_on_two',
  overlappingCourses: mixedTwo,
});
assert.equal(addTwo.blocked, true);
assert.equal(addTwo.reason, 'incoming_full');

const addOntoOne = incomingClassConflict({
  incomingType: 'one_on_three',
  overlappingCourses: [{ id: 1, student_id: 7, class_type: 'one_on_one' }],
});
assert.equal(addOntoOne.blocked, true);
assert.equal(addOntoOne.reason, 'one_on_one');

const mixedBusy = [
  { start_time: '15:00', end_time: '17:00', campus_id: 3, class_type: 'one_on_two', remaining_capacity: 0 },
  { start_time: '15:00', end_time: '17:00', campus_id: 3, class_type: 'one_on_three', remaining_capacity: 1 },
];

const coverThree = pickerSlotConflict({
  overlappingSlots: mixedBusy,
  coveredClassType: 'one_on_three',
  sessionCampusId: 3,
  branchNameMap: { 3: '大直' },
});
assert.equal(coverThree.conflict, false);
assert.equal(coverThree.capacityWarn, true);
assert.equal(coverThree.conflictTooltip, '');

const coverTwo = pickerSlotConflict({
  overlappingSlots: mixedBusy,
  coveredClassType: 'one_on_two',
  sessionCampusId: 3,
  branchNameMap: { 3: '大直' },
});
assert.equal(coverTwo.conflict, true);
assert.match(coverTwo.conflictTooltip, /本分校/);
assert.doesNotMatch(coverTwo.conflictTooltip, /其他分校/);

// #4368: a trial joining a server-classified one-on-three slot is not full below 3 students.
const trialSlot = (count) => [
  { start_time: '15:00', end_time: '17:00', campus_id: 3, class_type: 'one_on_three', remaining_capacity: 3 - count, student_count: count, one_on_three: true },
  { start_time: '15:00', end_time: '17:00', campus_id: 3, class_type: 'trial', remaining_capacity: 3 - count, student_count: count, one_on_three: true },
];
const coverTrial = (count, requestedEnd = '17:00') => pickerSlotConflict({
  overlappingSlots: trialSlot(count), coveredClassType: 'trial', sessionCampusId: 3, requestedStart: '15:00', requestedEnd,
});
assert.equal(coverTrial(2).conflict, false);
assert.equal(coverTrial(3).conflict, true);
// Codex P2: each flag describes that busy row's own span. A one-on-three 15:00–16:00 doesn't lift a 15:00–17:00 trial.
const shortOneOnThree = [
  { start_time: '15:00', end_time: '16:00', campus_id: 3, class_type: 'one_on_three', remaining_capacity: 2, student_count: 1, one_on_three: true },
  { start_time: '15:00', end_time: '17:00', campus_id: 3, class_type: 'trial', remaining_capacity: 0, student_count: 1, one_on_three: false },
];
assert.equal(pickerSlotConflict({ overlappingSlots: shortOneOnThree, coveredClassType: 'trial', sessionCampusId: 3, requestedStart: '15:00', requestedEnd: '17:00' }).conflict, true);
assert.equal(pickerSlotConflict({ overlappingSlots: trialSlot(1), coveredClassType: 'trial', sessionCampusId: 3 }).conflict, true, 'no requested span: no lift');

const otherCampus = pickerSlotConflict({
  overlappingSlots: [{
    start_time: '15:00',
    end_time: '17:00',
    campus_id: 9,
    class_type: 'one_on_one',
    remaining_capacity: 0,
  }],
  coveredClassType: 'one_on_three',
  sessionCampusId: 3,
  branchNameMap: { 9: '新店' },
});
assert.equal(otherCampus.conflict, true);
assert.match(otherCampus.conflictTooltip, /其他分校（新店）/);
assert.equal(otherCampus.otherCampusConflict, true);
assert.equal(coverTwo.otherCampusConflict, false);

const dupStudent = uniqueStudentCount([
  { id: 1, student_id: 214, class_type: 'one_on_three' },
  { id: 2, student_id: 214, class_type: 'one_on_three' },
]);
assert.equal(dupStudent, 1);

// #2007/#2006: renewal left the old course open — same teacher/day/time as the new one.
const renewalOverlap = coursesWithSlotConflicts([
  { id: 1272, teacher_id: 67, days_of_week: [6], start_time: '13:00', end_time: '15:00', start_date: '2026-01-01', end_date: '2026-08-15' },
  { id: 2382, teacher_id: 67, days_of_week: [6], start_time: '13:00', end_time: '15:00', start_date: '2026-08-08', end_date: '2026-12-31' },
]);
assert.deepEqual([...renewalOverlap].sort(), [1272, 2382]);

// In-app #283: a paused course stays visible for staff follow-up, but Stop=1
// does not occupy a teacher slot and must not create an "ongoing" conflict.
const pausedCourseOverlap = coursesWithSlotConflicts([
  { id: 2831, status: 'active', Stop: 0, teacher_id: 91, days_of_week: [5], start_time: '18:00', end_time: '20:00' },
  { id: 2832, status: 'inactive', teacher_id: 91, days_of_week: [5], start_time: '18:00', end_time: '20:00' },
  { id: 2833, Stop: 1, teacher_id: 91, days_of_week: [5], start_time: '18:00', end_time: '20:00' },
]);
assert.equal(pausedCourseOverlap.size, 0);

// Same recurring slot but disjoint contract periods is a normal renewal, not
// a live overlap. The course list must agree with the date-expanded detail.
const disjointRenewal = coursesWithSlotConflicts([
  { id: 10, teacher_id: 67, days_of_week: [6], start_time: '13:00', end_time: '15:00', start_date: '2026-01-01', end_date: '2026-08-01' },
  { id: 11, teacher_id: 67, days_of_week: [6], start_time: '13:00', end_time: '15:00', start_date: '2026-08-08', end_date: '2026-12-31' },
]);
assert.equal(disjointRenewal.size, 0);

// Different teacher, same time → no conflict.
const differentTeacher = coursesWithSlotConflicts([
  { id: 1, teacher_id: 1, days_of_week: [6], start_time: '13:00', end_time: '15:00' },
  { id: 2, teacher_id: 2, days_of_week: [6], start_time: '13:00', end_time: '15:00' },
]);
assert.equal(differentTeacher.size, 0);

// Same teacher/day, non-overlapping times → no conflict.
const noTimeOverlap = coursesWithSlotConflicts([
  { id: 1, teacher_id: 1, days_of_week: [6], start_time: '13:00', end_time: '15:00' },
  { id: 2, teacher_id: 1, days_of_week: [6], start_time: '15:00', end_time: '17:00' },
]);
assert.equal(noTimeOverlap.size, 0);

// Legacy single day_of_week field (no days_of_week array) is also supported.
const legacyDayField = coursesWithSlotConflicts([
  { id: 1, teacher_id: 5, day_of_week: 2, start_time: '10:00', end_time: '11:00' },
  { id: 2, teacher_id: 5, day_of_week: 2, start_time: '10:30', end_time: '12:00' },
]);
assert.deepEqual([...legacyDayField].sort(), [1, 2]);

const otherCampusRoom = pickerSlotConflict({
  overlappingSlots: [{ start_time: '15:00', end_time: '17:00', campus_id: 9, class_type: 'one_on_three', remaining_capacity: 2 }],
  coveredClassType: 'one_on_three',
  sessionCampusId: 3,
  branchNameMap: { 9: '新店' },
});
assert.equal(otherCampusRoom.conflict, true, 'under-capacity other-campus lesson still blocks');
assert.equal(otherCampusRoom.otherCampusConflict, true);
assert.equal(otherCampusRoom.capacityWarn, false);

// in-app #426 (Founder decision 1a, 2026-10-21): in a one-on-three slot trial
// students take real seats; more than one trial may join while total <= 3.
const r = (id, type) => ({ id, student_id: 700 + id, class_type: type });
const conflictFor = (incomingType, overlappingCourses) => incomingClassConflict({ incomingType, overlappingCourses });

// 1 regular + 1 trial: a second trial is allowed (the reported case, with a 1v3 student present).
assert.equal(conflictFor('trial', [r(1, 'one_on_three'), r(2, 'trial')]).blocked, false);
// 2 regular + 1 trial is allowed.
assert.equal(conflictFor('trial', [r(1, 'one_on_three'), r(2, 'one_on_three')]).blocked, false);
// 2 regular + 1 trial, then a third regular: blocked (the trial took a seat).
const thirdRegular = conflictFor('one_on_three', [r(1, 'one_on_three'), r(2, 'one_on_three'), r(3, 'trial')]);
assert.equal(thirdRegular.blocked, true);
assert.equal(thirdRegular.unique, 3);
// A 4th of any kind is blocked.
const fourthTrial = conflictFor('trial', [r(1, 'one_on_three'), r(2, 'trial'), r(3, 'trial')]);
assert.equal(fourthTrial.blocked, true);
assert.equal(fourthTrial.reason, 'absolute');
assert.equal(conflictFor('trial', [r(1, 'one_on_three'), r(2, 'one_on_three'), r(3, 'one_on_three')]).blocked, true);

// Badge shows the true total, trials included.
const fullWithTrial = occupancyBadge({ courses: [r(1, 'one_on_three'), r(2, 'one_on_three'), r(3, 'trial')] });
assert.equal(fullWithTrial.label, '3/3');
assert.match(fullWithTrial.tooltip, /含試聽 1 位/);
assert.match(fullWithTrial.tooltip, /已滿/);

// Blast radius: non-1v3 slots keep the one-trial-per-slot rule.
const oneOnTwoSecondTrial = conflictFor('trial', [r(1, 'one_on_two'), r(2, 'trial')]);
assert.equal(oneOnTwoSecondTrial.blocked, true);
assert.equal(oneOnTwoSecondTrial.reason, 'trial');
assert.equal(conflictFor('trial', [r(2, 'trial')]).blocked, true);
assert.equal(conflictFor('trial', [r(1, 'one_on_one'), r(2, 'one_on_three'), r(3, 'trial')]).reason, 'trial');
// Trial auditing outside 1v3 is unchanged (first trial joins a 1v1 slot).
assert.equal(conflictFor('trial', [r(1, 'one_on_one')]).blocked, false);

// in-app #426 trial target: trials for a 1v3 class make the slot 1v3 with no regular student yet.
const t = (id, target) => ({ id, student_id: 800 + id, class_type: 'trial', trial_target_class_type: target });
const targetConflict = (overlappingCourses, incomingType = 'trial', incomingTrialTarget = 'one_on_three') =>
  incomingClassConflict({ incomingType, incomingTrialTarget, overlappingCourses });
// 3 trials targeting 1v3, no regular student: allowed; a 4th is blocked.
assert.equal(targetConflict([t(1, 'one_on_three'), t(2, 'one_on_three')]).blocked, false);
const fourthTargeted = targetConflict([t(1, 'one_on_three'), t(2, 'one_on_three'), t(3, 'one_on_three')]);
assert.equal(fourthTargeted.blocked, true);
assert.equal(fourthTargeted.reason, 'absolute');
// 2 targeted trials + 1 regular 1v3: allowed.
assert.equal(targetConflict([t(1, 'one_on_three'), t(2, 'one_on_three')], 'one_on_three', null).blocked, false);
// A stored target alone marks the slot for an untargeted incoming trial.
assert.equal(targetConflict([t(1, 'one_on_three')], 'trial', null).blocked, false);
// Null-target trials keep one per slot; a 1v2 target does not lift the cap.
assert.equal(targetConflict([t(1, null)], 'trial', null).reason, 'trial');
assert.equal(targetConflict([t(1, 'one_on_two')], 'trial', 'one_on_two').reason, 'trial');
// A one-on-one booking still excludes the slot from 1v3 typing.
assert.equal(targetConflict([r(1, 'one_on_one'), t(2, 'one_on_three')]).reason, 'trial');
// Badge counts targeted trials as seats.
assert.equal(occupancyBadge({ courses: [t(1, 'one_on_three'), t(2, 'one_on_three'), t(3, 'one_on_three')] }).label, '3/3');

// Form payload: only a trial sends its target; null clears it.
assert.deepEqual(trialTargetPayload({ class_type: 'trial', trial_target_class_type: 'one_on_three' }), { trial_target_class_type: 'one_on_three' });
assert.deepEqual(trialTargetPayload({ class_type: 'trial', trial_target_class_type: '' }), { trial_target_class_type: null });
assert.deepEqual(trialTargetPayload({ class_type: 'one_on_three', trial_target_class_type: 'one_on_three' }), {});
assert.deepEqual(TRIAL_TARGET_CLASS_TYPE_OPTIONS.map((o) => o.value), ['one_on_one', 'one_on_two', 'one_on_three']);
// The calendar course mapping exposes the stored target to the conflict check.
assert.equal(mapCalendarCourse({ id: 9, class_type: 'trial', trial_target_class_type: 'one_on_three' }).trial_target_class_type, 'one_on_three');
assert.equal(mapCalendarCourse({ id: 9, class_type: 'trial' }).trial_target_class_type, null);
// The badge uses the same trial-target rule as the guard: a 1v2 student plus a trial for a 1v3
// class is a 1v3 slot, so it reads 2/3 with a seat left, and 3/3 after the permitted third.
const targetedBadge = occupancyBadge({ courses: [r(1, 'one_on_two'), t(2, 'one_on_three')] });
assert.equal(targetedBadge.label, '2/3');
assert.equal(targetedBadge.remaining, 1);
assert.match(targetedBadge.tooltip, /一對二已滿/, 'the separate 1v2 capacity detail is kept');
assert.equal(targetConflict([r(1, 'one_on_two'), t(2, 'one_on_three')], 'trial', 'one_on_three').blocked, false);
assert.equal(occupancyBadge({ courses: [r(1, 'one_on_two'), t(2, 'one_on_three'), t(3, 'one_on_three')] }).label, '3/3');
// Untargeted or 1v2-targeted trials keep the class-type maximum.
assert.equal(occupancyBadge({ courses: [r(1, 'one_on_two'), t(2, null)] }).label, '2/2');
assert.equal(occupancyBadge({ courses: [r(1, 'one_on_one'), t(2, 'one_on_three')] }).label, '2/1');

// #4368 slice 1: the reschedule advisory preview must use the same #4342
// one-on-three decision as the guard, including full-span qualification.
const rescheduleTrialPreview = (courses, overrides = {}) => buildReschedulePreview({
  currentCourseId: 90,
  studentId: 900,
  teacherId: 5,
  targetDate: '2026-10-27',
  startTime: '16:00',
  endTime: '18:00',
  classType: 'trial',
  courses,
  ...overrides,
});
const previewCourse = (id, studentId, classType, extra = {}) => ({
  id,
  student_id: studentId,
  teacher_id: 5,
  day_of_week: 2,
  start_time: '16:00',
  end_time: '18:00',
  class_type: classType,
  ...extra,
});

// The current trial's target can arrive as either the course id or student_course_id.
// No regular 1v3 row is present: the binding itself must establish capacity.
for (const current of [
  { ...previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }) },
  { ...previewCourse(901, 900, 'trial', { teacher_id: 8, student_course_id: 90, trial_target_class_type: 'one_on_three' }) },
]) {
  const allowed = rescheduleTrialPreview([
    current,
    previewCourse(1, 1, 'trial'),
    previewCourse(2, 2, 'trial'),
  ]);
  assert.equal(allowed.blocked, false, 'the bound target establishes the third trial seat');
}

// A full-span regular 1v3 independently expands an untargeted incoming trial.
const untargetedWithRegular = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8 }),
  previewCourse(1, 1, 'one_on_three'),
  previewCourse(2, 2, 'trial'),
]);
assert.equal(untargetedWithRegular.blocked, false);

const previewFull = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
  previewCourse(1, 1, 'one_on_three'),
  previewCourse(2, 2, 'trial'),
  previewCourse(3, 3, 'trial'),
]);
assert.equal(previewFull.blocked, true);
assert.match(previewFull.message, /3 位學生/);

// A partial, missing, or malformed existing 1v3 span must not expand a trial slot.
for (const existing of [
  previewCourse(1, 1, 'one_on_three', { start_time: '16:30', end_time: '17:30' }),
  previewCourse(1, 1, 'one_on_three', { start_time: '', end_time: '18:00' }),
  previewCourse(1, 1, 'one_on_three', { start_time: 'bad', end_time: '18:00' }),
]) {
  const partial = rescheduleTrialPreview([
    previewCourse(90, 900, 'trial', { teacher_id: 8 }),
    existing,
    previewCourse(2, 2, 'trial'),
  ]);
  assert.equal(partial.blocked, true);
  assert.match(partial.message, /試聽時段已有安排/);
}

// An incoming targeted trial establishes its own full requested span, but never a fourth seat.
const targetedOnly = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
  previewCourse(1, 1, 'trial'),
]);
assert.equal(targetedOnly.blocked, false);
const targetedFull = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
  previewCourse(1, 1, 'trial'),
  previewCourse(2, 2, 'trial'),
  previewCourse(3, 3, 'trial'),
]);
assert.equal(targetedFull.blocked, true);

// Duplicate schedule rows for one student occupy one seat, leaving the correct third seat.
const duplicateStudentPreview = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
  previewCourse(1, 1, 'trial'),
  previewCourse(2, 1, 'trial'),
  previewCourse(3, 2, 'trial'),
]);
assert.equal(duplicateStudentPreview.blocked, false);
assert.match(duplicateStudentPreview.message, /2 位學生/);

// Missing, ambiguous, or non-trial source rows cannot lend a target to the incoming trial.
for (const sources of [
  [],
  [
    previewCourse(90, 900, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
    previewCourse(90, 901, 'trial', { teacher_id: 8, trial_target_class_type: 'one_on_three' }),
  ],
  [previewCourse(90, 900, 'one_on_three', { teacher_id: 8, trial_target_class_type: 'one_on_three' })],
]) {
  const unbound = rescheduleTrialPreview([
    ...sources,
    previewCourse(1, 1, 'one_on_two'),
    previewCourse(2, 2, 'trial'),
  ]);
  assert.equal(unbound.blocked, true);
  assert.match(unbound.message, /試聽時段已有安排/);
}

// A 1v1 never expands the slot, while its first ordinary trial remains allowed.
const firstAlongsideOne = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8 }),
  previewCourse(1, 1, 'one_on_one'),
]);
assert.equal(firstAlongsideOne.blocked, false);
const secondAlongsideOne = rescheduleTrialPreview([
  previewCourse(90, 900, 'trial', { teacher_id: 8 }),
  previewCourse(1, 1, 'one_on_one'),
  previewCourse(2, 2, 'trial'),
]);
assert.equal(secondAlongsideOne.blocked, true);
assert.match(secondAlongsideOne.message, /試聽時段已有安排/);

// The old non-trial preview uses raw Set identities: do not normalize these values.
const nonTrialIdentityPreview = buildReschedulePreview({
  currentCourseId: 90,
  studentId: 900,
  teacherId: 5,
  targetDate: '2026-10-27',
  startTime: '16:00',
  endTime: '18:00',
  classType: 'one_on_two',
  courses: [
    previewCourse(1, 7, 'one_on_two'),
    previewCourse(2, '007', 'one_on_two'),
  ],
});
assert.equal(nonTrialIdentityPreview.blocked, true);
assert.match(nonTrialIdentityPreview.message, /已達一對二上限/);

// Span comparisons are second-precise and fail closed for missing or invalid values.
const requestedSpan = { startTime: '16:00', endTime: '18:00' };
assert.equal(isOneOnThreeSlot([
  { class_type: 'one_on_three', start_time: '16:00:30', end_time: '18:00' },
], requestedSpan), false, '16:00:30 does not cover a 16:00 request');
assert.equal(isOneOnThreeSlot([
  { class_type: 'one_on_three', start_time: '16:00:99', end_time: '18:00' },
], requestedSpan), false, 'invalid booking seconds fail closed');
assert.equal(isOneOnThreeSlot([
  { class_type: 'one_on_three', start_time: '', end_time: '18:00' },
], requestedSpan), false, 'missing booking times fail closed');
assert.equal(isOneOnThreeSlot([
  { class_type: 'one_on_three', start_time: '16:00', end_time: '18:00' },
], { startTime: '16:00:99', endTime: '18:00' }), false, 'invalid requested seconds fail closed');
assert.equal(incomingClassConflict({
  incomingType: 'trial',
  incomingTrialTarget: 'one_on_three',
  overlappingCourses: [previewCourse(1, 1, 'trial')],
  requestedSpan: { startTime: '16:00:99', endTime: '18:00' },
}).reason, 'trial', 'invalid requested times do not let the synthetic target expand capacity');
