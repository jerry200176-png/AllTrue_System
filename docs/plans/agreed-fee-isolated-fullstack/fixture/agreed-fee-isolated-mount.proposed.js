/**
 * DRAFT — proposed path:
 *   frontend/e2e/fixtures/agreed-fee-isolated/agreed-fee-isolated-mount.js
 *
 * Mounts AgreedFeeCorrectionModal only. Requires fee UI on the checked-out tip
 * (e.g. #4250 a579). Course IDs come from AGREED_FEE_ISOLATED_* (seed env).
 * Vite proxies /api → Laravel :8000 so modal relative fetch hits real API.
 */
import { createApp, h, ref } from 'vue';
import AgreedFeeCorrectionModal from '../../../../../frontend/src/components/AgreedFeeCorrectionModal.vue';

// After copy into frontend/e2e/fixtures/agreed-fee-isolated/, import becomes:
//   import AgreedFeeCorrectionModal from '../../../../src/components/AgreedFeeCorrectionModal.vue';

const params = new URLSearchParams(location.search);
const courseId = Number(params.get('courseId') || window.__AGREED_FEE_COURSE_ID__ || 0);
const invoiceIdRaw = params.get('invoiceId') || window.__AGREED_FEE_INVOICE_ID__ || '';
const invoiceId = invoiceIdRaw === '' || invoiceIdRaw === 'null' ? null : Number(invoiceIdRaw);

const closed = ref(false);

createApp({
  setup() {
    return () =>
      h('div', { 'data-testid': 'agreed-fee-isolated-root' }, [
        closed.value
          ? h('div', { 'data-testid': 'agreed-fee-success' }, '更正已送出')
          : h(AgreedFeeCorrectionModal, {
              courseId,
              invoiceId,
              onClose: () => {
                closed.value = true;
              },
              onChanged: () => {
                closed.value = true;
              },
            }),
      ]);
  },
}).mount('#app');
