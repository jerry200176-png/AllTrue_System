<script setup>
// #4488 勾選聯動: tri-state "select all" for a useSelection() list. Native checkbox, so
// `indeterminate` is exposed as aria-checked="mixed" and Space toggles it. Clicking a mixed
// box selects every selectable visible row (WAI-ARIA APG checkbox pattern).
defineProps({
  selection: { type: Object, required: true },
  label: { type: String, default: '全選' },
  hideLabel: { type: Boolean, default: false },
  unit: { type: String, default: '筆' },
  showCount: { type: Boolean, default: true },
});
</script>

<template>
  <span class="at-select-all">
    <label class="at-select-all__control">
      <input
        type="checkbox"
        :checked="selection.all"
        :indeterminate.prop="selection.mixed"
        :disabled="selection.empty"
        @change="selection.toggleAll($event.target.checked)"
      >
      <span :class="hideLabel ? 'sr-only' : 'at-select-all__label'">{{ label }}</span>
    </label>
    <span v-if="showCount" class="at-select-all__count" role="status">
      <template v-if="selection.count">已選 {{ selection.count }} {{ unit }}</template>
    </span>
  </span>
</template>

<style scoped>
.at-select-all {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.at-select-all__control {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-size: var(--ds-font-size-md);
  color: var(--ds-ink);
}

.at-select-all__control input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: var(--ds-primary);
  cursor: inherit;
}

.at-select-all__control:has(input:disabled) {
  cursor: not-allowed;
  color: var(--ds-ink-mute);
}

.at-select-all__count {
  font-size: var(--ds-font-size-sm);
  color: var(--ds-ink-mute);
  font-variant-numeric: tabular-nums;
}
</style>
