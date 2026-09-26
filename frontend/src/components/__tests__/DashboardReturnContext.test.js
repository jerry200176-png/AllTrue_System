import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { createDashboardReturnContext } from '../../lib/dashboardReturnContext';

describe('dashboard return context', () => {
  it('offers a return path for task-first director navigation', () => {
    expect(createDashboardReturnContext({ fromPage: 'director', target: 'tuition-collect' }))
      .toEqual({ page: 'director', label: '回到主任今日工作' });
    expect(createDashboardReturnContext({ fromPage: 'director', target: 'learning' }))
      .toEqual({ page: 'director', label: '回到主任今日工作' });
  });

  it('does not leak the dashboard context into unrelated navigation', () => {
    expect(createDashboardReturnContext({ fromPage: 'notifications', target: 'learning' })).toBeNull();
    expect(createDashboardReturnContext({ fromPage: 'director', target: 'profile' })).toBeNull();
  });

  it('carries a verifiable course context from Course Management to Students', () => {
    expect(createDashboardReturnContext({
      fromPage: 'course-mgmt',
      target: 'students',
      studentId: 12,
      courseId: 34,
    })).toEqual({ page: 'course-mgmt', label: '回到課程管理', studentId: 12, courseId: 34 });
    expect(createDashboardReturnContext({
      fromPage: 'course-mgmt', target: 'students', studentId: 'bad', courseId: 34,
    })).toBeNull();
  });

  it('applies the next return context after page navigation clears stale state', () => {
    const frontendRoot = fs.existsSync(path.resolve(process.cwd(), 'src/App.vue'))
      ? process.cwd()
      : path.resolve(process.cwd(), 'frontend');
    const appSource = fs.readFileSync(path.resolve(frontendRoot, 'src/App.vue'), 'utf8');
    const start = appSource.indexOf('function onNavigateFromNotifications');
    const end = appSource.indexOf('function onNavigateFromCourseManagement', start);
    const navigationBlock = appSource.slice(start, end);
    expect(navigationBlock.indexOf('setActivePage(target')).toBeGreaterThan(-1);
    expect(navigationBlock.indexOf('dashboardReturnContext.value = nextDashboardReturnContext'))
      .toBeGreaterThan(navigationBlock.indexOf('setActivePage(target'));
    expect(appSource).toContain("context?.page === 'course-mgmt'");
    expect(appSource).toContain("target: 'course-mgmt'");
    expect(appSource).toContain(':initial-course-id="courseMgmtFocusCourseId"');
    expect(appSource).toContain('courseMgmtFocusCourseId.value = normalizeNavigationId(courseId)');
  });
});
