<script setup>
import { ref, watch } from 'vue';
/**
 * issue 702 AtField — 表單欄位外殼：label + hint/error caption。包住 AtInput/AtSelect/AtTextarea。
 */
const props = defineProps({
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: { type: Boolean, default: false },
  htmlFor: { type: String, default: '' },
});

// A new error text swaps between two identical keyframes so the nudge replays
// when the error changes, but never on an unrelated re-render (#4697 item 3).
const flip = ref(false);
watch(() => props.error, (next, prev) => { if (next && prev && next !== prev) flip.value = !flip.value; });
</script>

<template>
  <div class="at-field" :class="error ? ['at-field--error', flip ? 'at-field--nudge-b' : 'at-field--nudge-a'] : null">
    <label v-if="label" class="at-field__label" :for="htmlFor || undefined">
      {{ label }}<span v-if="required" class="at-field__req" aria-hidden="true">*</span>
    </label>
    <slot />
    <p v-if="error" :key="error" class="at-field__msg at-field__msg--error">{{ error }}</p>
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
.at-field--nudge-a { animation: at-field-nudge-a var(--ds-motion-slow) var(--ds-ease-standard); }
.at-field--nudge-b { animation: at-field-nudge-b var(--ds-motion-slow) var(--ds-ease-standard); }
.at-field__msg--error { animation: at-field-msg-in var(--ds-motion-base) var(--ds-ease-standard); }
@keyframes at-field-nudge-a {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  50% { transform: translateX(3px); }
  75% { transform: translateX(-2px); }
}
@keyframes at-field-nudge-b {
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
  .at-field--nudge-a, .at-field--nudge-b, .at-field__msg--error { animation: none; }
}
</style>
