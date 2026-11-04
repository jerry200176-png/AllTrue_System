<script setup>
import { nextTick, onBeforeUnmount, onDeactivated, ref, watch } from 'vue';
import { lockScroll, unlockScroll } from '../../lib/useScrollLock';
import { trapTab } from '../../lib/focusTrap';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  ariaLabel: { type: String, default: '' },
  titleId: { type: String, default: 'at-dialog-title' },
  panelClass: { type: [String, Array, Object], default: '' },
  size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
  // 'right' = full-height side sheet (Stripe-style record panel); same focus trap, Esc, scroll lock and teleport.
  placement: { type: String, default: 'center', validator: (value) => ['center', 'right'].includes(value) },
  closeOnBackdrop: { type: Boolean, default: true },
  closeLabel: { type: String, default: '關閉視窗' },
  initialFocus: { type: String, default: '' },
});

const emit = defineEmits(['close']);
const panelRef = ref(null);
let scrollLocked = false;
let returnFocusEl = null;

function focusPanel() {
  nextTick(() => {
    // initialFocus: a selector inside the panel (e.g. the safe 取消 button of a destructive confirm).
    const target = props.initialFocus ? panelRef.value?.querySelector(props.initialFocus) : null;
    (target || panelRef.value)?.focus();
  });
}

function onTab(event) {
  trapTab(event, panelRef.value);
}

function restoreFocus() {
  const target = returnFocusEl;
  returnFocusEl = null;
  if (target && target.isConnected && typeof target.focus === 'function') target.focus();
}

function syncScrollLock(isOpen) {
  if (isOpen && !scrollLocked) {
    returnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    lockScroll();
    scrollLocked = true;
    focusPanel();
    return;
  }
  if (!isOpen && scrollLocked) {
    unlockScroll();
    scrollLocked = false;
    restoreFocus();
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
// A kept-alive page was hidden while this (teleported) dialog was open: release the scroll lock and close it
// through the normal close path, so the page comes back without a stale dialog.
onDeactivated(() => {
  if (!props.open) return;
  syncScrollLock(false);
  close();
});
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="at-dialog-overlay" :class="{ 'at-dialog-overlay--right': placement === 'right' }" @click.self="onBackdropClick">
      <section
        ref="panelRef"
        class="at-dialog__panel"
        :class="[`at-dialog__panel--${size}`, { 'at-dialog__panel--sheet': placement === 'right' }, panelClass]"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="title ? titleId : undefined"
        :aria-label="title ? undefined : (ariaLabel || '對話框')"
        tabindex="-1"
        @keydown.esc.prevent="close"
        @keydown.tab="onTab"
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

.at-dialog-overlay--right {
  align-items: stretch;
  justify-content: flex-end;
  padding: 0;
  background: rgba(15, 23, 42, 0.24);
  backdrop-filter: none;
}
.at-dialog__panel--sheet {
  width: min(420px, 100%);
  height: 100%;
  max-height: none;
  border-width: 0 0 0 1px;
  border-radius: 0;
  animation: at-dialog-sheet-in var(--ds-motion-base, 180ms) var(--ds-ease-standard, ease-out);
}

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

@keyframes at-dialog-sheet-in {
  from { opacity: 0; transform: translateX(32px); }
  to { opacity: 1; transform: translateX(0); }
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
  .at-dialog__panel.at-dialog__panel--sheet { height: 100dvh; max-height: none; border-radius: 0; }
  .at-dialog__body { padding: var(--ds-space-4); }
  .at-dialog__header { padding: var(--ds-space-4) var(--ds-space-4) var(--ds-space-3); }
  .at-dialog__actions { padding: var(--ds-space-3) var(--ds-space-4) calc(var(--ds-space-4) + env(safe-area-inset-bottom, 0px)); }
}

@media (prefers-reduced-motion: reduce) {
  .at-dialog__panel,
  .at-dialog__panel--sheet { animation: none; }
}
</style>
