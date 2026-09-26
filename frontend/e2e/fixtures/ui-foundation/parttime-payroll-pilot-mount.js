const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/ParttimePayrollPage.vue'),
]);

void styles;
createApp({
  name: 'ParttimePayrollPilotMount',
  setup() {
    return () => h(module.default, { branchId: 1, userRole: 'director' });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'parttime-payroll';
document.documentElement.dataset.pilotReady = '1';
