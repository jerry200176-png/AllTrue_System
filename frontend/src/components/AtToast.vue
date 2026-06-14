<template>
  <transition-group name="at-toast" tag="div" class="at-toast-stack" aria-live="polite">
    <div
      v-for="t in toastState.toasts"
      :key="t.id"
      class="at-toast"
      :class="`at-toast--${t.variant}`"
      role="status"
    >
      <span class="at-toast__bar" aria-hidden="true"></span>
      <span class="at-toast__icon material-symbols-outlined" aria-hidden="true">{{ iconFor(t.variant) }}</span>
      <div class="at-toast__body">
        <div v-if="t.title" class="at-toast__title">{{ t.title }}</div>
        <div v-if="t.description" class="at-toast__desc">{{ t.description }}</div>
      </div>
      <button v-if="t.onUndo" type="button" class="at-toast__action" :disabled="t._undoing" @click="runUndo(t)">
        {{ t._undoing ? '處理中…' : '復原' }}
      </button>
      <button v-else-if="t.action" type="button" class="at-toast__action" @click="runAction(t)">{{ t.action.label }}</button>
      <button type="button" class="at-toast__close" aria-label="關閉" @click="dismiss(t.id)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
  </transition-group>
</template>

<script setup>
import { toastState, useToast } from '../composables/useToast';

const { dismiss } = useToast();

const ICONS = {
  success: 'check_circle',
  error: 'error',
  warning: 'warning',
  info: 'info',
  undo: 'history',
};
function iconFor(v) { return ICONS[v] || 'info'; }

async function runUndo(t) {
  if (t._undoing) return;
  t._undoing = true;
  try { await t.onUndo(); } finally { dismiss(t.id); }
}
function runAction(t) {
  try { t.action.onClick?.(); } finally { dismiss(t.id); }
}
</script>

<style scoped>
.at-toast-stack {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 10060;
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: min(380px, calc(100vw - 32px));
}
.at-toast {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 12px 12px 16px;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 55, 112, 0.12);
}
.at-toast__bar {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  border-radius: 10px 0 0 10px;
  background: var(--ds-ink-mute);
}
.at-toast--success .at-toast__bar { background: var(--ds-success); }
.at-toast--error .at-toast__bar { background: var(--ds-danger); }
.at-toast--warning .at-toast__bar { background: var(--ds-warning); }
.at-toast--info .at-toast__bar { background: var(--ds-primary); }
.at-toast--undo .at-toast__bar { background: var(--ds-ink-mute); }

.at-toast__icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.at-toast--success .at-toast__icon { color: var(--ds-success); }
.at-toast--error .at-toast__icon { color: var(--ds-danger); }
.at-toast--warning .at-toast__icon { color: var(--ds-warning); }
.at-toast--info .at-toast__icon { color: var(--ds-primary); }
.at-toast--undo .at-toast__icon { color: var(--ds-ink-mute); }

.at-toast__body { flex: 1; min-width: 0; }
.at-toast__title { font-size: 14px; font-weight: 600; color: var(--ds-ink); line-height: 1.4; }
.at-toast__desc { font-size: 13px; color: var(--ds-ink-mute); line-height: 1.4; }

.at-toast__action {
  flex-shrink: 0;
  background: none;
  border: none;
  color: var(--ds-primary);
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
  padding: 2px 6px;
}
.at-toast__action:disabled { opacity: 0.6; cursor: not-allowed; }
.at-toast__close {
  flex-shrink: 0;
  background: none;
  border: none;
  color: var(--ds-ink-mute);
  cursor: pointer;
  padding: 0;
  display: grid;
  place-items: center;
}
.at-toast__close .material-symbols-outlined { font-size: 18px; }

.at-toast-enter-active, .at-toast-leave-active { transition: all 0.25s ease; }
.at-toast-enter-from { opacity: 0; transform: translateX(20px); }
.at-toast-leave-to { opacity: 0; transform: translateX(20px); }
</style>
