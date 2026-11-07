import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const dir = dirname(fileURLToPath(import.meta.url));
const course = readFileSync(resolve(dir, '../../pages/CourseManagement.vue'), 'utf8');
const students = readFileSync(resolve(dir, '../../pages/StudentsList.vue'), 'utf8');

describe('in-app #519: 新增課程 on 課程查找 reuses the 學生管理 flow', () => {
  it('both pages open the one UniversalClassScheduler; the course page adds no second copy', () => {
    expect(course.match(/<UniversalClassScheduler/g)).toHaveLength(1);
    expect(course.match(/import UniversalClassScheduler/g)).toHaveLength(1);
    expect(students).toContain('import UniversalClassScheduler');
  });

  it('the header button opens it in create mode with no student chosen, so the student is picked first', () => {
    const open = course.slice(course.indexOf('function openCreateCourse()'), course.indexOf('async function duplicateCourseForTeacher'));
    expect(course).toContain('@click="openCreateCourse"');
    expect(open).toContain("schedulerInitialStudentId.value = ''");
    expect(open).toContain("schedulerMode.value = 'create'");
    expect(open).toContain('showBackfillModal.value = true');
    expect(course).toContain(':mode="schedulerMode"');
    expect(course).toContain("schedulerMode.value = 'backfill'"); // 複製課程 (雙師) keeps its own mode
    expect(students).toContain('mode="create"');
  });

  it('each page says its role in one line: 學生管理 the person, 課程管理 the courses', () => {
    expect(students).toContain('管理「人」');
    expect(course).toContain('管理「課」');
  });
});
