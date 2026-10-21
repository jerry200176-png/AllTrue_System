<template>
  <div class="legacy" data-testid="legacy-flag-actions">
    <p class="legacy__text" data-testid="legacy-flag-help">{{ LEGACY_FLAG_ACTION_HELP }}</p>
    <div class="legacy__buttons">
      <AtButton size="sm" shape="rect" :disabled="busy" data-testid="legacy-backfill-open" @click="openBackfill">已收過，補登收款</AtButton>
      <AtButton variant="secondary" size="sm" shape="rect" :disabled="busy" data-testid="legacy-clear-open" @click="openClear">其實沒收，改成未繳</AtButton>
    </div>
    <p v-if="error" class="legacy__error" role="alert" data-testid="legacy-error">{{ error }}</p>

    <AtDialog :open="backfillOpen" title="補登收款" size="sm" :title-id="`legacy-backfill-title-${courseId}`" @close="backfillOpen = false">
      <form class="legacy__form" data-testid="legacy-backfill-form" @submit.prevent="submitBackfill">
        <p class="legacy__note">會建立一筆已確認的收款紀錄（需要時一併開帳單），這門課維持已收。</p>
        <label class="legacy__label" :for="`legacy-date-${courseId}`">收款日期 *</label>
        <AtInput :id="`legacy-date-${courseId}`" v-model="form.payment_date" type="date" data-testid="legacy-backfill-date" />
        <label class="legacy__label" :for="`legacy-method-${courseId}`">繳費方式 *</label>
        <AtSelect :id="`legacy-method-${courseId}`" v-model="form.payment_method" :options="METHODS" placeholder="請選擇" data-testid="legacy-backfill-method" />
        <label class="legacy__label" :for="`legacy-amount-${courseId}`">金額 *</label>
        <AtInput :id="`legacy-amount-${courseId}`" v-model="form.amount" type="number" inputmode="numeric" data-testid="legacy-backfill-amount" />
        <label class="legacy__label" :for="`legacy-note-${courseId}`">備註（選填）</label>
        <AtTextarea :id="`legacy-note-${courseId}`" v-model="form.note" :rows="2" data-testid="legacy-backfill-note" />
      </form>
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" @click="backfillOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="!backfillValid || busy" data-testid="legacy-backfill-submit" @click="submitBackfill">補登收款</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="clearOpen" title="改成未繳" size="sm" :title-id="`legacy-clear-title-${courseId}`" @close="clearOpen = false">
      <p class="legacy__note">只把舊的「已收」標記拿掉，這門課會變成未繳、依合約金額列入收款。不會動任何帳單或收款紀錄。</p>
      <label class="legacy__label" :for="`legacy-reason-${courseId}`">原因 *</label>
      <AtTextarea :id="`legacy-reason-${courseId}`" v-model="reason" :rows="2" data-testid="legacy-clear-reason" />
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" @click="clearOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="reason.trim().length < 2 || busy" data-testid="legacy-clear-submit" @click="submitClear">改成未繳</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtInput from '../design-system/AtInput.vue';
import AtSelect from '../design-system/AtSelect.vue';
import AtTextarea from '../design-system/AtTextarea.vue';
import { backfillLegacyPayment, clearLegacyPaidFlag } from '../../lib/paymentActions.js';
import { LEGACY_FLAG_ACTION_HELP } from '../../lib/courseMoneyState.js';

// Founder GO 2026-10-21 (docs/DIRECTOR_PAYMENT_ALERT_RULES.md §只有舊「已收」標記): the 需核對 card says what to do next.
const props = defineProps({
  courseId: { type: Number, required: true },
  // Prefill only; the director edits it to what was actually received.
  contractAmount: { type: Number, default: null },
});
const emit = defineEmits(['resolved']);

const METHODS = [{ value: 'transfer', label: '匯款' }, { value: 'cash', label: '現金' }];
const busy = ref(false);
const error = ref('');
const backfillOpen = ref(false);
const clearOpen = ref(false);
const reason = ref('');
const form = reactive({ payment_date: '', payment_method: '', amount: '', note: '', key: '' });

// One key per opened form: a double click or a retry after a lost response replays instead of paying twice.
function newKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const b = globalThis.crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const backfillValid = computed(() => !!form.payment_date && form.payment_date <= today() && !!form.payment_method
  && Number.isInteger(Number(form.amount)) && Number(form.amount) > 0);

function openBackfill() {
  Object.assign(form, { payment_date: '', payment_method: '', amount: props.contractAmount > 0 ? String(props.contractAmount) : '', note: '', key: newKey() });
  error.value = '';
  backfillOpen.value = true;
}
function openClear() {
  reason.value = '';
  error.value = '';
  clearOpen.value = true;
}

async function run(action) {
  busy.value = true;
  const res = await action();
  busy.value = false;
  backfillOpen.value = false;
  clearOpen.value = false;
  if (!res.ok) { error.value = res.message; return; }
  emit('resolved');
}
function submitBackfill() {
  if (!backfillValid.value || busy.value) return;
  run(() => backfillLegacyPayment({
    student_class_id: props.courseId, payment_date: form.payment_date, payment_method: form.payment_method,
    amount: Number(form.amount), note: form.note.trim() || null, idempotency_key: form.key,
  }));
}
function submitClear() {
  if (reason.value.trim().length < 2 || busy.value) return;
  run(() => clearLegacyPaidFlag(props.courseId, reason.value.trim()));
}
</script>

<style scoped>
.legacy{display:grid;gap:6px}
.legacy__text{margin:0;font-size:var(--ds-font-size-base);font-weight:600;color:var(--ds-warning)}
.legacy__buttons{display:flex;flex-wrap:wrap;gap:6px 8px}
.legacy__form{display:grid;gap:6px}
.legacy__label{font-size:var(--ds-font-size-base);font-weight:700}
.legacy__note{margin:0 0 6px;font-size:var(--ds-font-size-base);line-height:1.6}
.legacy__error{margin:0;font-size:var(--ds-font-size-md);color:var(--ds-danger)}
</style>
