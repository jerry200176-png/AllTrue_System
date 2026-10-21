<template>
  <AtInlineAlert v-if="error" tone="danger" title="儲存失敗" style="margin: 0 0 14px;">
    <p>{{ error.message }}</p>
    <p v-if="error.details" class="save-error__detail">{{ error.details }}</p>
    <p v-if="hint" class="save-error__detail" data-testid="save-error-next-step">{{ hint }}</p>
    <p v-if="notCarried" class="save-error__detail" data-testid="save-error-not-carried">注意：轉課不會套用你改的{{ notCarried }}，新合約沿用剩餘堂數。</p>
    <button
      v-if="canOpen(error.nextStep)"
      type="button"
      class="small primary"
      data-testid="save-error-action"
      @click="$emit('action', error.nextStep)"
    >{{ actionLabel }}</button>
  </AtInlineAlert>
</template>

<script setup>
// One next step per refused 課程設定 save (in-app #409): the same next-step map
// (courseEditability.js) the editability panel uses; the parent opens it through
// openEditabilityAction().
import { computed } from 'vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import { editabilityActionLabel, editabilityNextStepLabel } from '../../lib/courseEditability.js';
import { formatTransferStartDate } from '../../lib/courseTransferPrefill.js';

const props = defineProps({
  // { message, details?, nextStep?, transferPrefill? }
  error: { type: Object, default: null },
  canOpen: { type: Function, default: () => false },
});
defineEmits(['action']);

const transferDate = computed(() => (
  props.error?.nextStep === 'course_transfer' ? formatTransferStartDate(props.error?.transferPrefill?.start_date) : ''
));
const hint = computed(() => editabilityNextStepLabel(props.error?.nextStep));
const notCarried = computed(() => (props.error?.transferPrefill?.not_carried || []).join('、'));
const actionLabel = computed(() => {
  const label = editabilityActionLabel(props.error?.nextStep);
  return transferDate.value ? `${label}（${transferDate.value} 起生效）` : label;
});
</script>

<style scoped>
.save-error__detail { margin: 4px 0 0; font-size: var(--ds-font-size-sm); }
</style>
