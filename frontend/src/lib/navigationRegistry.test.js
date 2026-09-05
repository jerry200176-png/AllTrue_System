import { describe, expect, it } from 'vitest';
import { getMobileTabItems, getNavigationGroups } from './navigationRegistry';

const pages = role => getNavigationGroups(role).flatMap(group => group.items.map(item => item.page));

describe('navigation registry', () => {
  it('keeps role visibility and legacy page keys stable', () => {
    expect(pages('admin')).toEqual(pages('director'));
    expect(pages('teacher')).toContain('teacher-home');
    expect(pages('teacher')).not.toContain('tuition-collect');
    expect(pages('director')).toContain('course-mgmt');
    expect(pages('director')).toContain('admission-inquiries');
    expect(pages('teacher')).not.toContain('admission-inquiries');
    expect(pages('super_admin')).toEqual(expect.arrayContaining([
      'director-accounts', 'branch-management', 'branch-health-board', 'nightly-reconcile',
    ]));
    expect(pages('super_admin')).not.toContain('ui-improvements');
    expect(pages('student')).toEqual([]);
  });

  it('hides the admissions entry when the client rollout flag is off', () => {
    expect(getNavigationGroups('director', { admissionsEnabled: false })
      .flatMap(group => group.items.map(item => item.page)))
      .not.toContain('admission-inquiries');
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
    first.find(group => group.key === 'communication').items[0].badgeTypes.push('changed');
    const second = getNavigationGroups('director');
    expect(second[0].items[0].label).toBe('今日工作台');
    expect(second.find(group => group.key === 'communication').items[0].badgeTypes).toEqual(['chat']);
  });

  it('keeps low-frequency tools available without opening every sidebar group by default', () => {
    const groups = getNavigationGroups('director');
    expect(groups.filter(group => group.primary !== false).map(group => group.key)).toEqual([
      'overview', 'teaching', 'students-courses', 'finance',
    ]);
    expect(groups.filter(group => group.primary === false).map(group => group.key)).toEqual([
      'teaching-tools', 'reports-payroll', 'communication', 'settings',
    ]);
    expect(groups.find(group => group.key === 'teaching-tools').defaultOpen).toBe(false);
    expect(groups.find(group => group.key === 'reports-payroll').defaultOpen).toBe(false);
    expect(groups.flatMap(group => group.items.map(item => item.page))).toEqual(expect.arrayContaining([
      'assessments', 'question-banks', 'tuition-report', 'teacher-eligibility', 'chat', 'bugs',
    ]));
  });

  it('keeps every page unique across primary and More destinations', () => {
    for (const role of ['director', 'super_admin', 'teacher']) {
      const rolePages = pages(role);
      expect(new Set(rolePages).size).toBe(rolePages.length);
    }
  });
});
