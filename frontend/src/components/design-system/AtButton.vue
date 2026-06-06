<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (v) => ['primary', 'secondary', 'ghost', 'danger'].includes(v),
  },
  size: {
    type: String,
    default: 'md',
    validator: (v) => ['sm', 'md'].includes(v),
  },
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  icon: { type: String, default: '' },
});

const classes = computed(() => [
  'at-btn',
  `at-btn--${props.variant}`,
  `at-btn--${props.size}`,
  { 'at-btn--block': props.block },
]);
</script>

<template>
  <button :class="classes" :type="type" :disabled="disabled">
    <span v-if="icon" class="material-symbols-outlined at-btn__icon" aria-hidden="true">{{ icon }}</span>
    <span class="at-btn__label"><slot /></span>
  </button>
</template>

<style scoped>
.at-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-family: inherit;
  font-weight: 600;
  border-radius: 999px;
  border: 1px solid transparent;
  cursor: pointer;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
  white-space: nowrap;
}

.at-btn--md {
  padding: 8px 16px;
  font-size: 14px;
}

.at-btn--sm {
  padding: 5px 12px;
  font-size: 12.5px;
}

.at-btn--block {
  display: flex;
  width: 100%;
}

.at-btn__icon {
  font-size: 18px;
  line-height: 1;
}

.at-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.at-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

/* Primary: 實心主色，hover 加深（無 gradient） */
.at-btn--primary {
  background: var(--ds-primary);
  color: var(--ds-on-primary);
}
.at-btn--primary:hover:not(:disabled) {
  background: var(--ds-primary-deep);
}
.at-btn--primary:active:not(:disabled) {
  background: var(--ds-primary-press);
}

/* Secondary: 白底 + 主色字與邊框 */
.at-btn--secondary {
  background: var(--ds-canvas);
  color: var(--ds-primary);
  border-color: var(--ds-primary);
}
.at-btn--secondary:hover:not(:disabled) {
  background: var(--ds-primary-wash);
}

/* Ghost: 透明底、navy 字、hairline 邊 */
.at-btn--ghost {
  background: transparent;
  color: var(--ds-ink);
  border-color: var(--ds-hairline);
}
.at-btn--ghost:hover:not(:disabled) {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline-input);
}

/* Danger: 實心紅 */
.at-btn--danger {
  background: var(--ds-danger);
  color: #fff;
}
.at-btn--danger:hover:not(:disabled) {
  filter: brightness(0.94);
}
</style>
