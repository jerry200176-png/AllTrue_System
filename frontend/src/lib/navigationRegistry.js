// App shell navigation is a presentation registry only.
// Backend permissions and branch scope remain the security boundary.

const DIRECTOR_ROLES = new Set(['director', 'admin', 'super_admin']);

function directorGroups(role) {
  const systemItems = [
    { page: 'line-integration', label: '家長 LINE 通知', icon: 'chat' },
    { page: 'binding-management', label: 'LINE 綁定管理', icon: 'link' },
    { page: 'binding-conflicts', label: '綁定衝突審查', icon: 'gpp_maybe' },
    { page: 'binding-health', label: '綁定健康度', icon: 'monitor_heart' },
  ];
  if (role === 'super_admin') {
    systemItems.push(
      { page: 'director-accounts', label: '主任審核', icon: 'admin_panel_settings', badgeTypes: ['director_pending'] },
      { page: 'branch-management', label: '分校管理', icon: 'store' },
      { page: 'branch-health-board', label: '分校健康', icon: 'monitor_heart' },
      { page: 'nightly-reconcile', label: '夜間堂數對帳', icon: 'receipt_long' },
    );
  }
  return [
    {
      key: 'overview', title: '今日工作', defaultOpen: true,
      items: [
        { page: 'director', label: '今日工作台', icon: 'dashboard' },
        { page: 'notifications', label: '待處理收件匣', icon: 'inbox' },
        { page: 'chat', label: '內部訊息', icon: 'forum', badgeTypes: ['chat'] },
        { page: 'bugs', label: 'Bug 回報', icon: 'bug_report', badgeTypes: ['bugs'] },
      ],
    },
    {
      key: 'teaching', title: '教學現場', defaultOpen: true,
      items: [
        { page: 'calendar', label: '班級行事曆', icon: 'calendar_today' },
        { page: 'attendance', label: '出缺勤', icon: 'fact_check', badgeTypes: ['pending_swipe', 'attendance'] },
        { page: 'schedule-discrepancy', label: '課表回報管理', icon: 'flag', badgeTypes: ['schedule_discrepancy'] },
        { page: 'learning', label: '學習評量', icon: 'assignment', badgeTypes: ['learning_review', 'parent_feedback'] },
        { page: 'assessments', label: '學習檢測', icon: 'grading' },
        { page: 'question-banks', label: '題庫管理', icon: 'quiz' },
        { page: 'duplicate-review', label: '重疊課程審核', icon: 'compare_arrows' },
      ],
    },
    {
      key: 'students-courses', title: '學生與課程', defaultOpen: true,
      items: [
        { page: 'students', label: '學生管理', icon: 'groups' },
        { page: 'course-mgmt', label: '課程查找', icon: 'menu_book', badgeTypes: ['tuition'] },
      ],
    },
    {
      key: 'finance', title: '財務與人事', defaultOpen: true,
      items: [
        { page: 'tuition-collect', label: '帳務中心', icon: 'payments' },
        { page: 'tuition-report', label: '當月學收', icon: 'bar_chart' },
        { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
        { page: 'parttime-payroll', label: '兼職薪資', icon: 'account_balance_wallet' },
        { page: 'teacher-eligibility', label: '正職薪資要件', icon: 'rule' },
        { page: 'teachers', label: '老師管理', icon: 'badge', badgeTypes: ['pending_teachers'] },
      ],
    },
    {
      key: 'settings', title: '設定與資源', defaultOpen: false,
      items: [
        { page: 'classroom', label: '教室管理', icon: 'meeting_room' },
        { page: 'subject-settings', label: '科目管理', icon: 'library_books' },
        ...systemItems,
      ],
    },
  ];
}

function teacherGroups() {
  return [{
    key: 'teaching', title: '今日教學', defaultOpen: true,
    items: [
      { page: 'teacher-home', label: '教學工作台', icon: 'space_dashboard' },
      { page: 'calendar', label: '我的課表', icon: 'calendar_today' },
      { page: 'attendance', label: '出缺勤', icon: 'fact_check', badgeTypes: ['attendance'] },
      { page: 'learning', label: '課表與評量', icon: 'assignment', badgeTypes: ['teacher_learning_pending', 'parent_feedback'] },
      { page: 'assessments', label: '學習檢測', icon: 'grading' },
      { page: 'question-banks', label: '題庫管理', icon: 'quiz' },
      { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
      { page: 'chat', label: '內部聊天', icon: 'forum', badgeTypes: ['chat'] },
      { page: 'bugs', label: 'Bug 回報', icon: 'bug_report', badgeTypes: ['bugs'] },
    ],
  }];
}

function cloneGroups(groups) {
  return groups.map(group => ({
    ...group,
    items: group.items.map(item => ({
      ...item,
      ...(item.badgeTypes ? { badgeTypes: [...item.badgeTypes] } : {}),
    })),
  }));
}

/** Return a fresh role-scoped model for every renderer. */
export function getNavigationGroups(role) {
  if (DIRECTOR_ROLES.has(role)) return cloneGroups(directorGroups(role));
  if (role === 'teacher') return cloneGroups(teacherGroups());
  return [];
}

/** High-frequency mobile tabs; More is a renderer sentinel, not a page. */
export function getMobileTabItems(role) {
  if (DIRECTOR_ROLES.has(role)) {
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

