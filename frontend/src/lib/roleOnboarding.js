export const ROLE_ONBOARDING_VERSION = '2026-09-04';

const ROLE_ONBOARDING_STEPS = {
  director: [
    {
      id: 'director-dashboard',
      page: 'director',
      target: '[data-guide="director-summary"]',
      icon: 'dashboard',
      title: '先從主任儀表板開始',
      description: '登入後先看今日數據與提醒，再依優先順序處理分校工作。這裡只提供導覽，不會替你修改資料。',
      placement: 'bottom',
    },
    {
      id: 'director-notifications',
      page: 'notifications',
      target: '[data-guide="notifications-header"]',
      icon: 'notifications',
      title: '查看需要處理的通知',
      description: '通知中心集中顯示繳費、評量與出缺勤等待辦；點「下一步」會帶你前往這個工作頁。',
      placement: 'bottom',
    },
    {
      id: 'director-calendar',
      page: 'calendar',
      target: '[data-guide="calendar-toolbar"]',
      icon: 'calendar_month',
      title: '掌握本週課表',
      description: '用日期與篩選工具快速定位老師、教室或科目，再從課表查看需要處理的課程。',
      placement: 'bottom',
    },
    {
      id: 'director-learning',
      page: 'learning',
      target: '[data-guide="learning-header"]',
      icon: 'task_alt',
      title: '審核學習評量',
      description: '最後到學習評量查看老師提交的紀錄與待審項目；需要寫入資料時，請依畫面提示確認後再操作。',
      placement: 'bottom',
    },
  ],
  teacher: [
    {
      id: 'teacher-home',
      page: 'teacher-home',
      target: '[data-guide="teacher-home-today"]',
      icon: 'today',
      title: '從今日待辦開始',
      description: '教學工作台會把今天最重要的點名與評量待辦放在前面，登入後先從這裡開始。',
      placement: 'bottom',
    },
    {
      id: 'teacher-attendance',
      page: 'attendance',
      target: '[data-guide="attendance-header"]',
      icon: 'how_to_reg',
      title: '完成出缺勤管理',
      description: '到出缺勤管理查看今天的課堂，依課程逐堂完成點名；導覽不會替你送出出缺勤資料。',
      placement: 'bottom',
    },
    {
      id: 'teacher-learning',
      page: 'learning',
      target: '[data-guide="learning-header"]',
      icon: 'school',
      title: '接著完成學習評量',
      description: '點名後可到學習評量查看學生紀錄、補填與待回覆項目，維持每日教學紀錄完整。',
      placement: 'bottom',
    },
    {
      id: 'teacher-calendar',
      page: 'calendar',
      target: '[data-guide="calendar-header"]',
      icon: 'calendar_today',
      title: '用我的課表安排下一步',
      description: '最後回到課表確認接下來的課堂與學生清單，形成「待辦 → 點名 → 評量 → 課表」的日常流程。',
      placement: 'bottom',
    },
  ],
};

const STORAGE_PREFIX = 'alltrue_role_onboarding';

function storageFor(storage) {
  if (storage) return storage;
  if (typeof localStorage === 'undefined') return null;
  return localStorage;
}

export function isOnboardingRole(role) {
  return role === 'teacher' || role === 'director' || role === 'admin' || role === 'super_admin';
}

export function getRoleOnboardingSteps(role) {
  const key = role === 'teacher' ? 'teacher' : 'director';
  return (ROLE_ONBOARDING_STEPS[key] || []).map((step) => ({ ...step }));
}

export function onboardingStorageKey({ role, userId } = {}) {
  const normalizedRole = role === 'teacher' ? 'teacher' : 'director';
  const normalizedUserId = String(userId || '').trim();
  return `${STORAGE_PREFIX}:${normalizedRole}:${normalizedUserId || 'anonymous'}`;
}

export function readOnboardingState({ role, userId, storage } = {}) {
  const targetStorage = storageFor(storage);
  if (!targetStorage) return null;
  try {
    const raw = targetStorage.getItem(onboardingStorageKey({ role, userId }));
    if (!raw) return null;
    const value = JSON.parse(raw);
    if (!value || typeof value !== 'object') return null;
    if (!['in_progress', 'completed', 'skipped'].includes(value.status)) return null;
    return {
      version: String(value.version || ''),
      status: value.status,
      stepIndex: Number.isInteger(value.stepIndex) ? Math.max(0, value.stepIndex) : 0,
      updatedAt: value.updatedAt || null,
    };
  } catch {
    return null;
  }
}

export function writeOnboardingState({ role, userId, status, stepIndex = 0, version = ROLE_ONBOARDING_VERSION, storage } = {}) {
  const targetStorage = storageFor(storage);
  if (!targetStorage || !['in_progress', 'completed', 'skipped'].includes(status)) return false;
  const state = {
    version: String(version),
    status,
    stepIndex: Math.max(0, Number.isInteger(stepIndex) ? stepIndex : 0),
    updatedAt: new Date().toISOString(),
  };
  try {
    targetStorage.setItem(onboardingStorageKey({ role, userId }), JSON.stringify(state));
    return true;
  } catch {
    return false;
  }
}

export function shouldAutoStartOnboarding({ state, firstLogin = false, version = ROLE_ONBOARDING_VERSION } = {}) {
  if (state?.status === 'in_progress' && state.version === version) return true;
  if (state?.status === 'completed' || state?.status === 'skipped') return false;
  return Boolean(firstLogin);
}

export function onboardingStartIndex(state, stepCount) {
  if (state?.status !== 'in_progress' || state.version !== ROLE_ONBOARDING_VERSION) return 0;
  return Math.min(Math.max(0, state.stepIndex), Math.max(0, stepCount - 1));
}
