<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  icon: { type: String, default: '' },
});
</script>

<template>
  <header class="at-page-header" data-testid="at-page-header">
    <div class="at-page-header__main">
      <h2 class="at-page-header__title">
        <span
          v-if="icon"
          class="material-symbols-outlined at-page-header__icon"
          aria-hidden="true"
        >{{ icon }}</span>
        {{ title }}
      </h2>
      <p v-if="description" class="at-page-header__desc">{{ description }}</p>
      <div v-if="$slots.meta" class="at-page-header__meta">
        <slot name="meta" />
      </div>
    </div>
    <div v-if="$slots.actions" class="at-page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.at-page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--ds-space-4);
  margin-bottom: var(--ds-space-4);
  flex-wrap: wrap;
}

.at-page-header__title {
  display: flex;
  align-items: center;
  gap: var(--ds-space-2);
  margin: 0;
  font-size: var(--ds-font-size-xl);
  font-weight: var(--ds-font-weight-bold);
  line-height: var(--ds-line-tight);
  color: var(--ds-text-primary);
  letter-spacing: -0.02em;
}

.at-page-header__icon {
  font-size: 22px;
  color: var(--ds-text-secondary);
}

.at-page-header__desc {
  margin: var(--ds-space-1) 0 0;
  font-size: var(--ds-font-size-base);
  line-height: var(--ds-line-base);
  color: var(--ds-text-tertiary);
  max-width: 64ch;
}

.at-page-header__meta {
  margin-top: var(--ds-space-2);
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-2) var(--ds-space-3);
  font-size: var(--ds-font-size-md);
  color: var(--ds-text-secondary);
  font-variant-numeric: tabular-nums;
}

.at-page-header__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-2);
  align-items: center;
}

@media (max-width: 768px) {
  .at-page-header__actions {
    width: 100%;
  }
  .at-page-header__actions :deep(.at-btn) {
    flex: 1;
  }
}
</style>
