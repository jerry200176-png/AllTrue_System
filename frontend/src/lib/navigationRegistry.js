// App shell navigation is a presentation registry only.
// Backend permissions and branch scope remain the security boundary.
import { isDirectorRole } from './roleCapabilities.js';


// A hub item is one sidebar entry whose `tabs` are the retired standalone pages.
// `item.page` is the default tab, and every tab keeps its original page key, so old
// ?app_page= URLs, bookmarks and LINE links still resolve to the same screen.
const REPORT_TABS = [
  { page: 'tuition-report', label: '當月學收' },
  { page: 'subject-units', label: '科目數統計' },
  { page: 'parttime-payroll', label: '兼職薪資' },
  { page: 'teacher-eligibility', label: '正職薪資要件' },
];
const SETTINGS_TABS = [
  { page: 'teachers', label: '老師管理', badgeTypes: ['pending_teachers'] },
  { page: 'classroom', label: '教室' },
  { page: 'subject-settings', label: '科目' },
  { page: 'line-integration', label: '家長 LINE 通知' },
  { page: 'binding-management', label: '家長 LINE 綁定' },
  { page: 'binding-conflicts', label: '綁定衝突審查' },
  { page: 'binding-health', label: '綁定健康度' },
];
const SYSTEM_TABS = [
  { page: 'director-accounts', label: '主任審核', badgeTypes: ['director_pending'] },
  { page: 'branch-management', label: '分校管理' },
  { page: 'branch-health-board', label: '分校健康' },
  { page: 'nightly-reconcile', label: '夜間堂數對帳' },
];
const hub = (label, icon, tabs) => ({ page: tabs[0].page, label, icon, tabs });

function directorGroups(role, { admissionsEnabled = true } = {}) {
  const adminItems = [
    { page: 'tuition-collect', label: '帳務中心', icon: 'payments' },
    hub('報表與薪資', 'bar_chart', REPORT_TABS),
    hub('設定', 'settings', SETTINGS_TABS),
  ];
  if (role === 'super_admin') adminItems.push(hub('系統管理', 'admin_panel_settings', SYSTEM_TABS));
  const groups = [
    {
      key: 'overview', title: '今日工作', defaultOpen: true, primary: true,
      items: [
        { page: 'director', label: '今日工作台', icon: 'dashboard' },
      ],
    },
    {
      key: 'teaching', title: '教學現場', defaultOpen: true, primary: true,
      items: [
        { page: 'calendar', label: '班級行事曆', icon: 'calendar_today' },
        { page: 'attendance', label: '出缺勤', icon: 'fact_check', badgeTypes: ['pending_swipe', 'attendance'] },
        { page: 'learning', label: '學習評量', icon: 'assignment', badgeTypes: ['learning_review', 'parent_feedback'] },
      ],
    },
    {
      key: 'students-courses', title: '學生與課程', defaultOpen: true, primary: true,
      items: [
        { page: 'students', label: '學生管理', icon: 'groups' },
        { page: 'course-mgmt', label: '課程查找', icon: 'menu_book', badgeTypes: ['tuition'] },
        { page: 'admission-inquiries', label: '新生問班', icon: 'how_to_reg' },
      ],
    },
    {
      key: 'finance', title: '帳務與管理', defaultOpen: true, primary: true,
      items: adminItems,
    },
  ];
  if (!admissionsEnabled) {
    const group = groups.find(item => item.key === 'students-courses');
    if (group) group.items = group.items.filter(item => item.page !== 'admission-inquiries');
  }
  return groups;
}

// Director top-bar icons (bell / messages / feedback). Same page keys as before, so old URLs still open them;
// they are routable pages that no longer take a sidebar row.
const DIRECTOR_TOOLBAR = [
  { page: 'notifications', label: '待處理收件匣', icon: 'inbox' },
  { page: 'chat', label: '內部訊息', icon: 'forum', badgeTypes: ['chat'] },
  { page: 'bugs', label: '意見與建議', icon: 'rate_review', badgeTypes: ['bugs'] },
];

// Pending-work pages: no sidebar row. The director workbench lists them as to-do items only while something is
// pending (see directorDashboardTasks.js); the page keys stay routable for old URLs and LINE links.
const DIRECTOR_TODO_PAGES = [
  { page: 'schedule-discrepancy', label: '課表回報管理', icon: 'flag', badgeTypes: ['schedule_discrepancy'] },
  { page: 'duplicate-review', label: '重疊課程審核', icon: 'compare_arrows' },
];

/** Routable director pages that live in the workbench to-do list instead of the sidebar. */
export function getTodoPages(role) {
  return isDirectorRole(role) ? DIRECTOR_TODO_PAGES.map(item => ({ ...item, ...(item.badgeTypes ? { badgeTypes: [...item.badgeTypes] } : {}) })) : [];
}

/** Top-bar icon items for a role (teachers keep chat/feedback in their own sidebar group). */
export function getToolbarItems(role) {
  return isDirectorRole(role) ? DIRECTOR_TOOLBAR.map(item => ({ ...item, ...(item.badgeTypes ? { badgeTypes: [...item.badgeTypes] } : {}) })) : [];
}

function teacherGroups({ truefitEnabled = false } = {}) {
  const teachingItems = [
    { page: 'teacher-home', label: '教學工作台', icon: 'space_dashboard' },
    { page: 'calendar', label: '我的課表', icon: 'calendar_today' },
    { page: 'attendance', label: '出缺勤', icon: 'fact_check', badgeTypes: ['attendance'] },
    { page: 'learning', label: '課表與評量', icon: 'assignment', badgeTypes: ['teacher_learning_pending', 'parent_feedback'] },
  ];
  if (truefitEnabled) {
    teachingItems.push({ page: 'truefit', label: 'TrueFit', icon: 'school' });
  }

  return [{
    key: 'teaching', title: '今日教學', defaultOpen: true, primary: true,
    items: teachingItems,
  }, {
    key: 'teaching-tools', title: '教學工具', defaultOpen: false, primary: false,
    items: [
      { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
    ],
  }, {
    key: 'communication', title: '訊息與回報', defaultOpen: false, primary: false,
    items: [
      { page: 'chat', label: '內部聊天', icon: 'forum', badgeTypes: ['chat'] },
      { page: 'bugs', label: '意見與建議', icon: 'rate_review', badgeTypes: ['bugs'] },
    ],
  }];
}

function cloneGroups(groups) {
  return groups.map(group => ({
    ...group,
    items: group.items.map(item => {
      const tabs = item.tabs?.map(tab => ({ ...tab, ...(tab.badgeTypes ? { badgeTypes: [...tab.badgeTypes] } : {}) }));
      const hubBadges = tabs ? [...new Set(tabs.flatMap(tab => tab.badgeTypes || []))] : [];
      const badgeTypes = item.badgeTypes || (hubBadges.length ? hubBadges : null);
      return {
        ...item,
        requiredContext: requiredContextForPage(item.page),
        ...(tabs ? { tabs } : {}),
        ...(badgeTypes ? { badgeTypes: [...badgeTypes] } : {}),
      };
    }),
  }));
}

/** Every routable page key in the groups: sidebar items plus the tabs inside hub items. */
export const navigationPages = (groups) => groups.flatMap(g => g.items.flatMap(i => (i.tabs ? i.tabs.map(t => t.page) : [i.page])));

/** The sidebar item that owns `page` (the hub for a tab, the item itself otherwise). */
export const findNavItem = (groups, page) => groups.flatMap(g => g.items)
  .find(i => i.page === page || i.tabs?.some(t => t.page === page)) || null;
/**
 * Desktop sidebar trigger: 更多功能 only when some group is non-primary; otherwise the panel
 * holds nothing but global search, so the trigger is labelled 搜尋 (in-app #390).
 */
export function sidebarMoreTrigger(groups) {
  return groups.some(group => group.primary === false)
    ? { label: '更多功能', icon: 'apps' }
    : { label: '搜尋', icon: 'search' };
}
/** Return a fresh role-scoped model for every renderer. */
export function getNavigationGroups(role, options = {}) {
  if (isDirectorRole(role)) return cloneGroups(directorGroups(role, options));
  if (role === 'teacher') return cloneGroups(teacherGroups(options));
  return [];
}

/** High-frequency mobile tabs; More is a renderer sentinel, not a page. */
export function getMobileTabItems(role) {
  if (isDirectorRole(role)) {
    return [
      { page: 'director', label: '儀表板', icon: 'dashboard' },
      { page: 'calendar', label: '行事曆', icon: 'calendar_today' },
      { page: 'students', label: '學生', icon: 'groups' },
      { page: 'attendance', label: '出勤', icon: 'fact_check', badgeTypes: ['pending_swipe', 'attendance'] },
      { page: 'more', label: '更多', icon: 'apps' },
    ];
  }
  if (role === 'teacher') {
    return [
      { page: 'teacher-home', label: '工作台', icon: 'space_dashboard' },
      { page: 'attendance', label: '出勤', icon: 'fact_check' },
      { page: 'learning', label: '評量', icon: 'assignment', badgeTypes: ['teacher_learning_pending', 'parent_feedback'] },
      { page: 'chat', label: '聊天', icon: 'forum', badgeTypes: ['chat'] },
      { page: 'more', label: '更多', icon: 'apps' },
    ];
  }
  return [];
}

/**
 * Route-implied acting context (#2908): pages exposed only in one role's menu.
 * Derived from the menus above so it cannot drift; shared pages need no context.
 */
const pagesOf = (groups) => new Set(navigationPages(groups));
// Director-capability pages only: super_admin-only pages (director-accounts, branch-*) must never trigger an auto-switch.
const DIRECTOR_PAGES = new Set([...pagesOf(directorGroups('director')), ...DIRECTOR_TOOLBAR.map(item => item.page), ...DIRECTOR_TODO_PAGES.map(item => item.page)]);
const TEACHER_PAGES = pagesOf(teacherGroups({ truefitEnabled: true }));
export function requiredContextForPage(page) {
  if (DIRECTOR_PAGES.has(page) && !TEACHER_PAGES.has(page)) return 'director';
  if (TEACHER_PAGES.has(page) && !DIRECTOR_PAGES.has(page)) return 'teacher';
  return null;
}
