import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = dirname(fileURLToPath(import.meta.url));
const read = (name) => readFileSync(resolve(dir, '../../pages', name), 'utf8');

describe('loading shows placeholders, not fake zero / fake empty', () => {
  it('list headers show the dash until the first load finishes', () => {
    expect(read('StudentsList.vue')).toContain("studentsLoaded ? branchStudentTotal : '—'");
    expect(read('TeachersList.vue')).toContain("teachersLoaded ? teachers.length : '—'");
    expect(read('CourseManagement.vue')).toContain("coursesLoading && !groupedCourses.length ? '—'");
  });

  it('LearningRecordsPage keeps the skeleton until the first fetch settles', () => {
    const s = read('LearningRecordsPage.vue');
    expect(s).toContain('(recordsPagination.loading || !recordsSettled) && records.length === 0');
    expect(s).toContain('recordsSettled.value = true');
  });

  it('BranchHealthBoard starts in loading state with a skeleton', () => {
    const s = read('BranchHealthBoard.vue');
    expect(s).toContain('const loading = ref(true)');
    expect(s).toContain('<AtSkeleton v-if="loading"');
  });
});

describe('one source per count on the director dashboard', () => {
  const s = read('DirectorDashboard.vue');
  it('pending evaluations use the server total, not the 100-row page length', () => {
    expect(s).toContain('pendingJson.total');
    expect(s).not.toMatch(/\{\{ pendingEvaluations\.length \}\}/);
  });
  it('unread notifications come from action-inbox/count like sidebar and center', () => {
    expect(s).toContain('/v1/action-inbox/count');
  });
  it('completed lessons = total - pending so progress card matches attendance list', () => {
    expect(s).toContain('todaySchedules.value.length - pendingAttendanceCount.value');
  });
});
