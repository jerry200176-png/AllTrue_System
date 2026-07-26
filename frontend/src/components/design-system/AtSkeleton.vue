<script setup>
defineProps({
  rows: { type: Number, default: 3 },
  width: { type: String, default: '100%' },
  height: { type: String, default: '14px' },
});
</script>

<template>
  <div
    class="at-skeleton"
    role="status"
    aria-live="polite"
    aria-busy="true"
    data-testid="at-skeleton"
  >
    <span class="visually-hidden">載入中</span>
    <div
      v-for="n in rows"
      :key="n"
      class="at-skeleton__row"
      :style="{ width: n === rows ? '70%' : width, height }"
    />
  </div>
</template>

<style scoped>
.at-skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-2);
  padding: var(--ds-space-2) 0;
}

.at-skeleton__row {
  border-radius: var(--ds-radius-sm);
  background: linear-gradient(
    90deg,
    var(--ds-surface-0) 25%,
    var(--ds-hairline) 50%,
    var(--ds-surface-0) 75%
  );
  background-size: 200% 100%;
  animation: at-skeleton-shimmer var(--ds-motion-slow) linear infinite;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (prefers-reduced-motion: reduce) {
  .at-skeleton__row {
    animation: none;
    background: var(--ds-surface-2);
  }
}

@keyframes at-skeleton-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
</style>
