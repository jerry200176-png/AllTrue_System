import { isCourseManagementHistory } from './courseMoneyState.js';
import { toYmd } from './calendarDateUtils.js';

// Declared dates are display metadata; EndDate may instead record a later closure.
export const contractStartDate = (course) => [course?.first_class_date, course?.StartDate]
  .map(toYmd)
  .find((value) => /^\d{4}-\d{2}-\d{2}$/.test(value)) || '';
const courseId = (course) => {
  const value = Number(course?.id ?? course?.ID);
  return Number.isFinite(value) && value > 0 ? value : 0;
};

/** Match the API's newest-created (ID DESC) order, retaining contiguous templates and stable unknown IDs last. */
export function buildContractChronology(courses = []) {
  const ordered = [...courses].sort((a, b) => courseId(b) - courseId(a));
  const segments = [];
  for (const [index, course] of ordered.entries()) {
    const kind = isCourseManagementHistory(course) ? 'history' : 'working';
    let segment = segments[segments.length - 1];
    if (segment?.kind !== kind) {
      segment = { kind, key: `${kind}-${course?.id ?? course?.ID ?? `unknown-${index}`}`, courses: [] };
      segments.push(segment);
    }
    segment.courses.push(course);
  }
  return segments;
}
