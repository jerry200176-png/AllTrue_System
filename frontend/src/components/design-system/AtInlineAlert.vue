<script setup>
import { computed } from 'vue';

const props = defineProps({
  tone: {
    type: String,
    default: 'info',
    validator: (v) => ['info', 'success', 'warning', 'danger'].includes(v),
  },
  title: { type: String, default: '' },
});

const icon = computed(() => ({
  info: 'info',
  success: 'check_circle',
  warning: 'warning',
  danger: 'error',
}[props.tone] || 'info'));
</script>

<template>
  <div
    class="at-inline-alert"
    :class="`at-inline-alert--${tone}`"
    role="alert"
    data-testid="at-inline-alert"
  >
    <span class="material-symbols-outlined at-inline-alert__icon" aria-hidden="true">{{ icon }}</span>
    <div class="at-inline-alert__body">
      <p v-if="title" class="at-inline-alert__title">{{ title }}</p>
      <div class="at-inline-alert__content"><slot /></div>
    </div>
    <div v-if="$slots.action" class="at-inline-alert__action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.at-inline-alert {
  display: flex;
  align-items: flex-start;
  gap: var(--ds-space-2);
  padding: var(--ds-space-3);
  border-radius: var(--ds-radius-md);
  border: var(--ds-border-width) solid var(--ds-hairline);
  background: var(--ds-surface-0);
  color: var(--ds-text-primary);
  font-size: var(--ds-font-size-base);
  line-height: var(--ds-line-base);
}

.at-inline-alert__icon {
  font-size: 18px;
  line-height: 1;
  margin-top: 1px;
  flex-shrink: 0;
}

.at-inline-alert__title {
  margin: 0 0 2px;
  font-weight: var(--ds-font-weight-semibold);
}

.at-inline-alert__content {
  min-width: 0;
}

.at-inline-alert__action {
  margin-left: auto;
  flex-shrink: 0;
}

.at-inline-alert--info {
  background: var(--ds-info-wash);
  border-color: transparent;
  color: var(--ds-primary-deep);
}
.at-inline-alert--success {
  background: var(--ds-success-wash);
  border-color: transparent;
  color: var(--ds-success);
}
.at-inline-alert--warning {
  background: var(--ds-warning-wash);
  border-color: transparent;
  color: var(--ds-warning);
}
.at-inline-alert--danger {
  background: var(--ds-danger-wash);
  border-color: transparent;
  color: var(--ds-danger);
}
</style>
