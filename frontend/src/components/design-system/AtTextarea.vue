<script setup>
/**
 * issue 702 AtTextarea — 統一多行輸入。v-model；error 紅框；focus ring。
 */
defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  rows: { type: [String, Number], default: 3 },
  id: { type: String, default: '' },
  maxlength: { type: [String, Number], default: undefined },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <textarea
    class="at-textarea"
    :class="{ 'at-textarea--error': error }"
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :rows="rows"
    :id="id || undefined"
    :maxlength="maxlength"
    @input="$emit('update:modelValue', $event.target.value)"
  ></textarea>
</template>

<style scoped>
.at-textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px;
  font-family: inherit;
  font-size: 14px;
  line-height: 1.5;
  color: var(--ds-ink);
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  resize: vertical;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.at-textarea::placeholder { color: var(--ds-ink-mute); }
.at-textarea:focus {
  outline: none;
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-primary-wash);
}
.at-textarea--error { border-color: var(--ds-danger); }
.at-textarea--error:focus { box-shadow: 0 0 0 3px var(--ds-danger-wash); }
.at-textarea:disabled {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  cursor: not-allowed;
}
</style>
