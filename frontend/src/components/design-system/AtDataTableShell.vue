<script setup>
defineProps({
  caption: { type: String, default: '' },
  dense: { type: Boolean, default: true },
});
</script>

<template>
  <div
    class="at-data-table-shell"
    :class="{ 'at-data-table-shell--dense': dense }"
    data-testid="at-data-table-shell"
  >
    <div v-if="$slots.toolbar" class="at-data-table-shell__toolbar">
      <slot name="toolbar" />
    </div>
    <div class="at-data-table-shell__scroll">
      <table class="at-data-table-shell__table">
        <caption v-if="caption" class="visually-hidden">{{ caption }}</caption>
        <slot />
      </table>
    </div>
    <div v-if="$slots.footer" class="at-data-table-shell__footer">
      <slot name="footer" />
    </div>
  </div>
</template>

<style scoped>
.at-data-table-shell {
  background: var(--ds-surface-1);
  border: var(--ds-border-width) solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  overflow: hidden;
}

.at-data-table-shell__toolbar {
  padding: var(--ds-space-3) var(--ds-space-4);
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  background: var(--ds-surface-0);
}

.at-data-table-shell__scroll {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.at-data-table-shell__table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--ds-font-size-base);
  color: var(--ds-text-primary);
}

.at-data-table-shell :deep(th) {
  text-align: left;
  font-size: var(--ds-font-size-sm);
  font-weight: var(--ds-font-weight-semibold);
  color: var(--ds-text-tertiary);
  background: var(--ds-surface-0);
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  padding: 10px 12px;
  white-space: nowrap;
}

.at-data-table-shell--dense :deep(th),
.at-data-table-shell--dense :deep(td) {
  padding: 8px 10px;
}

.at-data-table-shell :deep(td) {
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  vertical-align: middle;
  font-variant-numeric: tabular-nums;
}

.at-data-table-shell :deep(tbody tr:hover) {
  background: var(--ds-surface-0);
}

.at-data-table-shell__footer {
  padding: var(--ds-space-3) var(--ds-space-4);
  border-top: var(--ds-border-width) solid var(--ds-hairline);
  background: var(--ds-surface-0);
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

@media (max-width: 390px) {
  .at-data-table-shell {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
}
</style>
