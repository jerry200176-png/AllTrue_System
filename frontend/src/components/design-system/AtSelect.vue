<script setup>
/**
 * issue 702 AtSelect — 薄封裝 native select（統一外框/focus/error）。
 * options: [{ value, label }] 或用 default slot 自帶 <option>。
 */
defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  id: { type: String, default: '' },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <div class="at-select-wrap" :class="{ 'at-select-wrap--error': error, 'at-select-wrap--disabled': disabled }">
    <select
      class="at-select"
      :value="modelValue"
      :disabled="disabled"
      :id="id || undefined"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
      <slot>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
      </slot>
    </select>
    <span class="at-select__chevron material-symbols-outlined" aria-hidden="true">expand_more</span>
  </div>
</template>

<style scoped>
.at-select-wrap {
  position: relative;
  display: block;
}
.at-select {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 36px 9px 12px;
  font-family: inherit;
  font-size: 14px;
  color: var(--ds-ink);
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  appearance: none;
  -webkit-appearance: none;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.at-select:focus {
  outline: none;
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-primary-wash);
}
.at-select-wrap--error .at-select { border-color: var(--ds-danger); }
.at-select-wrap--error .at-select:focus { box-shadow: 0 0 0 3px var(--ds-danger-wash); }
.at-select:disabled {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  cursor: not-allowed;
}
.at-select__chevron {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 18px;
  color: var(--ds-ink-mute);
  pointer-events: none;
}
</style>
