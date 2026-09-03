import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const read = (relativePath) => readFileSync(resolve(__dirname, relativePath), 'utf8');
const appSource = read('../../App.vue');
const courseSource = read('../../pages/CourseManagement.vue');
const studentsSource = read('../../pages/StudentsList.vue');

describe('student focus navigation contract', () => {
  it('carries the expanded course group student into the Students master record', () => {
    expect(courseSource).toContain("studentId: group.student_id");
    expect(courseSource).toContain('const group = { key, student_id: studentId');
    expect(appSource).toContain(':initial-student-id="studentFocusIdForNav"');
    expect(appSource).toContain('studentFocusIdForNav.value = Number.isSafeInteger(normalizedStudentId)');
  });

  it('carries the exact course edit intent into the Students master record', () => {
    expect(courseSource).toContain('const navigateToStudentCourse = (course) =>');
    expect(courseSource).toContain("courseId: Number.isSafeInteger(courseId) && courseId > 0 ? courseId : null");
    expect(courseSource).toContain("intent: 'edit'");
    expect(appSource).toContain(':initial-course-id="studentFocusCourseIdForNav"');
    expect(appSource).toContain(':initial-student-intent="studentFocusIntentForNav"');
    expect(appSource).toContain('const normalizedCourseId = Number(courseId)');
  });

  it('uses the branch-filtered student list as the focus safety boundary', () => {
    expect(studentsSource).toContain('initialStudentId: [String, Number]');
    expect(studentsSource).toContain('initialCourseId: [String, Number]');
    expect(studentsSource).toContain('initialStudentIntent: String');
    expect(studentsSource).toContain('candidate?._laravelId ?? candidate?.id');
    expect(studentsSource).toContain("find((course) => Number(course?.id) === targetCourseId)");
    expect(studentsSource).toContain("if (props.initialStudentIntent === 'edit') editCourse(targetCourse);");
    expect(studentsSource).toContain("else if (props.initialStudentIntent === 'purchase' || props.initialStudentIntent === 'renew')");
    expect(studentsSource).toContain("else if (props.initialStudentIntent === 'close') closeCourseNoRenew(targetCourse, student.name);");
    expect(studentsSource).toContain("emit('clear-initial-student')");
    expect(studentsSource).toContain(':data-student-id="student.id"');
  });

  it('keeps generic Students navigation context-free', () => {
    expect(courseSource).toContain("@click=\"emit('navigate', 'students')\"");
    expect(courseSource).toContain("@click=\"emit('navigate', { target: 'students', studentId: group.student_id })\"");
  });
});
