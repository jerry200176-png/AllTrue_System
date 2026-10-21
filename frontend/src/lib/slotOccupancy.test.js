import assert from 'node:assert/strict';
import {
  occupancyBadge,
  incomingClassConflict,
  pickerSlotConflict,
  uniqueStudentCount,
  coursesWithSlotConflicts,
} from './slotOccupancy.js';
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
