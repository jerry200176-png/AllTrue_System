/**
 * Mixed class-type slot occupancy (#1889).
 *
 * Remaining seats = capacity(incoming / covered class type) − unique students.
 * A full 1-on-2 must not mark a mixed 1-on-3 slot full.
 * Keep in sync with SubstituteService::capacityForClassType and
 * ScheduleGuardService (absolute teacher max = 3).
 */

import { classTypeLabel } from './calendarFormat.js';

export const TEACHER_SLOT_ABSOLUTE_MAX = 3;

/** Calendar badge / add-course caps (tutoring counts as 1 on the grid). */
export const CLASS_CAPACITY = {
  one_on_one: 1,
  one_on_two: 2,
  one_on_three: 3,
  tutoring: 1,
  trial: 1,
};

/** Availability busy_slots remaining is computed with this map on the backend. */
export const AVAILABILITY_CLASS_CAPACITY = {
  one_on_one: 1,
  one_on_two: 2,
  one_on_three: 3,
  tutoring: 4,
  trial: 1,
};

export function capacityForClassType(type) {
  return CLASS_CAPACITY[String(type || '')] ?? 1;
}

export function availabilityCapacityForClassType(type) {
  return AVAILABILITY_CLASS_CAPACITY[String(type || '')] ?? 1;
}

export function uniqueStudentCount(courses = []) {
  const keys = new Set();
  for (const course of courses) {
    const studentId = Number(course?.student_id || course?.StudentID || 0);
    if (studentId > 0) {
      keys.add(`s:${studentId}`);
      continue;
    }
    const rowId = course?.id ?? course?.ID ?? '';
    keys.add(rowId !== '' ? `c:${rowId}` : `row:${keys.size}`);
  }
  return keys.size;
}

export function displayMaxForClassTypes(types = []) {
  const list = (types || []).map((type) => String(type || ''));
  if (list.includes('one_on_one')) return 1;
  if (list.includes('one_on_three')) return TEACHER_SLOT_ABSOLUTE_MAX;
  if (list.includes('one_on_two')) return 2;
  return TEACHER_SLOT_ABSOLUTE_MAX;
}

export function occupancyBadge({ courses = [] } = {}) {
  const count = uniqueStudentCount(courses);
  if (count === 0) {
    return {
      count: 0,
      max: TEACHER_SLOT_ABSOLUTE_MAX,
      color: '#10b981',
      label: '',
      tooltip: '',
    };
  }
  const types = courses.map((course) => String(course.class_type || ''));
  const max = displayMaxForClassTypes(types);
  const remaining = Math.max(0, max - count);
  const full = count >= max;
  const color = full ? '#ef4444' : remaining === 1 ? '#f59e0b' : '#10b981';
  const status = full ? '已滿' : `可再收 ${remaining} 位`;
  const parts = [];
  for (const type of ['one_on_one', 'one_on_two', 'one_on_three']) {
    if (!types.includes(type)) continue;
    const rem = Math.max(0, capacityForClassType(type) - count);
    const label = classTypeLabel(type);
    parts.push(rem === 0 ? `${label}已滿` : `${label}可再收 ${rem}`);
  }
  const detail = parts.length ? `：${parts.join('，')}` : '';
  return {
    count,
    max,
    color,
    label: `${count}/${max}`,
    tooltip: `本分校 ${count} 位（上限 ${max} 位，${status}）${detail}`,
    remaining,
  };
}

export function incomingClassConflict({ incomingType, overlappingCourses = [] } = {}) {
  const unique = uniqueStudentCount(overlappingCourses);
  const types = overlappingCourses.map((course) => String(course.class_type || ''));
  const newMax = Math.min(capacityForClassType(incomingType), TEACHER_SLOT_ABSOLUTE_MAX);

  if (incomingType === 'trial') {
    const trialCount = uniqueStudentCount(
      overlappingCourses.filter((course) => String(course.class_type || '') === 'trial'),
    );
    return {
      blocked: trialCount >= 1,
      reason: trialCount >= 1 ? 'trial' : '',
      unique,
      newMax: 1,
    };
  }

  if (types.includes('one_on_one')) {
    return { blocked: true, reason: 'one_on_one', unique, newMax };
  }
  if (unique >= TEACHER_SLOT_ABSOLUTE_MAX) {
    return { blocked: true, reason: 'absolute', unique, newMax };
  }
  if (unique >= newMax) {
    return { blocked: true, reason: 'incoming_full', unique, newMax };
  }
  return { blocked: false, reason: '', unique, newMax };
}

export function uniqueOccupantsFromBusySlots(slots = []) {
  let unique = 0;
  for (const slot of slots) {
    const cap = availabilityCapacityForClassType(slot?.class_type);
    const remaining = slot?.remaining_capacity === undefined ? 0 : Number(slot.remaining_capacity);
    unique = Math.max(unique, Math.max(0, cap - remaining));
  }
  return unique;
}

function hhmm(value) {
  return String(value || '').slice(0, 5);
}

export function timesOverlap(aStart, aEnd, bStart, bEnd) {
  const aS = hhmm(aStart);
  const aE = hhmm(aEnd);
  const bS = hhmm(bStart);
  const bE = hhmm(bEnd);
  if (!aS || !aE || !bS || !bE) return false;
  return aS < bE && bS < aE;
}

export function pickerSlotConflict({
  overlappingSlots = [],
  coveredClassType = '',
  sessionCampusId = 0,
  branchNameMap = {},
} = {}) {
  if (!overlappingSlots.length) {
    return { conflict: false, capacityWarn: false, conflictTooltip: '', conflictCampusId: 0 };
  }

  const unique = uniqueOccupantsFromBusySlots(overlappingSlots);
  const hasOneOnOne = overlappingSlots.some(
    (slot) => String(slot.class_type || '') === 'one_on_one',
  );
  const covered = String(coveredClassType || '');
  let remaining;
  if (covered) {
    const coveredCap = Math.min(availabilityCapacityForClassType(covered), TEACHER_SLOT_ABSOLUTE_MAX);
    remaining = hasOneOnOne && covered !== 'trial' ? 0 : Math.max(0, coveredCap - unique);
  } else {
    remaining = Math.max(0, ...overlappingSlots.map((slot) => (
      slot.remaining_capacity === undefined ? 0 : Number(slot.remaining_capacity)
    )));
  }

  const conflict = remaining <= 0 || unique >= TEACHER_SLOT_ABSOLUTE_MAX;
  const capacityWarn = !conflict && unique > 0;
  const sessionCampus = Number(sessionCampusId || 0);
  const other = overlappingSlots.find((slot) => {
    const campusId = Number(slot.campus_id || 0);
    return campusId > 0 && sessionCampus > 0 && campusId !== sessionCampus;
  });
  const local = overlappingSlots.find((slot) => Number(slot.campus_id || 0) > 0) || overlappingSlots[0];
  const conflictCampusId = Number((conflict && (other || local)?.campus_id) || 0);
  let conflictTooltip = '';
  if (conflict) {
    if (other) {
      const name = branchNameMap[other.campus_id] || `分校#${other.campus_id}`;
      conflictTooltip = `於其他分校（${name}）已有課`;
    } else {
      conflictTooltip = '本分校此時段已滿';
    }
  }

  return { conflict, capacityWarn, conflictTooltip, conflictCampusId };
}
