/**
 * Deterministic mount for page-level Playwright visual evidence.
 * Renders real NotificationsCenter / StudentsList Vue SFCs (not a restyled HTML clone).
 * Session must be written before importing modules that read alltrue_session at init.
 */
const params = new URLSearchParams(window.location.search);
const page = params.get('page') || 'inbox';

localStorage.setItem(
  'alltrue_session',
  JSON.stringify({
    access_token: 'e2e-foundation-token',
    token: 'e2e-foundation-token',
    user: {
      id: 9001,
      role: 'director',
      name: 'E2E Director',
      must_change_password: false,
    },
  }),
);
localStorage.setItem('app_branch', '1');
localStorage.setItem('notifications_sound_enabled', '0');

const role = params.get('role') || 'director';

const [{ createApp, h }, styles, NotificationsCenter, StudentsList, DirectorDashboard, LearningRecordsPage] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/NotificationsCenter.vue'),
  import('../../../src/pages/StudentsList.vue'),
  import('../../../src/pages/DirectorDashboard.vue'),
  import('../../../src/pages/LearningRecordsPage.vue'),
]);

void styles;

createApp({
  name: 'UiFoundationPilotMount',
  setup() {
    if (page === 'students') {
      return () => h(StudentsList.default, { branchId: 1 });
    }
    if (page === 'director') {
      return () => h(DirectorDashboard.default, { branchId: 1 });
    }
    if (page === 'learning') {
      return () => h(LearningRecordsPage.default, { branchId: 1, userRole: role, userId: 9001 });
    }
    return () => h(NotificationsCenter.default, { branchId: 1 });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = page;
document.documentElement.dataset.pilotReady = '1';
