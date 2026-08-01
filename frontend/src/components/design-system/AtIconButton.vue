<script setup>
import { computed } from 'vue';

const props = defineProps({
  icon: { type: String, required: true },
  label: { type: String, required: true },
  variant: {
    type: String,
    default: 'ghost',
    validator: (v) => ['ghost', 'danger'].includes(v),
  },
  disabled: { type: Boolean, default: false },
  type: { type: String, default: 'button' },
});

const classes = computed(() => ['at-icon-btn', `at-icon-btn--${props.variant}`]);
</script>

<template>
  <button
    :class="classes"
    :type="type"
    :disabled="disabled"
    :aria-label="label"
    :title="label"
    data-testid="at-icon-button"
  >
    <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
  </button>
</template>

<style scoped>
.at-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--ds-control-height-md);
  height: var(--ds-control-height-md);
  padding: 0;
  border-radius: var(--ds-radius-md);
  border: var(--ds-border-width) solid var(--ds-hairline);
  background: transparent;
  color: var(--ds-text-secondary);
  cursor: pointer;
  transition: background-color var(--ds-motion-fast) var(--ds-ease-standard),
    border-color var(--ds-motion-fast) var(--ds-ease-standard),
    color var(--ds-motion-fast) var(--ds-ease-standard);
}

.at-icon-btn .material-symbols-outlined {
  font-size: 18px;
  line-height: 1;
}

.at-icon-btn:hover:not(:disabled) {
  background: var(--ds-surface-0);
  color: var(--ds-text-primary);
}

.at-icon-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.at-icon-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.at-icon-btn--danger {
  color: var(--ds-danger);
  border-color: transparent;
}
.at-icon-btn--danger:hover:not(:disabled) {
  background: var(--ds-danger-wash);
}
</style>
