/**
 * Deterministic mount for page-level Playwright visual evidence.
 * Renders real NotificationsCenter Vue SFC (inbox pilot).
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

const [{ createApp, h }, styles, NotificationsCenter] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/NotificationsCenter.vue'),
]);

void styles;

if (page !== 'inbox') {
  throw new Error(`Inbox pilot mount only supports page=inbox (got ${page})`);
}

createApp({
  name: 'UiFoundationInboxPilotMount',
  setup() {
    return () => h(NotificationsCenter.default, { branchId: 1 });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = page;
document.documentElement.dataset.pilotReady = '1';
