import { createApp, h, ref } from 'vue';
import '../../../src/styles.css';
import '../../../src/ui.css';
import PaymentSlipModal from '../../../src/components/PaymentSlipModal.vue';

createApp({ setup() {
  const show = ref(false);
  return () => h('main', [
    h('button', { onClick: () => { show.value = true; } }, '預覽繳費通知'),
    h(PaymentSlipModal, { show: show.value, studentClassId: 9, onClose: () => { show.value = false; } }),
  ]);
} }).mount('#app');
