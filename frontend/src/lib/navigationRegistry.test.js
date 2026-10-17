import { describe, expect, it } from 'vitest';
import { findNavItem, getMobileTabItems, getNavigationGroups, getTodoPages, getToolbarItems, navigationPages, requiredContextForPage } from './navigationRegistry';

const pages = role => navigationPages(getNavigationGroups(role));
const sidebar = role => getNavigationGroups(role).flatMap(group => group.items.map(item => item.label));

describe('navigation registry', () => {
  it('keeps role visibility and legacy page keys stable', () => {
    expect(pages('admin')).toEqual(pages('director'));
    expect(pages('teacher')).toContain('teacher-home');
    expect(pages('teacher')).not.toContain('tuition-collect');
    expect(pages('director')).toContain('course-mgmt');
    expect(pages('director')).toContain('admission-inquiries');
    expect(pages('teacher')).not.toContain('admission-inquiries');
    expect(pages('director')).not.toEqual(expect.arrayContaining(['assessments', 'question-banks']));
    expect(pages('teacher')).not.toEqual(expect.arrayContaining(['assessments', 'question-banks']));
    expect(pages('super_admin')).not.toEqual(expect.arrayContaining(['assessments', 'question-banks']));
    expect(pages('director')).toContain('exam-grades');
    expect(pages('teacher')).toContain('exam-grades');
    expect(pages('super_admin')).toEqual(expect.arrayContaining([
      'director-accounts', 'branch-management', 'branch-health-board', 'nightly-reconcile',
    ]));
    expect(pages('super_admin')).not.toContain('ui-improvements');
    expect(pages('student')).toEqual([]);
  });

  it('hides the admissions entry when the client rollout flag is off', () => {
    expect(navigationPages(getNavigationGroups('director', { admissionsEnabled: false })))
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
    first.find(group => group.key === 'teaching').items[1].badgeTypes.push('changed');
    getToolbarItems('director')[1].badgeTypes.push('changed');
    const second = getNavigationGroups('director');
    expect(second[0].items[0].label).toBe('今日工作台');
    expect(second.find(group => group.key === 'teaching').items[1].badgeTypes).toEqual(['pending_swipe', 'attendance']);
    expect(getToolbarItems('director')[1].badgeTypes).toEqual(['chat']);
  });

  it('keeps low-frequency tools available without opening every sidebar group by default', () => {
    const groups = getNavigationGroups('director');
    expect(groups.filter(group => group.primary !== false).map(group => group.key)).toEqual([
      'overview', 'teaching', 'students-courses', 'finance',
    ]);
    expect(groups.filter(group => group.primary === false)).toEqual([]);
    expect(navigationPages(groups)).toEqual(expect.arrayContaining([
      'tuition-report', 'teacher-eligibility',
    ]));
  });

  it('keeps every page unique across primary and More destinations', () => {
    for (const role of ['director', 'super_admin', 'teacher']) {
      const rolePages = pages(role);
      expect(new Set(rolePages).size).toBe(rolePages.length);
    }
  });

  it('folds reports, settings and system admin into three tabbed hubs', () => {
    const hubs = role => getNavigationGroups(role).flatMap(g => g.items).filter(i => i.tabs);
    expect(hubs('director').map(h => [h.label, h.tabs.map(t => t.label)])).toEqual([
      ['報表與薪資', ['當月學收', '科目數統計', '兼職薪資', '正職薪資要件']],
      ['設定', ['老師管理', '教室', '科目', '家長 LINE 通知', '家長 LINE 綁定', '綁定衝突審查', '綁定健康度']],
    ]);
    expect(hubs('super_admin').map(h => h.label)).toEqual(['報表與薪資', '設定', '系統管理']);
    expect(hubs('super_admin')[2].tabs.map(t => t.page)).toEqual([
      'director-accounts', 'branch-management', 'branch-health-board', 'nightly-reconcile',
    ]);
    expect(hubs('teacher')).toEqual([]);
    for (const hub of hubs('super_admin')) expect(hub.page).toBe(hub.tabs[0].page);
  });

  it('keeps the director sidebar short and the hub badge equal to its tabs', () => {
    expect(sidebar('director')).toEqual([
      '今日工作台', '班級行事曆', '出缺勤', '學習評量', '學生管理', '課程查找', '新生問班', '帳務中心', '報表與薪資', '設定',
    ]);
    expect(sidebar('super_admin')).toContain('系統管理');
    expect(sidebar('director')).not.toContain('系統管理');
    const settings = getNavigationGroups('director').flatMap(g => g.items).find(i => i.label === '設定');
    expect(settings.badgeTypes).toEqual(['pending_teachers']);
    const system = getNavigationGroups('super_admin').flatMap(g => g.items).find(i => i.label === '系統管理');
    expect(system.badgeTypes).toEqual(['director_pending']);
  });

  // Retired standalone sidebar entries: old ?app_page= URLs, bookmarks and LINE links keep working
  // because each old key is now a tab of its hub (same key, same screen).
  const RETIRED = {
    director: {
      'tuition-report': ['報表與薪資', '當月學收'],
      'subject-units': ['報表與薪資', '科目數統計'],
      'parttime-payroll': ['報表與薪資', '兼職薪資'],
      'teacher-eligibility': ['報表與薪資', '正職薪資要件'],
      teachers: ['設定', '老師管理'],
      classroom: ['設定', '教室'],
      'subject-settings': ['設定', '科目'],
      'line-integration': ['設定', '家長 LINE 通知'],
      'binding-management': ['設定', '家長 LINE 綁定'],
      'binding-conflicts': ['設定', '綁定衝突審查'],
      'binding-health': ['設定', '綁定健康度'],
    },
    super_admin: {
      'director-accounts': ['系統管理', '主任審核'],
      'branch-management': ['系統管理', '分校管理'],
      'branch-health-board': ['系統管理', '分校健康'],
      'nightly-reconcile': ['系統管理', '夜間堂數對帳'],
    },
  };
  for (const [role, map] of Object.entries(RETIRED)) {
    for (const [key, [hubLabel, tabLabel]] of Object.entries(map)) {
      it(`retired key ${key} still resolves to ${hubLabel} > ${tabLabel} for ${role}`, () => {
        const groups = getNavigationGroups(role);
        expect(navigationPages(groups)).toContain(key);
        const item = findNavItem(groups, key);
        expect(item.label).toBe(hubLabel);
        expect(item.tabs.find(t => t.page === key).label).toBe(tabLabel);
      });
    }
  }

  it('does not expose super_admin-only tabs to directors or switch context for them', () => {
    expect(pages('director')).not.toEqual(expect.arrayContaining(['director-accounts']));
    expect(findNavItem(getNavigationGroups('director'), 'nightly-reconcile')).toBeNull();
    expect(requiredContextForPage('director-accounts')).toBeNull();
    expect(requiredContextForPage('teachers')).toBe('director');
    expect(requiredContextForPage('subject-units')).toBeNull();
  });

  it('moves the inbox, messages and feedback to top-bar icons for directors only', () => {
    expect(getToolbarItems('director').map(i => [i.page, i.label, i.badgeTypes || []])).toEqual([
      ['notifications', '待處理收件匣', []],
      ['chat', '內部訊息', ['chat']],
      ['bugs', '意見與建議', ['bugs']],
    ]);
    expect(getToolbarItems('super_admin').map(i => i.page)).toEqual(['notifications', 'chat', 'bugs']);
    expect(getToolbarItems('teacher')).toEqual([]);
    expect(getToolbarItems('student')).toEqual([]);
    for (const page of ['notifications', 'chat', 'bugs']) {
      expect(pages('director')).not.toContain(page);
      expect(sidebar('director')).not.toContain(getToolbarItems('director').find(i => i.page === page).label);
    }
    // teacher sidebar is unchanged
    expect(pages('teacher')).toEqual(expect.arrayContaining(['chat', 'bugs']));
  });

  it('keeps retired inbox/messages/feedback keys director-routable and never forces a context switch for shared pages', () => {
    expect(requiredContextForPage('notifications')).toBe('director');
    expect(requiredContextForPage('chat')).toBeNull();
    expect(requiredContextForPage('bugs')).toBeNull();
  });

  it('keeps schedule reports and duplicate review routable but off the sidebar (workbench to-do items)', () => {
    expect(getTodoPages('director').map(i => [i.page, i.label, i.badgeTypes || []])).toEqual([
      ['schedule-discrepancy', '課表回報管理', ['schedule_discrepancy']],
      ['duplicate-review', '重疊課程審核', []],
    ]);
    expect(getTodoPages('teacher')).toEqual([]);
    for (const page of ['schedule-discrepancy', 'duplicate-review']) {
      expect(pages('director')).not.toContain(page);
      expect(requiredContextForPage(page)).toBe('director');
    }
    getTodoPages('director')[0].badgeTypes.push('changed');
    expect(getTodoPages('director')[0].badgeTypes).toEqual(['schedule_discrepancy']);
  });
});
