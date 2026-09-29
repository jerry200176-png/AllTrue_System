const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/BindingManagementPage.vue'),
]);

void styles;
const PageComponent = module.default;
createApp({
  name: 'BindingManagementPilotMount',
  setup() { return () => h(PageComponent, { branchId: 1, userRole: 'director' }); },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'binding-management';
document.documentElement.dataset.pilotReady = '1';

