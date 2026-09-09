const params = new URLSearchParams(window.location.search);
const role = params.get('role') || 'director';

localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-subject-units-token',
  token: 'e2e-subject-units-token',
  user: { id: role === 'teacher' ? 701 : 9001, role, name: role === 'teacher' ? '王老師' : 'E2E 主任' },
}));
localStorage.setItem('app_branch', '1');

const [{ createApp, h }, styles, { default: SubjectUnitsPage }] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/SubjectUnitsTimelinePage.vue'),
]);

void styles;

createApp({
  name: 'SubjectUnitsPilotMount',
  setup() {
    return () => h(SubjectUnitsPage, { branchId: 1, userRole: role });
  },
}).mount('#app');

document.documentElement.dataset.pilotReady = '1';
