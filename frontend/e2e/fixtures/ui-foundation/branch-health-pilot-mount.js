const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/BranchHealthBoard.vue'),
]);

void styles;
const PageComponent = module.default;
createApp({
  name: 'BranchHealthPilotMount',
  setup() { return () => h(PageComponent, { token: 'e2e-branch-health-token' }); },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'branch-health';
document.documentElement.dataset.pilotReady = '1';
