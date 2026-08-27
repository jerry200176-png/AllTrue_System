import { describe, expect, it } from 'vitest';
import { getMobileTabItems, getNavigationGroups } from './navigationRegistry';

const pages = role => getNavigationGroups(role).flatMap(group => group.items.map(item => item.page));

describe('navigation registry', () => {
  it('keeps role visibility and legacy page keys stable', () => {
    expect(pages('admin')).toEqual(pages('director'));
    expect(pages('teacher')).toContain('teacher-home');
    expect(pages('teacher')).not.toContain('tuition-collect');
    expect(pages('director')).toContain('course-mgmt');
    expect(pages('super_admin')).toEqual(expect.arrayContaining([
      'director-accounts', 'branch-management', 'branch-health-board', 'nightly-reconcile',
    ]));
    expect(pages('student')).toEqual([]);
  });

  it('keeps every pinned mobile page in the same role-scoped registry', () => {
    for (const role of ['director', 'super_admin', 'teacher']) {
      const rolePages = new Set(pages(role));
      for (const tab of getMobileTabItems(role).filter(item => item.page !== 'more')) {
        expect(rolePages.has(tab.page)).toBe(true);
      }
    }
  });

  it('returns fresh nested data so renderers cannot mutate the source model', () => {
    const first = getNavigationGroups('director');
    first[0].items[0].label = 'changed';
    first[0].items[2].badgeTypes.push('changed');
    const second = getNavigationGroups('director');
    expect(second[0].items[0].label).toBe('今日工作台');
    expect(second[0].items[2].badgeTypes).toEqual(['chat']);
  });
});

