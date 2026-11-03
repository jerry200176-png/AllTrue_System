<template>
  <AtDialog :open="open" title="退費" size="sm" title-id="refund-title" @close="emit('close')">
    <p v-if="loadError" class="refund__error" data-testid="refund-load-error">{{ loadError }}</p>
    <template v-else-if="preview">
      <p data-testid="refund-paid">已繳 {{ money(preview.paid) }}</p>
      <p data-testid="refund-used">已上 {{ preview.used_lessons }} 堂 × 單價 {{ money(preview.rate) }}</p>
      <p data-testid="refund-suggested"><strong>建議退 {{ money(preview.refundable) }}</strong></p>
      <label class="refund__label" for="refund-amount">退費金額（最多 {{ money(preview.refundable) }}）</label>
      <input id="refund-amount" v-model.number="amount" type="number" min="1" :max="preview.refundable" data-testid="refund-amount" />
      <label class="refund__label" for="refund-date">退費日期</label>
      <input id="refund-date" v-model="date" type="date" :max="today" data-testid="refund-date" />
      <label class="refund__label" for="refund-method">退費方式</label>
      <select id="refund-method" v-model="method" data-testid="refund-method">
        <option value="cash">現金</option>
        <option value="transfer">匯款</option>
      </select>
      <label class="refund__label" for="refund-reason">原因（必填）</label>
      <AtTextarea id="refund-reason" v-model="reason" :rows="2" data-testid="refund-reason" />
      <p v-if="problem" class="refund__error" data-testid="refund-problem">{{ problem }}</p>
      <p class="refund__note">退費後這門課會結束，還沒上的課會取消。</p>
    </template>
    <p v-if="submitError" class="refund__error" data-testid="refund-error">{{ submitError }}</p>
    <template #actions>
      <AtButton variant="secondary" size="sm" shape="rect" data-testid="refund-cancel" @click="emit('close')">取消</AtButton>
      <AtButton size="sm" shape="rect" :disabled="!preview || !!problem || busy" data-testid="refund-submit" @click="submit">確認退費</AtButton>
    </template>
  </AtDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtTextarea from '../design-system/AtTextarea.vue';

const props = defineProps({ open: { type: Boolean, default: false }, courseId: { type: Number, required: true } });
const emit = defineEmits(['close', 'done']);

const money = (n) => `NT$${Number(n || 0).toLocaleString()}`;
const today = new Date().toISOString().slice(0, 10);
const preview = ref(null);
const loadError = ref('');
const submitError = ref('');
const busy = ref(false);
const amount = ref(0);
const date = ref(today);
const method = ref('cash');
const reason = ref('');

const problem = computed(() => {
  if (!preview.value) return '';
  if (!Number.isInteger(amount.value) || amount.value <= 0) return '請填退費金額。';
  if (amount.value > preview.value.refundable) return `退費金額不能超過 ${money(preview.value.refundable)}。`;
  if (!reason.value.trim()) return '請填退費原因。';
  return '';
});

async function readError(resp) {
  const body = await resp.json().catch(() => ({}));
  return humanizeApiErrorMessage(Object.values(body.errors || {}).flat()[0] || body.message || '退費失敗，請再試一次。');
}

watch(() => props.open, async (isOpen) => {
  if (!isOpen) return;
  preview.value = null;
  loadError.value = submitError.value = reason.value = '';
  try {
    const resp = await authedFetch(`/api/v1/student-classes/${props.courseId}/refund/preview`, { headers: { Accept: 'application/json' } });
    if (!resp.ok) throw new Error(await readError(resp));
    preview.value = await resp.json();
    amount.value = preview.value.refundable;
  } catch (e) {
    loadError.value = e.message || '讀取失敗，請再試一次。';
  }
}, { immediate: true });

async function submit() {
  if (problem.value || busy.value) return;
  busy.value = true;
  submitError.value = '';
  try {
    const resp = await authedFetch(`/api/v1/student-classes/${props.courseId}/refund`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ amount: amount.value, method: method.value, date: date.value, reason: reason.value.trim() }),
    });
    if (!resp.ok) throw new Error(await readError(resp));
    emit('done');
  } catch (e) {
    submitError.value = e.message || '退費失敗，請再試一次。';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.refund__label{display:block;margin-top:.6rem;font-size:.85rem}
.refund__error{color:var(--ds-danger)}
.refund__note{font-size:.85rem;opacity:.75}
</style>
