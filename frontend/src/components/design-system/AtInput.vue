<script setup>
/**
 * issue 702 AtInput — 統一文字/數字/電話輸入（Stripe Dashboard 取向）。
 * v-model 支援；error 紅框；focus ring。
 */
defineProps({
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
  id: { type: String, default: '' },
  inputmode: { type: String, default: '' },
  maxlength: { type: [String, Number], default: undefined },
});
defineEmits(['update:modelValue']);
</script>

<template>
  <input
    class="at-input"
    :class="{ 'at-input--error': error }"
    :type="type"
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :id="id || undefined"
    :inputmode="inputmode || undefined"
    :maxlength="maxlength"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>

<style scoped>
.at-input {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px;
  font-family: inherit;
  font-size: 14px;
  color: var(--ds-ink);
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.at-input::placeholder { color: var(--ds-ink-mute); }
.at-input:focus {
  outline: none;
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-primary-wash);
}
.at-input--error {
  border-color: var(--ds-danger);
}
.at-input--error:focus {
  box-shadow: 0 0 0 3px var(--ds-danger-wash);
}
.at-input:disabled {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  cursor: not-allowed;
}
</style>
