function hourOf(value) {
  const hour = Number.parseInt(String(value || '').split(':')[0], 10);
  return Number.isFinite(hour) ? hour : 0;
}

/**
 * Decide which domain workflow owns a calendar drag.
 *
 * A teacher change always belongs to the atomic substitute endpoint, even when
 * the drop also changes date/time. Treating that gesture as a plain reschedule
 * changes the visual schedule without transferring attendance/evaluation
 * ownership to the substitute teacher.
 */
export function resolveCalendarDropAction({
  originalDate = '',
  targetDate = '',
  originalStart = '',
  targetHour = 0,
  currentTeacherId = null,
  targetTeacherId = null,
} = {}) {
  const normalizedTargetHour = Number(targetHour) || 0;
  const sameDate = String(targetDate) === String(originalDate);
  const sameHour = hourOf(originalStart) === normalizedTargetHour;
  const hasTeacherTarget = targetTeacherId !== null && targetTeacherId !== '';
  const hasCurrentTeacher = currentTeacherId !== null && currentTeacherId !== '';
  const teacherChanged = hasTeacherTarget
    && hasCurrentTeacher
    && Number(targetTeacherId) !== Number(currentTeacherId);

  if (sameDate && sameHour && !teacherChanged) {
    return { kind: 'noop', timeChanged: false };
  }

  if (teacherChanged) {
    return { kind: 'substitute', timeChanged: !sameDate || !sameHour };
  }

  return { kind: 'reschedule', timeChanged: !sameDate || !sameHour };
}
