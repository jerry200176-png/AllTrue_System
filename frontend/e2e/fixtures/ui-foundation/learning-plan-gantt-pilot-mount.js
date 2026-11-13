const params = new URLSearchParams(window.location.search);

localStorage.setItem('alltrue_session', JSON.stringify({
  access_token: 'e2e-gantt-token',
  token: 'e2e-gantt-token',
  user: { id: 9001, role: 'director', name: 'E2E 主任' },
}));
localStorage.setItem('app_branch', '1');
localStorage.setItem('alltrue_text_size', params.get('size') === 'large' ? 'large' : 'standard');

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
  name: 'LearningPlanGanttPilotMount',
  setup() {
    return () => h(Profile, { show: true, studentId: 1, studentName: '林小明', branchId: 1, studentGrade: 'J1' });
  },
}).mount('#app');

document.documentElement.dataset.pilotReady = '1';
