<script setup>
/**
 * issue 702 AtField — 表單欄位外殼：label + hint/error caption。包住 AtInput/AtSelect/AtTextarea。
 */
defineProps({
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: { type: Boolean, default: false },
  htmlFor: { type: String, default: '' },
});
</script>

<template>
  <div class="at-field" :class="{ 'at-field--error': !!error }">
    <label v-if="label" class="at-field__label" :for="htmlFor || undefined">
      {{ label }}<span v-if="required" class="at-field__req" aria-hidden="true">*</span>
    </label>
    <slot />
    <p v-if="error" class="at-field__msg at-field__msg--error">{{ error }}</p>
    <p v-else-if="hint" class="at-field__msg">{{ hint }}</p>
  </div>
</template>

<style scoped>
.at-field { display: grid; gap: 6px; }
.at-field__label { font-size: 12.5px; font-weight: 600; color: var(--ds-ink-mute); }
.at-field__req { color: var(--ds-danger); margin-left: 2px; }
.at-field__msg { margin: 0; font-size: 12px; color: var(--ds-ink-mute); line-height: 1.4; }
.at-field__msg--error { color: var(--ds-danger); }
/* #4697 item 3: one gentle nudge when an error appears, so a wrong value is noticed. */
.at-field--error { animation: at-field-nudge var(--ds-motion-slow, 240ms) var(--ds-ease-standard, ease-out); }
.at-field__msg--error { animation: at-field-msg-in var(--ds-motion-base, 180ms) var(--ds-ease-standard, ease-out); }
@keyframes at-field-nudge {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  50% { transform: translateX(3px); }
  75% { transform: translateX(-2px); }
}
@keyframes at-field-msg-in {
  from { opacity: 0; transform: translateY(-2px); }
  to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
  .at-field--error, .at-field__msg--error { animation: none; }
}
</style>
