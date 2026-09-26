const params = new URLSearchParams(window.location.search);
const role = params.get('role') || 'director';

const [{ createApp, h }, styles, { default: ReleaseNotesPage }] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/ReleaseNotesPage.vue'),
]);

void styles;

createApp({
  name: 'ReleaseNotesPilotMount',
  setup() {
    return () => h(ReleaseNotesPage, { userRole: role });
  },
}).mount('#app');

document.documentElement.dataset.pilotReady = '1';
