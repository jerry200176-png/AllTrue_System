/**
 * Install path: frontend/e2e/fixtures/agreed-fee-isolated/agreed-fee-isolated-mount.js
 *
 * Mounts real AgreedFeeCorrectionModal. Modal fetch uses relative `/api/...`
 * so Vite must same-origin proxy `/api` → Laravel :8000 (window.__AGREED_FEE_API__ is unused).
 * Requires fee UI on the checked-out tip (e.g. #4250 a579).
 */
const params = new URLSearchParams(window.location.search);
const courseId = Number(params.get('courseId') || 0);
const invoiceRaw = params.get('invoiceId');
const invoiceId = invoiceRaw === null || invoiceRaw === '' || invoiceRaw === 'null' ? null : Number(invoiceRaw);

// Session is injected by Playwright addInitScript before navigation (no token in URL).
if (!localStorage.getItem('alltrue_session')) {
  throw new Error('agreed-fee-isolated: missing alltrue_session (inject via test init script)');
}

const [{ createApp, h, ref }, styles, modalMod] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css').catch(() => null),
  import('../../../src/components/AgreedFeeCorrectionModal.vue'),
]);
void styles;

const AgreedFeeCorrectionModal = modalMod.default;
const closed = ref(false);

createApp({
  name: 'AgreedFeeIsolatedMount',
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
