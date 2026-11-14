localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'e2e-subject-credit', user: { id: 9001, role: 'director' } }));
localStorage.setItem('alltrue_text_size', new URLSearchParams(location.search).get('size') || 'standard');
const [{ createApp, h, ref }, , , { initTextSize }, { default: Modal }] = await Promise.all([
  import('vue'), import('../../../src/styles.css'), import('../../../src/ui.css'),
  import('../../../src/composables/useTextSize.js'), import('../../../src/components/course-management/SubjectCreditTransferModal.vue'),
]);
initTextSize();
createApp({ setup() {
  const show = ref(false);
  return () => h('main', [h('button', { onClick: () => { show.value = true; } }, '跨科轉用與紀錄'),
    h(Modal, { show: show.value, course: { id: 10, student_id: 1, student_name: '隔離測試學生', subject: 'math', subject_name: '數學' }, onClose: () => { show.value = false; } })]);
} }).mount('#app');
