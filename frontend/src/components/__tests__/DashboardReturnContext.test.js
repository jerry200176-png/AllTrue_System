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

  it('applies the next return context after page navigation clears stale state', () => {
    const appSource = fs.readFileSync(path.resolve(process.cwd(), 'src/App.vue'), 'utf8');
    const start = appSource.indexOf('function onNavigateFromNotifications');
    const end = appSource.indexOf('function onNavigateFromCourseManagement', start);
    const navigationBlock = appSource.slice(start, end);
    expect(navigationBlock.indexOf('setActivePage(target')).toBeGreaterThan(-1);
    expect(navigationBlock.indexOf('dashboardReturnContext.value = nextDashboardReturnContext'))
      .toBeGreaterThan(navigationBlock.indexOf('setActivePage(target'));
  });
});
