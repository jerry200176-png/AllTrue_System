<template>
  <transition-group name="twu" tag="div" class="twu-stack">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="twu"
      :class="[
        `twu--${t.variant}`,
        t.voided && 'twu--voided',
        t.expired && 'twu--expired',
      ]"
      role="status"
      @mouseenter="pause(t.id)"
      @mouseleave="resume(t.id)"
    >
      <span class="twu__icon" aria-hidden="true">{{ iconFor(t) }}</span>
      <div class="twu__body">
        <div class="twu__title">{{ t.title }}</div>
        <div v-if="t.description" class="twu__desc">{{ t.description }}</div>
      </div>
      <button
        v-if="t.onUndo && !t.voided && !t.expired"
        type="button"
        class="twu__undo"
        :disabled="t.undoing"
        @click="doUndo(t)"
      >
        {{ t.undoing ? '處理中…' : '復原' }}
      </button>
      <button type="button" class="twu__close" aria-label="關閉" @click="dismiss(t.id)">✕</button>
      <div v-if="t.onUndo && !t.voided" class="twu__bar">
        <div
          class="twu__bar-inner"
          :style="{ width: t.progress + '%', transition: t.paused ? 'none' : `width ${t.remaining}ms linear` }"
        />
      </div>
    </div>
  </transition-group>
</template>

<script setup>
// ToastWithUndo — 代課流程 UX 優化（PRD 9c058f19, FR-005 / FR-011）
// 右下角堆疊式 toast；支援倒數條、hover 暫停、Undo 失敗轉錯誤。
import { ref, onBeforeUnmount } from 'vue';

const toasts = ref([]);
let seq = 0;

function ensureTimer(t) {
  if (t.timerId) clearTimeout(t.timerId);
  if (!t.onUndo || t.voided || t.expired) return;
  const startedAt = Date.now();
  t.startedAt = startedAt;
  t.timerId = setTimeout(() => {
    t.expired = true;
    t.timerId = null;
  }, t.remaining);
}

function pause(id) {
  const t = toasts.value.find((x) => x.id === id);
  if (!t || !t.timerId) return;
  const elapsed = Date.now() - t.startedAt;
  t.remaining = Math.max(0, t.remaining - elapsed);
  t.progress = 100 * (t.remaining / t.totalMs);
  clearTimeout(t.timerId);
  t.timerId = null;
  t.paused = true;
}

function resume(id) {
  const t = toasts.value.find((x) => x.id === id);
  if (!t || !t.onUndo || t.voided || t.expired) return;
  t.paused = false;
  ensureTimer(t);
}

async function doUndo(t) {
  if (!t.onUndo || t.voided || t.expired) return;
  t.undoing = true;
  clearTimeout(t.timerId);
  t.timerId = null;
  try {
    await t.onUndo();
    t.voided = true;
    t.title = '已復原';
    t.description = t.undoDescription || '代課已撤銷';
    t.variant = 'info';
    setTimeout(() => dismiss(t.id), 2000);
  } catch (e) {
    t.voided = false;
    t.expired = true;
    t.title = '復原失敗';
    t.description = (e && e.message) || '請稍後再試';
    t.variant = 'error';
    t.undoing = false;
    setTimeout(() => dismiss(t.id), 4000);
  }
}

function dismiss(id) {
  const idx = toasts.value.findIndex((x) => x.id === id);
  if (idx < 0) return;
  const t = toasts.value[idx];
  if (t.timerId) clearTimeout(t.timerId);
  toasts.value.splice(idx, 1);
}

function show(opts) {
  const t = {
    id: ++seq,
    title: opts.title || '',
    description: opts.description || '',
    variant: opts.variant || 'success',
    onUndo: opts.onUndo || null,
    undoDescription: opts.undoDescription || '',
    totalMs: opts.durationMs || 5000,
    remaining: opts.durationMs || 5000,
    progress: 100,
    paused: false,
    undoing: false,
    voided: false,
    expired: false,
    timerId: null,
    startedAt: 0,
  };
  toasts.value.push(t);
  ensureTimer(t);
  if (!t.onUndo) {
    setTimeout(() => dismiss(t.id), t.totalMs);
  }
  return t.id;
}

function iconFor(t) {
  if (t.voided) return '↺';
  if (t.variant === 'error') return '⚠';
  if (t.variant === 'info') return 'ℹ';
  return '✓';
}

onBeforeUnmount(() => {
  toasts.value.forEach((t) => t.timerId && clearTimeout(t.timerId));
  toasts.value = [];
});

defineExpose({ show, dismiss });
</script>

<style scoped>
.twu-stack {
  position: fixed;
  right: 24px;
  bottom: 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  z-index: 9999;
  max-width: calc(100vw - 48px);
}
.twu {
  position: relative;
  width: 360px;
  max-width: 100%;
  background: var(--ds-canvas);
  border: 1px solid rgba(16, 24, 40, 0.08);
  border-radius: 12px;
  box-shadow: 0 12px 32px rgba(16, 24, 40, 0.14);
  padding: 14px 14px 18px 14px;
  display: grid;
  grid-template-columns: 24px 1fr auto auto;
  gap: 10px;
  align-items: start;
  overflow: hidden;
}
.twu--success {
  border-left: 4px solid var(--ds-success);
}
.twu--error {
  border-left: 4px solid var(--ds-danger);
}
.twu--info {
  border-left: 4px solid var(--ds-ink-mute);
}
.twu--voided {
  opacity: 0.85;
}
.twu__icon {
  font-size: 18px;
  line-height: 20px;
}
.twu--success .twu__icon { color: var(--ds-success); }
.twu--error .twu__icon { color: var(--ds-danger); }
.twu--info .twu__icon { color: var(--ds-ink-mute); }
.twu__title {
  font-weight: 600;
  font-size: 14px;
  color: var(--ds-ink);
}
.twu__desc {
  font-size: 13px;
  color: var(--ds-ink);
  margin-top: 2px;
  word-break: break-word;
}
.twu__undo {
  background: transparent;
  color: var(--ds-primary-deep, var(--ds-primary));
  border: 1px solid rgba(245, 124, 0, 0.4);
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.twu__undo:hover { background: rgba(245, 124, 0, 0.08); }
.twu__undo:disabled { opacity: 0.6; cursor: not-allowed; }
.twu__close {
  background: transparent;
  border: 0;
  color: var(--ds-hairline);
  font-size: 14px;
  cursor: pointer;
  padding: 0 2px;
}
.twu__close:hover { color: var(--ds-ink); }
.twu__bar {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 3px;
  width: 100%;
  background: rgba(245, 124, 0, 0.12);
}
.twu__bar-inner {
  height: 100%;
  width: 100%;
  background: var(--ds-brand-gradient, linear-gradient(90deg, var(--ds-primary-soft), var(--ds-primary)));
}
.twu-enter-active,
.twu-leave-active {
  transition: transform 220ms ease, opacity 220ms ease;
}
.twu-enter-from {
  transform: translateY(12px);
  opacity: 0;
}
.twu-leave-to {
  transform: translateY(8px);
  opacity: 0;
}
</style>
