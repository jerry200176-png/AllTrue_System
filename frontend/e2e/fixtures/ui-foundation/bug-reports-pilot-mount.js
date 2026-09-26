const [{ createApp, h }, styles, module, launcherModule] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/BugReportsPage.vue'),
  import('../../../src/components/BugReportLauncher.vue'),
]);

void styles;
const PageComponent = module.default;
const BugReportLauncher = launcherModule.default;
const mode = new URLSearchParams(window.location.search).get('mode') || 'normal';

createApp({
  name: 'BugReportsPilotMount',
  setup() {
    if (mode === 'launcher') {
      return () => [
        h(BugReportLauncher, { branchId: 1, currentPageKey: 'attendance' }),
        h('nav', { class: 'mobile-bottom-nav', 'aria-label': '手機底部導覽' }, [
          h('button', { type: 'button' }, '底部導覽'),
        ]),
      ];
    }
    return () => h(PageComponent, { branchId: mode === 'no-branch' ? null : 1, userRole: '' });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'bug-reports';
document.documentElement.dataset.pilotReady = '1';
