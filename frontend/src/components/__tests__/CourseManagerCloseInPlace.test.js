// in-app #415: 結束課程 inside the 管理課程 drawer must stay in 課程查找 (close in place,
// then show the course under 歷史課程) instead of jumping to 學生管理.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const managerSource = readFileSync(resolve(__dirname, '../course-management/CourseManager.vue'), 'utf8');
const drawerActions = source.slice(source.indexOf('function onCourseManagerAction('), source.indexOf('function toggleCourseSessionCalendar('));

describe('Course Manager 結束課程 (in-app #415)', () => {
  it('drawer 結束課程 emits the close action', () => {
    expect(managerSource).toContain("@click=\"act('close')\">結束課程</button>");
  });

  it('closes in place and never navigates to 學生管理', () => {
    expect(drawerActions).not.toContain("goToStudentsCommercial(c, 'close')");
    expect(drawerActions).toContain('close: async () => { if (await closeCourseInPlace(c)) closeCourseManager(); }');
  });

  it('reveals the closed course under its student 歷史課程 after a successful close', () => {
    expect(source).toMatch(/const closed = await runCloseCourseNoRenew\(\{[\s\S]*?\}\);\s*if \(closed\) revealHistoryCourse\(course\);\s*return closed;/);
    const reveal = source.slice(source.indexOf('function revealHistoryCourse('), source.indexOf('function revealHistoryCourse(') + 600);
    expect(reveal).toContain('isHistoryCourse(reloaded)');
    expect(reveal).toContain('expandedHistoryGroups.value = new Set([...expandedHistoryGroups.value, key])');
    expect(source).toContain('const key = studentGroupKey(c);');
  });

  // PR #4159 review: the 「進行中」 filter reload omits the stopped course; it is fetched and kept.
  it('retains the closed course after a status-filtered reload without changing the filter', () => {
    const close = source.slice(source.indexOf('async function closeCourseInPlace('), source.indexOf('const courses = ref([]);'));
    expect(close).toMatch(/const reloadRequestId = await reloadCoursesForClose\(\);\s*await retainClosedCourse\(course, reloadRequestId\);/);
    expect(close).toContain('retainClosedCourseInList({');
    expect(close).toContain('student_id: String(studentId)');
    expect(close).not.toContain("params.set('status'");
    expect(close).not.toContain('filters.value');
  });

  // PR #4159 review round 2: the retained course gets the same session load as loadCourses() results.
  it('loads sessions for the retained course with the loadCourses() loaders, guarded by the request id', () => {
    const close = source.slice(source.indexOf('async function closeCourseInPlace('), source.indexOf('const courses = ref([]);'));
    expect(close).toMatch(/loadSessions: async \(list\) => \{[\s\S]*?if \(!isCurrent\(\)\) return;\s*await loadSessionsForCourseList\(list, token, isCurrent\);/);
    const helper = source.slice(source.indexOf('async function loadSessionsForCourseList('), source.indexOf('const courses = ref([]);'));
    expect(helper).toContain('await loadClassSessionsForCourses(list, token, isCurrent)');
    expect(helper).toContain('await loadEffectiveSessionDates(list, token, isCurrent)');
    expect(helper).toContain('sessionDataLoadFailed.value = true');
  });

  // PR #4159 review round 3: retention is tied to the close-triggered reload's own request id.
  it('carries the post-close reload request id into retention instead of re-reading the latest one', () => {
    const close = source.slice(source.indexOf('async function closeCourseInPlace('), source.indexOf('const courses = ref([]);'));
    expect(close).toContain('async function retainClosedCourse(course, requestId) {');
    expect(close).not.toMatch(/const requestId = courseLoadRequestId;/);
    expect(close).toMatch(/const isCurrent = \(\) => requestId === courseLoadRequestId;\s*if \(!isCurrent\(\)\) return;/);
    expect(close).toContain('isCurrent,');
    expect(close).toContain('if (next !== courses.value && isCurrent()) courses.value = next;');
    // The id is read synchronously right after loadCourses() starts, before awaiting it…
    expect(close).toMatch(/const pending = loadCourses\(pagination\.value\.page\);\s*const reloadRequestId = courseLoadRequestId;\s*await pending;\s*return reloadRequestId;/);
    // …which is that load's id because loadCourses() bumps it before its first await.
    expect(source).toMatch(/const loadCourses = async \(page = 1\) => \{\s*const requestId = \+\+courseLoadRequestId;/);
  });
});
