import { reactive } from 'vue';

/**
 * #708 統一 Toast — 單例 store（參考 GitHub Primer Toast）。
 *
 * 任何頁面：`import { useToast } from '@/composables/useToast'; const toast = useToast();`
 * 然後 `toast.success('已儲存')` / `toast.error('繳費失敗')` / `toast.undo({ title, onUndo })`。
 * 由 App.vue 掛一次 <AtToast/> 渲染整個 stack，不再各頁自刻 toast。
 */

let _id = 0;
const state = reactive({ toasts: [] });

const DURATION = { success: 4000, info: 4000, warning: 5000, error: 6000, undo: 8000 };

function dismiss(id) {
  const i = state.toasts.findIndex((t) => t.id === id);
  if (i >= 0) {
    const t = state.toasts[i];
    if (t._timer) clearTimeout(t._timer);
    state.toasts.splice(i, 1);
  }
}

function push(variant, message, opts = {}) {
  const id = ++_id;
  const t = {
    id,
    variant,
    title: opts.title || message || '',
    description: opts.title ? message : opts.description || '',
    action: opts.action || null,        // { label, onClick }
    onUndo: opts.onUndo || null,
    _timer: null,
  };
  state.toasts.push(t);
  const ms = opts.duration ?? DURATION[variant] ?? 4000;
  if (ms > 0) {
    t._timer = setTimeout(() => dismiss(id), ms);
  }
  return id;
}

export function useToast() {
  return {
    toasts: state.toasts,
    success: (msg, opts) => push('success', msg, opts),
    error: (msg, opts) => push('error', msg, opts),
    warning: (msg, opts) => push('warning', msg, opts),
    info: (msg, opts) => push('info', msg, opts),
    /** undo toast：opts = { title, description?, onUndo } */
    undo: (opts) => push('undo', opts.description || '', { ...opts }),
    dismiss,
  };
}

// 供 AtToast 直接讀取 store
export const toastState = state;
