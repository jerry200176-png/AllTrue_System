import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { getStudentCourseSubjectDisplayLabel } from '../../lib/studentCourseSubjectDisplay.js';

describe('student course subject display', () => {
  it('preserves the configured course label alongside its normalized key', () => {
    expect(getStudentCourseSubjectDisplayLabel({ subject: 'Science', subject_name: '自然' })).toBe('自然');
  });

  it('keeps canonical display and fallback behavior', () => {
    expect(getStudentCourseSubjectDisplayLabel({ subject: 'Science', subject_name: '理化' })).toBe('理化');
    expect(getStudentCourseSubjectDisplayLabel({ subject: 'Science' })).toBe('理化');
    expect(getStudentCourseSubjectDisplayLabel({ subject: 'Math', subject_name: '數學' })).toBe('數學');
  });

  it('passes the API source name through and uses the display helper on the student course row', () => {
    const studentsList = readFileSync(resolve(process.cwd(), 'src/pages/StudentsList.vue'), 'utf8');
    expect(studentsList).toMatch(/subject_name: c\.subject_name \?\? null/);
    expect(studentsList).toMatch(/getStudentCourseSubjectDisplayLabel\(course\)\.split\('\('\)\[0\]\.trim\(\)/);
  });
});
