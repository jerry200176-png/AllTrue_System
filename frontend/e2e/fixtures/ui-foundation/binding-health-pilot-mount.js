const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/BindingHealthDashboard.vue'),
]);

void styles;
const PageComponent = module.default;
createApp({
  name: 'BindingHealthPilotMount',
  setup() { return () => h(PageComponent, { branchId: null, userRole: 'director' }); },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'binding-health';
document.documentElement.dataset.pilotReady = '1';
