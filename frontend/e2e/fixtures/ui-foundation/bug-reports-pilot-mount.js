const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/BugReportsPage.vue'),
]);

void styles;
const PageComponent = module.default;
const mode = new URLSearchParams(window.location.search).get('mode') || 'normal';

createApp({
  name: 'BugReportsPilotMount',
  setup() {
    return () => h(PageComponent, {
      branchId: mode === 'no-branch' ? null : 1,
      userRole: '',
    });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'bug-reports';
document.documentElement.dataset.pilotReady = '1';
