const params = new URLSearchParams(window.location.search);
const role = params.get('role') || 'director';

localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-assessment-token',
  token: 'e2e-assessment-token',
  user: { id: 9001, role, name: 'E2E Assessment User', must_change_password: false },
}));

const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/AssessmentPage.vue'),
]);

void styles;
const PageComponent = module.default;
createApp({
  name: 'AssessmentPilotMount',
  setup() { return () => h(PageComponent, { branchId: 1, userRole: role }); },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'assessment';
document.documentElement.dataset.pilotReady = '1';
