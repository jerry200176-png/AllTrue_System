const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/DuplicateSessionReviewPage.vue'),
]);

void styles;
const PageComponent = module.default;

createApp({
  name: 'DuplicateReviewPilotMount',
  setup() {
    return () => h(PageComponent, { branchId: 1, userRole: 'director' });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'duplicate-review';
document.documentElement.dataset.pilotReady = '1';
