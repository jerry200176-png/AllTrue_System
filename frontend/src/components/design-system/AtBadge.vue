<script setup>
import { computed } from 'vue';

const props = defineProps({
  tone: {
    type: String,
    default: 'neutral',
    validator: (v) => ['neutral', 'info', 'success', 'warning', 'danger'].includes(v),
  },
  /** Prefer text + tone; never color-only meaning. */
  label: { type: String, required: true },
});

const classes = computed(() => ['at-badge', `at-badge--${props.tone}`]);
</script>

<template>
  <span :class="classes" data-testid="at-badge">
    <span class="at-badge__dot" aria-hidden="true" />
    <span class="at-badge__label">{{ label }}</span>
  </span>
</template>

<style scoped>
.at-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 20px;
  padding: 0 7px;
  border-radius: var(--ds-radius-sm);
  border: var(--ds-border-width) solid var(--ds-hairline);
  background: var(--ds-surface-0);
  color: var(--ds-text-secondary);
  font-size: var(--ds-font-size-xs);
  font-weight: var(--ds-font-weight-semibold);
  line-height: 1;
  white-space: nowrap;
}

.at-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

.at-badge--neutral { color: var(--ds-text-secondary); }
.at-badge--info {
  color: var(--ds-primary-deep);
  background: var(--ds-primary-wash);
  border-color: transparent;
}
.at-badge--success {
  color: var(--ds-success);
  background: var(--ds-success-wash);
  border-color: transparent;
}
.at-badge--warning {
  color: var(--ds-warning);
  background: var(--ds-warning-wash);
  border-color: transparent;
}
.at-badge--danger {
  color: var(--ds-danger);
  background: var(--ds-danger-wash);
  border-color: transparent;
}
</style>
