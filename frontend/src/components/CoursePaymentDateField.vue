<template>
  <div class="form-group">
    <label :for="inputId">繳費日期（選填）</label>
    <input :id="inputId" :value="modelValue" type="date" :disabled="locked || unavailable"
      :aria-describedby="`${inputId}-hint`" @input="updateDate($event.target.value)" />
    <p :id="`${inputId}-hint`" class="field-hint" role="status">
      <template v-if="unavailable">請先確認課程狀態，再更正繳費日期。</template>
      <template v-else-if="locked">{{ lockMessage || '已有收款紀錄，不能直接改為未繳費。請到帳務中心更正誤登收款，保留稽核紀錄。' }}</template>
      <template v-else-if="modelValue">儲存後將標示為已繳費；若尚未收款，請改為未繳費。</template>
      <template v-else>儲存後將標示為未繳費；尚未收款請保留空白。</template>
    </p>
    <button v-if="!unavailable && locked" type="button" class="ghost small" @click="$emit('open-billing')">前往帳務更正</button>
    <button v-else-if="!unavailable && modelValue" type="button" class="ghost small" @click="updateDate('')">改為未繳費（儲存後生效）</button>
  </div>
</template>

<script setup>
import { useId } from 'vue';
const props = defineProps({
  modelValue: { type: String, default: '' },
  locked: { type: Boolean, default: false },
  lockMessage: { type: String, default: '' },
  unavailable: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'open-billing']);
const inputId = `course-payment-date-${useId()}`;
function updateDate(value) {
  if (!props.locked && !props.unavailable) emit('update:modelValue', value);
}
</script>

<style scoped>
.field-hint { color: var(--ds-ink-secondary); font-size: 13px; margin: 8px 0; }
</style>
