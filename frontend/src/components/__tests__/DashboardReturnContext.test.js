import { describe, expect, it } from 'vitest';
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
});

