import { getSubjectLabel } from './constants.js';

/** Prefer a course's persisted subject name when presenting its subject. */
export function getStudentCourseSubjectDisplayLabel(course) {
  const subjectName = String(course?.subject_name ?? '').trim();
  if (subjectName === '自然') return subjectName;
  return getSubjectLabel(course?.subject || subjectName);
}
