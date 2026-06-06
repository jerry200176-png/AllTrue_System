<script setup>
const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (v) => ['default', 'inset'].includes(v),
  },
  title: { type: String, default: '' },
});
</script>

<template>
  <section :class="['at-card', `at-card--${props.variant}`]">
    <header v-if="title || $slots.header" class="at-card__header">
      <slot name="header">
        <h3 class="at-card__title">{{ title }}</h3>
      </slot>
      <div v-if="$slots.actions" class="at-card__actions">
        <slot name="actions" />
      </div>
    </header>
    <div class="at-card__body">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.at-card {
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  padding: 24px;
}

.at-card--inset {
  background: var(--ds-canvas-soft);
  border-radius: 8px;
  padding: 16px;
}

.at-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.at-card__title {
  font-size: 18px;
  font-weight: 700;
  color: var(--ds-ink);
  margin: 0;
}

.at-card__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.at-card__body {
  color: var(--ds-ink-secondary);
}
</style>
