import { ref } from 'vue';

// Promise API replacing window.confirm / prompt(): `if (!(await confirm({ title, description, confirmLabel, destructive }))) return;`
// Mount ONE <ConfirmHost /> per app/page. A new ask settles a still-open one with false.
export const confirmState = ref(null);

export function confirm(options = {}) {
  return new Promise((resolve) => {
    confirmState.value?.resolve(false);
    confirmState.value = { title: '確定要繼續嗎？', description: '', confirmLabel: '確認', destructive: false, ...options, resolve };
  });
}

export function settle(answer) {
  const s = confirmState.value;
  confirmState.value = null;
  s?.resolve(Boolean(answer));
}

export const useConfirm = () => ({ confirm });
