import { ref } from 'vue';

// Promise-based replacement for window.confirm(): `await askConfirm({...})` resolves true/false.
// One <ConfirmDialogHost /> per page renders `confirmState`. A new ask cancels one still open.
export const confirmState = ref(null);

export function askConfirm(options) {
  const o = typeof options === 'string' ? { message: options } : (options || {});
  return new Promise((resolve) => {
    confirmState.value?.resolve(false);
    confirmState.value = {
      title: o.title || '確定要繼續嗎？',
      message: o.message || '',
      confirmLabel: o.confirmLabel || '確定',
      cancelLabel: o.cancelLabel || '取消',
      danger: !!o.danger,
      resolve,
    };
  });
}

export function settleConfirm(answer) {
  const s = confirmState.value;
  confirmState.value = null;
  s?.resolve(!!answer);
}
