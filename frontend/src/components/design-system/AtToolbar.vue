<script setup>
defineProps({
  label: { type: String, default: '工具列' },
});
</script>

<template>
  <div class="at-toolbar" role="toolbar" :aria-label="label" data-testid="at-toolbar">
    <div v-if="$slots.default" class="at-toolbar__start">
      <slot />
    </div>
    <div v-if="$slots.end" class="at-toolbar__end">
      <slot name="end" />
    </div>
  </div>
</template>

<style scoped>
.at-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--ds-space-3);
  flex-wrap: wrap;
  min-height: var(--ds-control-height-lg);
}

.at-toolbar__start,
.at-toolbar__end {
  display: flex;
  align-items: center;
  gap: var(--ds-space-2);
  flex-wrap: wrap;
}

@media (max-width: 768px) {
  .at-toolbar__end {
    width: 100%;
  }
  .at-toolbar__end :deep(.at-btn) {
    flex: 1;
  }
}
</style>
