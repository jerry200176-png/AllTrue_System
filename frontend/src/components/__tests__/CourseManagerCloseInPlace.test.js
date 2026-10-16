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

  it('a failed refresh after a close shows no hidden-by-filter notice (Codex r4289552000)', () => {
    expect(closeFlow).toContain('onReloadFailed: () => { reloadFailed = true; },');
    expect(closeFlow).toMatch(/if \(closed && !reloadFailed\) \{\s*closedCourseNotice\.value = closedCourseHiddenNotice\(/);
  });

  it('loadCourses keeps the previous rows and resolves { ok: false } when the API and the Supabase fallback both fail', () => {
    const load = source.slice(source.indexOf('const loadCourses = async (page = 1) => {'), source.indexOf('const {\n  showRescheduleModal'));
    const fallback = load.slice(load.indexOf('const { data, error: fallbackError } = await query;'));
    expect(fallback).toMatch(/^const \{ data, error: fallbackError \} = await query;\s*const fallbackRows = fallbackListRows\(\{ data, error: fallbackError \}\);\s*if \(fallbackRows === null\) \{/);
    const failure = fallback.slice(0, fallback.indexOf('let result = fallbackRows.map('));
    expect(failure).toContain("return { ok: false, error: fallbackError || new Error('course list unavailable') };");
    expect(failure).not.toContain('sessionsByCourse.value =');
    expect(failure).toContain('coursesLoading.value = false;');
    expect(failure).not.toContain('courses.value =');
    expect(failure).not.toContain('pagination.value =');
    expect(load).not.toContain('(data || []).map(');
  });

  it('loadCourses clears the session caches only when a request wins, never at start (Codex r4289665973 / #4189)', () => {
    const load = source.slice(source.indexOf('const loadCourses = async (page = 1) => {'), source.indexOf('const {\n  showRescheduleModal'));
    const start = load.slice(load.indexOf('coursesLoading.value = true;'), load.indexOf('  try {'));
    expect(start).toContain('const sessionCaches = [completedSessionDatesByCourse, sessionsByCourse];');
    expect(start).not.toMatch(/(completedSessionDatesByCourse|sessionsByCourse)\.value = \{\}/);
    expect(load).not.toContain('previousSessionData');
    const wins = load.match(/courses\.value = result;\s*clearRowCachesForWinningRequest\(requestId, courseLoadRequestId, sessionCaches\);/g) || [];
    expect(wins).toHaveLength(2);
  });

  it('pause/resume share the close flow\'s per-course lifecycle lock (PR #4159 r4286957773)', () => {
    expect(source).toMatch(/\} = useCoursePause\(\{\s*courseLock: courseCloseFlow,/);
    expect(drawerActions).toContain('pause: () => requestCoursePause(c), resume: () => requestCoursePause(c),');
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
    // Goes through the helper that restores 課程狀態 + the notice when the reload fails (Codex r4289925542).
    expect(closeFlow).toMatch(/function showClosedCourseUnderAllStatuses\(\) \{\s*return switchClosedCourseNoticeToAllStatuses\(\{/);
    expect(closeFlow).toContain('notice: closedCourseNotice.value,');
    expect(closeFlow).toContain('setStatus: (status) => { filters.value.course_status = status; },');
    expect(closeFlow).toContain('setNotice: (notice) => { closedCourseNotice.value = notice; },');
    expect(closeFlow).toContain('reload: () => loadCourses(1),');
  });

  it('any new list load clears a stale notice', () => {
    expect(source).toMatch(/const loadCourses = async \(page = 1\) => \{\s*const requestId = \+\+courseLoadRequestId;\s*closedCourseNotice\.value = null;/);
  });
});
