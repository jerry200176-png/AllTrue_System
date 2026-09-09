const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/ClassroomManagement.vue'),
]);

void styles;
localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-classroom-token',
  token: 'e2e-classroom-token',
  user: { id: 9003, role: 'director', name: 'E2E Classroom Director', must_change_password: false },
}));
localStorage.setItem('app_branch', '1');

const PageComponent = module.default;
createApp({
  name: 'ClassroomPilotMount',
  setup() { return () => h(PageComponent, { branchId: 1 }); },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'classroom';
document.documentElement.dataset.pilotReady = '1';
