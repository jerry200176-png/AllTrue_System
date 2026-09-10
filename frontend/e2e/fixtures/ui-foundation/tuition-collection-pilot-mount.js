localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-tuition-collection-token',
  token: 'e2e-tuition-collection-token',
  user: { id: 9001, role: 'director', name: 'E2E 主任' },
}));
localStorage.setItem('app_branch', '1');
const params = new URLSearchParams(window.location.search);
const initialTab = params.get('tab') || '';

const [{ createApp, h }, styles, { default: TuitionCollectionPage }] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/TuitionCollectionPage.vue'),
]);

void styles;

createApp({
  name: 'TuitionCollectionPilotMount',
  setup() {
    return () => h(TuitionCollectionPage, { branchId: 1, initialTab });
  },
}).mount('#app');

document.documentElement.dataset.pilotReady = '1';
