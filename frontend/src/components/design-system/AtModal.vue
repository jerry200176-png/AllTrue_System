<script setup>
import { onMounted, onBeforeUnmount, watch } from 'vue';
import { lockScroll, unlockScroll } from '../../lib/useScrollLock.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  size: {
    type: String,
    default: 'md',
    validator: (v) => ['sm', 'md', 'lg'].includes(v),
  },
});

const emit = defineEmits(['close']);

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) emit('close');
}

watch(
  () => props.open,
  (v) => {
    if (v) lockScroll();
    else unlockScroll();
  },
  { immediate: true },
);

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  unlockScroll();
});
</script>

<template>
  <div
    v-if="open"
    class="at-modal-overlay"
    data-testid="at-modal"
    @click.self="emit('close')"
  >
    <div
      class="at-modal"
      :class="`at-modal--${size}`"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'at-modal-title'"
      :aria-describedby="description ? 'at-modal-desc' : undefined"
    >
      <header class="at-modal__header">
        <div>
          <h3 id="at-modal-title" class="at-modal__title">{{ title }}</h3>
          <p v-if="description" id="at-modal-desc" class="at-modal__desc">{{ description }}</p>
        </div>
        <button
          type="button"
          class="at-modal__close"
          aria-label="關閉"
          @click="emit('close')"
        >
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
      </header>
      <div class="at-modal__body">
        <slot />
      </div>
      <footer v-if="$slots.footer" class="at-modal__footer">
        <slot name="footer" />
      </footer>
    </div>
  </div>
</template>

<style scoped>
.at-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--ds-z-modal);
  background: rgba(13, 37, 61, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--ds-space-4);
}

.at-modal {
  width: min(520px, 100%);
  max-height: min(90vh, 900px);
  overflow: auto;
  background: var(--ds-surface-1);
  border-radius: var(--ds-radius-lg);
  border: var(--ds-border-width) solid var(--ds-hairline);
  box-shadow: var(--ds-shadow-2);
}

.at-modal--sm { width: min(400px, 100%); }
.at-modal--lg { width: min(720px, 100%); }

.at-modal__header {
  display: flex;
  justify-content: space-between;
  gap: var(--ds-space-3);
  padding: var(--ds-space-4);
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
}

.at-modal__title {
  margin: 0;
  font-size: var(--ds-font-size-lg);
  font-weight: var(--ds-font-weight-bold);
  color: var(--ds-text-primary);
}

.at-modal__desc {
  margin: var(--ds-space-1) 0 0;
  font-size: var(--ds-font-size-md);
  color: var(--ds-text-tertiary);
}

.at-modal__close {
  width: var(--ds-control-height-sm);
  height: var(--ds-control-height-sm);
  border: 0;
  background: transparent;
  color: var(--ds-text-tertiary);
  border-radius: var(--ds-radius-sm);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.at-modal__close:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.at-modal__body {
  padding: var(--ds-space-4);
}

.at-modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--ds-space-2);
  padding: var(--ds-space-3) var(--ds-space-4);
  border-top: var(--ds-border-width) solid var(--ds-hairline);
  background: var(--ds-surface-0);
}
</style>
