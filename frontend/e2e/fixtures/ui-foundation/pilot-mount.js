/**
 * Deterministic mount for page-level Playwright visual evidence.
 * Renders real NotificationsCenter / StudentsList Vue SFCs (not a restyled HTML clone).
 * Session must be written before importing modules that read alltrue_session at init.
 */
const params = new URLSearchParams(window.location.search);
const page = params.get('page') || 'inbox';
const mode = params.get('mode') || 'normal';
const role = params.get('role') || (page === 'teacher' ? 'teacher' : 'director');

localStorage.setItem(
  'alltrue_session',
  JSON.stringify({
    access_token: 'e2e-foundation-token',
    token: 'e2e-foundation-token',
    user: {
      id: 9001,
      role,
      name: role === 'teacher' ? 'E2E Teacher' : 'E2E Director',
      must_change_password: false,
    },
  }),
);
localStorage.setItem('app_branch', '1');
localStorage.setItem('notifications_sound_enabled', '0');

const [{ createApp, h }, styles] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
]);

const pageModules = {
  inbox: () => import('../../../src/pages/NotificationsCenter.vue'),
  students: () => import('../../../src/pages/StudentsList.vue'),
  director: () => import('../../../src/pages/DirectorDashboard.vue'),
  tuition: () => import('../../../src/pages/TuitionCollectionPage.vue'),
  learning: () => import('../../../src/pages/LearningRecordsPage.vue'),
  course: () => import('../../../src/pages/CourseManagement.vue'),
  calendar: () => import('../../../src/pages/SmartCalendar.vue'),
  discrepancy: () => import('../../../src/pages/ScheduleDiscrepancyPage.vue'),
  teacher: () => import('../../../src/pages/TeacherHomePage.vue'),
  teachers: () => import('../../../src/pages/TeachersList.vue'),
  attendance: () => import('../../../src/pages/AttendancePage.vue'),
};
const loadPage = pageModules[page] || pageModules.inbox;
const PageComponent = (await loadPage()).default;

void styles;

createApp({
  name: 'UiFoundationPilotMount',
  setup() {
    if (page === 'students') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'director') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'tuition') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'learning') {
      return () => h(PageComponent, { branchId: 1, userRole: role, userId: 9001 });
    }
    if (page === 'course') {
      return () => h(PageComponent, {
        branchId: 1,
        onNavigate: (payload) => {
          window.__pilotLastNavigation = payload;
        },
      });
    }
    if (page === 'calendar') {
      return () => h(PageComponent, { branchId: 1, userRole: role, userId: 9001 });
    }
    if (page === 'discrepancy') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'teacher') {
      return () => h(PageComponent, {
        branchId: 1,
        userId: 9001,
        userRole: 'teacher',
        teacherBranchIds: [1],
        unreadFeedbackCount: mode === 'empty' ? 0 : 1,
        onNavigate: (payload) => {
          window.__pilotLastNavigation = payload;
        },
      });
    }
    if (page === 'teachers') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'attendance') {
      return () => h(PageComponent, {
        branchId: 1,
        userId: 9001,
        userRole: role,
      });
    }
    return () => h(PageComponent, { branchId: 1 });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = page;
document.documentElement.dataset.pilotReady = '1';
