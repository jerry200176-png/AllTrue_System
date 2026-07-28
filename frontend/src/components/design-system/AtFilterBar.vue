<script setup>
defineProps({
  label: { type: String, default: '篩選' },
});
</script>

<template>
  <div class="at-filter-bar" role="search" :aria-label="label" data-testid="at-filter-bar">
    <slot />
  </div>
</template>

<style scoped>
.at-filter-bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: var(--ds-space-3);
  align-items: end;
  padding: var(--ds-space-3) 0;
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  margin-bottom: var(--ds-space-3);
}

.at-filter-bar :deep(label) {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-1);
  font-size: var(--ds-font-size-sm);
  font-weight: var(--ds-font-weight-medium);
  color: var(--ds-text-tertiary);
}

.at-filter-bar :deep(input),
.at-filter-bar :deep(select) {
  min-height: var(--ds-control-height-md);
  border: var(--ds-border-width) solid var(--ds-hairline-input);
  border-radius: var(--ds-radius-md);
  background: var(--ds-surface-1);
  color: var(--ds-text-primary);
  padding: 0 var(--ds-space-2);
  font-size: var(--ds-font-size-base);
}

.at-filter-bar :deep(input:focus-visible),
.at-filter-bar :deep(select:focus-visible) {
  outline: none;
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

@media (max-width: 390px) {
  .at-filter-bar {
    grid-template-columns: 1fr;
  }
}
</style>
