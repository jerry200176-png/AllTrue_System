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
.at-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 6px;
  padding: 32px 16px;
}

.at-empty__icon {
  font-size: 32px;
  color: var(--ds-ink-mute);
  margin-bottom: 2px;
}

.at-empty__title {
  font-size: 15px;
  font-weight: 600;
  color: var(--ds-ink);
  margin: 0;
}

.at-empty__desc {
  font-size: 13px;
  color: var(--ds-ink-mute);
  margin: 0;
  max-width: 360px;
}

.at-empty__action {
  margin-top: 12px;
}
</style>
