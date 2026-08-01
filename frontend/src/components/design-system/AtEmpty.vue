<script setup>
const props = defineProps({
  icon: { type: String, default: 'inbox' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
});
</script>

<template>
  <div class="at-empty">
    <span class="material-symbols-outlined at-empty__icon" aria-hidden="true">{{ props.icon }}</span>
    <p class="at-empty__title">{{ props.title }}</p>
    <p v-if="description" class="at-empty__desc">{{ description }}</p>
    <div v-if="$slots.action" class="at-empty__action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
/* Compact empty state — no giant centered icon hero (ops density). */
.at-empty {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
  gap: var(--ds-space-1, 4px);
  padding: var(--ds-space-5, 24px) var(--ds-space-4, 16px);
  border: var(--ds-border-width, 1px) dashed var(--ds-hairline);
  border-radius: var(--ds-radius-md, 6px);
  background: var(--ds-surface-0, var(--ds-canvas-soft));
}

.at-empty__icon {
  font-size: 20px;
  color: var(--ds-ink-mute);
  margin-bottom: 2px;
}

.at-empty__title {
  font-size: var(--ds-font-size-base, 14px);
  font-weight: 600;
  color: var(--ds-ink);
  margin: 0;
}

.at-empty__desc {
  font-size: var(--ds-font-size-md, 13px);
  color: var(--ds-ink-mute);
  margin: 0;
  max-width: 42ch;
}

.at-empty__action {
  margin-top: var(--ds-space-3, 12px);
}
</style>
