/**
 * Install path: frontend/e2e/fixtures/agreed-fee-isolated/agreed-fee-isolated-mount.js
 *
 * Success UI only on `changed` (apply completed). `close`/cancel must NOT mark success.
 */
const params = new URLSearchParams(window.location.search);
const courseId = Number(params.get('courseId') || 0);
const invoiceRaw = params.get('invoiceId');
const invoiceId = invoiceRaw === null || invoiceRaw === '' || invoiceRaw === 'null' ? null : Number(invoiceRaw);

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
const applied = ref(false);

createApp({
  name: 'AgreedFeeIsolatedMount',
  setup() {
    return () =>
      h('div', { 'data-testid': 'agreed-fee-isolated-root' }, [
        applied.value
          ? h('div', { 'data-testid': 'agreed-fee-success' }, '更正已送出')
          : h(AgreedFeeCorrectionModal, {
              courseId,
              invoiceId,
              onClose: () => {
                /* cancel/backdrop — not success */
              },
              onChanged: () => {
                applied.value = true;
              },
            }),
      ]);
  },
}).mount('#app');
