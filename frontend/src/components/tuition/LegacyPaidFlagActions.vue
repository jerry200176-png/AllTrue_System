<template>
  <div class="legacy" data-testid="legacy-flag-actions">
    <p class="legacy__text" data-testid="legacy-flag-help">{{ LEGACY_FLAG_ACTION_HELP }}</p>
    <div class="legacy__buttons">
      <AtButton size="sm" shape="rect" :disabled="busy" data-testid="legacy-backfill-open" @click="emit('backfill')">已收過，補登收款</AtButton>
      <AtButton variant="secondary" size="sm" shape="rect" :disabled="busy" data-testid="legacy-clear-open" @click="openClear">其實沒收，改成未繳</AtButton>
    </div>

    <AtDialog :open="clearOpen" title="改成未繳" size="sm" :title-id="`legacy-clear-title-${courseId}`" @close="clearOpen = false">
      <p class="legacy__note">只把舊的「已收」標記拿掉，這門課會變成未繳、依合約金額列入收款。不會動任何帳單或收款紀錄。</p>
      <label class="legacy__label" :for="`legacy-reason-${courseId}`">原因 <span aria-hidden="true">*</span></label>
      <AtTextarea :id="`legacy-reason-${courseId}`" v-model="reason" :rows="2" data-testid="legacy-clear-reason" />
      <p v-if="error" class="legacy__error" role="alert" data-testid="legacy-error">{{ error }}</p>
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" @click="clearOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="busy" data-testid="legacy-clear-submit" @click="submitClear">改成未繳</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtTextarea from '../design-system/AtTextarea.vue';
import { clearLegacyPaidFlag } from '../../lib/paymentActions.js';
import { LEGACY_FLAG_ACTION_HELP } from '../../lib/courseMoneyState.js';

// Founder GO 2026-10-21 (docs/DIRECTOR_PAYMENT_ALERT_RULES.md §只有舊「已收」標記): the 需核對 card says what to do next.
const props = defineProps({
  courseId: { type: Number, required: true },
});
const emit = defineEmits(['backfill', 'resolved']);

const busy = ref(false);
const error = ref('');
const clearOpen = ref(false);
const reason = ref('');

function openClear() {
  reason.value = '';
  error.value = '';
  clearOpen.value = true;
}

// A failure keeps the dialog open with the reason and the server's sentence.
async function submitClear() {
  if (busy.value) return;
  if (reason.value.trim().length < 2) { error.value = '請寫下原因（至少 2 個字）。'; return; }
  busy.value = true;
  error.value = '';
  const res = await clearLegacyPaidFlag(props.courseId, reason.value.trim());
  busy.value = false;
  if (!res.ok) { error.value = res.message; return; }
  clearOpen.value = false;
  emit('resolved');
}
</script>

<style scoped>
.legacy{display:grid;gap:6px}
.legacy__text{margin:0;font-size:var(--ds-font-size-base);font-weight:600;color:var(--ds-warning)}
.legacy__buttons{display:flex;flex-wrap:wrap;gap:6px 8px}
.legacy__label{display:block;margin-bottom:6px;font-size:var(--ds-font-size-base);font-weight:700}
.legacy__note{margin:0 0 6px;font-size:var(--ds-font-size-base);line-height:1.6}
.legacy__error{margin:6px 0 0;font-size:var(--ds-font-size-md);color:var(--ds-danger)}
</style>
