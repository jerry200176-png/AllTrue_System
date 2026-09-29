import { GRADES } from './constants.js';

// Student.ClassID 1..12 follows GRADES order (P1..P6, J1..J3, H1..H3); see StudentController::GRADE_TO_CLASS.
// Only school and grade are read from the embedded student; no other student field is surfaced.
export function studentSchoolGradeLabel(course) {
  const s = course?.student;
  const school = String(s?.SchoolName ?? '').trim();
  const grade = GRADES[Number(s?.ClassID) - 1]?.label || '';
  return [school, grade].filter(Boolean).join('｜');
}
