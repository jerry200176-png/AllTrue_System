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
  /** pill = legacy brand; rect = ops foundation default for new surfaces */
  shape: {
    type: String,
    default: 'pill',
    validator: (v) => ['pill', 'rect'].includes(v),
  },
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  icon: { type: String, default: '' },
});

const classes = computed(() => [
  'at-btn',
  `at-btn--${props.variant}`,
  `at-btn--${props.size}`,
  `at-btn--${props.shape}`,
  { 'at-btn--block': props.block, 'at-btn--loading': props.loading },
]);

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
  <button
    :class="classes"
    :type="type"
    :disabled="isDisabled"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span
      v-if="loading"
      class="material-symbols-outlined at-btn__icon at-btn__spinner"
      aria-hidden="true"
    >progress_activity</span>
    <span
      v-else-if="icon"
      class="material-symbols-outlined at-btn__icon"
      aria-hidden="true"
    >{{ icon }}</span>
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
  border: 1px solid transparent;
  cursor: pointer;
  transition: background-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease),
    border-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease),
    color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease);
  white-space: nowrap;
  min-height: var(--ds-control-height-md, 32px);
}

.at-btn--pill {
  border-radius: var(--ds-radius-pill, 9999px);
}

.at-btn--rect {
  border-radius: var(--ds-radius-md, 6px);
}

.at-btn--md {
  padding: 0 14px;
  font-size: 14px;
  min-height: var(--ds-control-height-md, 32px);
}

.at-btn--sm {
  padding: 0 10px;
  font-size: 12.5px;
  min-height: var(--ds-control-height-sm, 28px);
}

.at-btn--block {
  display: flex;
  width: 100%;
}

.at-btn__icon {
  font-size: 18px;
  line-height: 1;
}

.at-btn__spinner {
  animation: at-btn-spin 0.8s linear infinite;
}

@keyframes at-btn-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .at-btn__spinner { animation: none; }
}

.at-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.at-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

/* Primary: AA CTA fill (≥4.5:1 with --ds-on-cta); not brand amber gradient */
.at-btn--primary {
  background: var(--ds-cta);
  color: var(--ds-on-cta);
}
.at-btn--primary:hover:not(:disabled) {
  background: var(--ds-cta-hover);
}
.at-btn--primary:active:not(:disabled) {
  background: var(--ds-cta-press);
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
