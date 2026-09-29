import { describe, it, expect } from 'vitest';
import { studentSchoolGradeLabel } from './studentSchoolGrade.js';

describe('studentSchoolGradeLabel', () => {
  it('joins school and grade only', () => {
    const c = { student: { SchoolName: ' 中山國中 ', ClassID: 7, Phone: '0912', notes: 'x' } };
    expect(studentSchoolGradeLabel(c)).toBe('中山國中｜國中一年級 (J1)');
  });
  it('handles missing parts', () => {
    expect(studentSchoolGradeLabel({ student: { ClassID: 12 } })).toBe('高中三年級 (H3)');
    expect(studentSchoolGradeLabel({ student: { SchoolName: 'A', ClassID: 0 } })).toBe('A');
    expect(studentSchoolGradeLabel({})).toBe('');
  });
});

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('CourseManagement wiring', () => {
  it('renders school/grade in the student group header', () => {
    const src = readFileSync(resolve(__dirname, '../pages/CourseManagement.vue'), 'utf8');
    expect(src).toContain('data-testid="student-school-grade"');
    expect(src).toContain('studentSchoolGradeLabel(c)');
  });
});
