import assert from 'node:assert/strict';
import {
  occupancyBadge,
  incomingClassConflict,
  pickerSlotConflict,
  uniqueStudentCount,
} from './slotOccupancy.js';

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

const dupStudent = uniqueStudentCount([
  { id: 1, student_id: 214, class_type: 'one_on_three' },
  { id: 2, student_id: 214, class_type: 'one_on_three' },
]);
assert.equal(dupStudent, 1);
