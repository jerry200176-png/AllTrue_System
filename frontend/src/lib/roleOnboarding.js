export const ROLE_ONBOARDING_VERSION = '2026-09-05-v1.2';

const ROLE_ONBOARDING_MISSIONS = {
  director: [
    {
      id: 'director-dashboard',
      page: 'director',
      target: '[data-guide="director-summary"]',
      icon: 'dashboard',
      title: '先從主任儀表板開始',
      description: '登入後先看今日數據與提醒，再依優先順序處理分校工作。這裡只提供導覽，不會替你修改資料。',
      objective: '選定今天最需要你先處理的一項工作。',
      completionPrompt: '請先在「今天要處理的事」選定一項，再按「我完成了，下一步」。',
      placement: 'bottom',
    },
    {
      id: 'director-notifications',
      page: 'notifications',
      target: '[data-guide="notifications-list"]',
      icon: 'notifications',
      title: '查看需要處理的通知',
      description: '通知中心集中顯示繳費、評量與出缺勤等待辦；先確認一則通知，再進入下一個任務。',
      objective: '確認一則通知的內容與下一個處理入口。',
      completionPrompt: '請先打開一則需要跟進的通知（或確認目前沒有待辦），再繼續。',
      placement: 'bottom',
    },
    {
      id: 'director-calendar',
      page: 'calendar',
      target: '[data-guide="calendar-toolbar"]',
      icon: 'calendar_month',
      title: '掌握本週課表',
      description: '用日期與篩選工具快速定位老師、教室或科目，再從課表查看需要處理的課程。',
      objective: '用日期或篩選找到一堂要追蹤的課。',
      completionPrompt: '請先用課表的日期／篩選工具定位一堂課，再繼續。',
      placement: 'bottom',
    },
    {
      id: 'director-learning',
      page: 'learning',
      target: '[data-guide="learning-table"]',
      icon: 'task_alt',
      title: '審核學習評量',
      description: '最後到學習評量查看老師提交的紀錄與待審項目；需要寫入資料時，請依畫面提示確認後再操作。',
      objective: '完成一次評量審核判斷，讓主任巡檢有明確收尾。',
      completionPrompt: '請先查看一筆評量並完成核准／退回判斷；沒有待審時確認清單即可。',
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
      objective: '從今日工作台選出第一堂要處理的課。',
      completionPrompt: '請先打開今日待辦中的第一個課務入口，再按「我完成了，下一步」。',
      placement: 'bottom',
    },
    {
      id: 'teacher-attendance',
      page: 'attendance',
      target: '[data-guide="attendance-pending-list"]',
      icon: 'how_to_reg',
      title: '完成出缺勤管理',
      description: '到出缺勤管理查看今天的課堂，依課程逐堂完成點名；導覽不會替你送出出缺勤資料。',
      objective: '完成一堂課的點名，留下真實出缺勤紀錄。',
      completionPrompt: '請先完成一堂課的點名並確認送出，再繼續。',
      placement: 'bottom',
    },
    {
      id: 'teacher-learning',
      page: 'learning',
      target: '[data-guide="learning-table"]',
      icon: 'school',
      title: '接著完成學習評量',
      description: '點名後可到學習評量查看學生紀錄、補填與待回覆項目，維持每日教學紀錄完整。',
      objective: '完成一筆學習評量或確認目前沒有待填紀錄。',
      completionPrompt: '請先填寫並送出一筆評量，或確認今天沒有待填項目，再繼續。',
      placement: 'bottom',
    },
    {
      id: 'teacher-calendar',
      page: 'calendar',
      target: '[data-guide="calendar-header"]',
      icon: 'calendar_today',
      title: '用我的課表安排下一步',
      description: '最後回到課表確認接下來的課堂與學生清單，形成「待辦 → 點名 → 評量 → 課表」的日常流程。',
      objective: '確認下一堂課的時間與學生，完成每日教學收尾。',
      completionPrompt: '請先確認下一堂課的時間與學生清單，再完成這條任務。',
      placement: 'bottom',
    },
  ],
};

const ROLE_ONBOARDING_MISSION_META = {
  director: {
    id: 'director-daily-control-v1',
    roleTrack: 'staff',
    eyebrow: '主任任務 · 今日營運巡檢',
    title: '完成今天的主任巡檢',
    description: '沿著真實的主任工作順序走一遍：先判斷優先級，再追蹤通知、課表與評量。',
    rankNote: '導覽只記錄學習進度；軍階 XP 仍依既有可審計的營運事件累計。',
  },
  teacher: {
    id: 'teacher-daily-closeout-v1',
    roleTrack: 'teacher',
    eyebrow: '老師任務 · 今日教學收尾',
    title: '完成一輪今日教學',
    description: '從今日待辦開始，實際走過「點名 → 評量 → 下一堂課」的日常教學節奏。',
    rankNote: '導覽只記錄學習進度；軍階 XP 仍依既有點名與評量事件累計。',
  },
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
  return (ROLE_ONBOARDING_MISSIONS[key] || []).map((step) => ({ ...step }));
}

export function getRoleOnboardingMission(role) {
  const key = role === 'teacher' ? 'teacher' : 'director';
  const meta = ROLE_ONBOARDING_MISSION_META[key];
  return {
    ...meta,
    steps: getRoleOnboardingSteps(key),
  };
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
    if (!['in_progress', 'completed', 'skipped', 'deferred'].includes(value.status)) return null;
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
  if (!targetStorage || !['in_progress', 'completed', 'skipped', 'deferred'].includes(status)) return false;
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
  if (state?.status === 'completed' || state?.status === 'skipped' || state?.status === 'deferred') return false;
  return Boolean(firstLogin);
}

export function onboardingStartIndex(state, stepCount) {
  if (state?.status !== 'in_progress' || state.version !== ROLE_ONBOARDING_VERSION) return 0;
  return Math.min(Math.max(0, state.stepIndex), Math.max(0, stepCount - 1));
}
