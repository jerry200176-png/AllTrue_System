import { isCourseManagementHistory } from './courseMoneyState.js';
import { toYmd } from './calendarDateUtils.js';

// Declared contract start determines display order; EndDate may instead record a later closure.
export const contractStartDate = (course) => [course?.first_class_date, course?.StartDate]
  .map(toYmd)
  .find((value) => /^\d{4}-\d{2}-\d{2}$/.test(value)) || '';
const courseId = (course) => {
  const value = Number(course?.id ?? course?.ID);
  return Number.isFinite(value) && value > 0 ? value : Number.MAX_SAFE_INTEGER;
};

/** Order the loaded contracts as one chain, retaining existing templates in contiguous segments. */
export function buildContractChronology(courses = []) {
  const ordered = [...courses].sort((a, b) => (contractStartDate(a) || '\uffff').localeCompare(contractStartDate(b) || '\uffff') || courseId(a) - courseId(b));
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
