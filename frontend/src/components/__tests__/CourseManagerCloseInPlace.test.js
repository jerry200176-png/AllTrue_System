// in-app #415: 結束課程 inside the 管理課程 drawer must stay in 課程查找 (close in place,
// then the normal list reload) instead of jumping to 學生管理.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const managerSource = readFileSync(resolve(__dirname, '../course-management/CourseManager.vue'), 'utf8');
const drawerActions = source.slice(source.indexOf('function onCourseManagerAction('), source.indexOf('function toggleCourseSessionCalendar('));
const closeFlow = source.slice(source.indexOf('const closedCourseNotice = ref(null);'), source.indexOf('const courses = ref([]);'));

describe('Course Manager 結束課程 (in-app #415)', () => {
  it('drawer 結束課程 emits the close action', () => {
    expect(managerSource).toContain("@click=\"act('close')\">結束課程</button>");
  });

  it('closes in place through the single-flight flow and never navigates to 學生管理', () => {
    expect(drawerActions).not.toContain("goToStudentsCommercial(c, 'close')");
    expect(drawerActions).toContain('close: () => courseCloseFlow.closeFromDrawer(c)');
    expect(closeFlow).toMatch(/function closeCourseInPlace\(course\) \{\s*return courseCloseFlow\.close\(course\);/);
    expect(closeFlow).toContain('getDrawerCourse: () => (courseManagerOpen.value ? courseManagerCourse.value : null)');
  });

  it('only runs the normal reload, then sets the hidden-course notice (no row injection)', () => {
    expect(closeFlow).toContain('reloadCourses: () => loadCourses(pagination.value.page)');
    expect(closeFlow).toContain('closedCourseNotice.value = closedCourseHiddenNotice({ course, courses: courses.value, courseStatus: filters.value.course_status, pendingReconciliation });');
    expect(closeFlow).toContain('onClosed: ({ pendingReconciliation: pending }) => { pendingReconciliation = pending; },');
    expect(source).not.toContain('retainClosedCourse');
    expect(source).not.toContain('revealHistoryCourse');
    expect(closeFlow).not.toContain('courses.value =');
  });

  it('the notice switch button sets 課程狀態 to 「全部」 and reloads page 1', () => {
    expect(source).toContain('data-testid="closed-course-hidden-notice"');
    expect(source).toMatch(/v-if="closedCourseNotice\.canShowAll"[^>]*data-testid="closed-course-show-all" @click="showClosedCourseUnderAllStatuses"/);
    expect(closeFlow).toMatch(/function showClosedCourseUnderAllStatuses\(\) \{\s*closedCourseNotice\.value = null;\s*filters\.value\.course_status = '';\s*loadCourses\(1\);/);
  });

  it('any new list load clears a stale notice', () => {
    expect(source).toMatch(/const loadCourses = async \(page = 1\) => \{\s*const requestId = \+\+courseLoadRequestId;\s*closedCourseNotice\.value = null;/);
  });
});
