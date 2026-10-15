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
});
