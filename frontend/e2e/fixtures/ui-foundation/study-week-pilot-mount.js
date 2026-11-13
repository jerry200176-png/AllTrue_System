const params = new URLSearchParams(window.location.search);

localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-study-week-token',
  token: 'e2e-study-week-token',
  user: { id: 9001, role: 'director', name: 'E2E 主任' },
}));
localStorage.setItem('app_branch', '1');
if (['standard', 'medium', 'large'].includes(params.get('size'))) localStorage.setItem('alltrue_text_size', params.get('size')); // otherwise keep what the test set

const [{ createApp, h }, styles, ui, { initTextSize }, { default: Profile }] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/ui.css'),
  import('../../../src/composables/useTextSize.js'),
  import('../../../src/components/students/StudentLearningTimeline.vue'),
]);

void styles;
void ui;
initTextSize();

createApp({
  name: 'StudyWeekPilotMount',
  setup() {
    return () => h(Profile, { show: true, studentId: 1, studentName: '歐陽子安', branchId: 1, studentGrade: 'J1' });
  },
}).mount('#app');

document.documentElement.dataset.pilotReady = '1';
