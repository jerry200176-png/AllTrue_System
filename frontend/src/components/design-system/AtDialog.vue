<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { lockScroll, unlockScroll } from '../../lib/useScrollLock';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  ariaLabel: { type: String, default: '' },
  titleId: { type: String, default: 'at-dialog-title' },
  panelClass: { type: [String, Array, Object], default: '' },
  size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
  closeOnBackdrop: { type: Boolean, default: true },
  closeLabel: { type: String, default: '關閉視窗' },
});

const emit = defineEmits(['close']);
const panelRef = ref(null);
let scrollLocked = false;

function syncScrollLock(isOpen) {
  if (isOpen && !scrollLocked) {
    lockScroll();
    scrollLocked = true;
    nextTick(() => panelRef.value?.focus());
    return;
  }
  if (!isOpen && scrollLocked) {
    unlockScroll();
    scrollLocked = false;
  }
}

function close() {
  emit('close');
}

function onBackdropClick() {
  if (props.closeOnBackdrop) close();
}

watch(() => props.open, syncScrollLock, { immediate: true });
onBeforeUnmount(() => syncScrollLock(false));
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="at-dialog-overlay" @click.self="onBackdropClick">
      <section
        ref="panelRef"
        class="at-dialog__panel"
        :class="[`at-dialog__panel--${size}`, panelClass]"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="title ? titleId : undefined"
        :aria-label="title ? undefined : (ariaLabel || '對話框')"
        tabindex="-1"
        @keydown.esc.prevent="close"
      >
        <header v-if="title || $slots.header" class="at-dialog__header">
          <div class="at-dialog__heading">
            <h2 v-if="title" :id="titleId" class="at-dialog__title">{{ title }}</h2>
            <slot name="header" />
          </div>
          <button type="button" class="at-dialog__close" :aria-label="closeLabel" @click="close">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </header>
        <div class="at-dialog__body">
          <slot />
        </div>
        <footer v-if="$slots.actions" class="at-dialog__actions">
          <slot name="actions" />
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.at-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--ds-z-modal, 1300);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--ds-space-5, 24px);
  background: rgba(15, 23, 42, 0.46);
  backdrop-filter: blur(3px);
  overscroll-behavior: contain;
}

.at-dialog__panel {
  display: flex;
  flex-direction: column;
  width: min(100%, 520px);
  max-height: min(760px, calc(100dvh - 48px));
  overflow: hidden;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg, 8px);
  background: var(--ds-canvas);
  color: var(--ds-ink);
  box-shadow: var(--ds-shadow-2);
  animation: at-dialog-enter var(--ds-motion-base, 180ms) var(--ds-ease-standard, ease-out);
}

.at-dialog__panel--sm { width: min(100%, 400px); }
.at-dialog__panel--lg { width: min(100%, 760px); }
.at-dialog__panel--xl { width: min(100%, 1040px); }

.at-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-3);
  padding: var(--ds-space-5) var(--ds-space-5) var(--ds-space-3);
  border-bottom: 1px solid var(--ds-hairline);
}

.at-dialog__heading { min-width: 0; }
.at-dialog__title {
  margin: 0;
  color: var(--ds-text-primary);
  font-size: var(--ds-font-size-xl);
  font-weight: var(--ds-font-weight-bold);
  line-height: var(--ds-line-tight);
}

.at-dialog__close {
  display: inline-grid;
  place-items: center;
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border: 1px solid transparent;
  border-radius: var(--ds-radius-md);
  background: transparent;
  color: var(--ds-text-tertiary);
  cursor: pointer;
}

.at-dialog__close:hover { background: var(--ds-surface-0); color: var(--ds-text-primary); }
.at-dialog__close:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--ds-focus-ring); }
.at-dialog__close .material-symbols-outlined { font-size: 19px; }

.at-dialog__body {
  min-height: 0;
  overflow: auto;
  padding: var(--ds-space-5);
}

.at-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--ds-space-2);
  padding: var(--ds-space-3) var(--ds-space-5) var(--ds-space-5);
  border-top: 1px solid var(--ds-hairline);
}

@keyframes at-dialog-enter {
  from { opacity: 0; transform: translateY(8px) scale(0.99); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 640px) {
  .at-dialog-overlay { align-items: flex-end; padding: 0; }
  .at-dialog__panel,
  .at-dialog__panel--sm,
  .at-dialog__panel--md,
  .at-dialog__panel--lg,
  .at-dialog__panel--xl {
    width: 100%;
    max-height: 92dvh;
    border-radius: 16px 16px 0 0;
  }
  .at-dialog__body { padding: var(--ds-space-4); }
  .at-dialog__header { padding: var(--ds-space-4) var(--ds-space-4) var(--ds-space-3); }
  .at-dialog__actions { padding: var(--ds-space-3) var(--ds-space-4) calc(var(--ds-space-4) + env(safe-area-inset-bottom, 0px)); }
}

@media (prefers-reduced-motion: reduce) {
  .at-dialog__panel { animation: none; }
}
</style>
