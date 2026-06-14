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
</style>
