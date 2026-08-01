<script setup>
defineProps({
  title: { type: String, default: '' },
  flush: { type: Boolean, default: false },
});
</script>

<template>
  <section class="at-section" :class="{ 'at-section--flush': flush }" data-testid="at-section">
    <div v-if="title || $slots.header || $slots.actions" class="at-section__header">
      <div class="at-section__heading">
        <h3 v-if="title" class="at-section__title">{{ title }}</h3>
        <slot name="header" />
      </div>
      <div v-if="$slots.actions" class="at-section__actions">
        <slot name="actions" />
      </div>
    </div>
    <div class="at-section__body">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.at-section {
  background: var(--ds-surface-1);
  border: var(--ds-border-width) solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  padding: var(--ds-space-4);
}

.at-section--flush {
  padding: 0;
  background: transparent;
  border: 0;
  border-radius: 0;
}

.at-section__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--ds-space-3);
  margin-bottom: var(--ds-space-3);
  flex-wrap: wrap;
}

.at-section__title {
  margin: 0;
  font-size: var(--ds-font-size-base);
  font-weight: var(--ds-font-weight-semibold);
  color: var(--ds-text-primary);
}

.at-section__actions {
  display: flex;
  gap: var(--ds-space-2);
  flex-wrap: wrap;
}
</style>
