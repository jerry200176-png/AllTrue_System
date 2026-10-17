<script setup>
import { ref, watch } from 'vue';
import AtButton from './design-system/AtButton.vue';
import AtDialog from './design-system/AtDialog.vue';

const props = defineProps({ courseId: { type: Number, required: true }, invoiceId: { type: Number, default: null } });
const emit = defineEmits(['close', 'changed']);
const newCharge = ref('');
const reason = ref('');
const preview = ref(null);
const busy = ref(false);
const error = ref('');
let pending = null;
let submitted = false;
/** Bumps on every intent change; in-flight responses must match the generation they started with. */
let intentGeneration = 0;
let requestGeneration = 0;
/** Snapshot of inputs that produced the current preview/pending (or in-flight request). */
let requestSnapshot = null;

const money = (value) => value == null ? '尚未開單' : `NT$${Number(value).toLocaleString('zh-TW')}`;

function currentSnapshot() {
  return { new_charge: Number(newCharge.value), reason: reason.value.trim() };
}

function snapshotsEqual(a, b) {
  return a && b && a.new_charge === b.new_charge && a.reason === b.reason;
}

function invalidatePreviewState() {
  preview.value = null;
  pending = null;
  submitted = false;
  requestSnapshot = null;
}

function bumpIntent() {
  intentGeneration += 1;
  // Any field edit after a preview (or while a request is outstanding) voids the signed plan.
  if (preview.value || (pending && !busy.value) || (busy.value && requestGeneration !== intentGeneration)) {
    invalidatePreviewState();
  }
}

watch([newCharge, reason], () => {
  bumpIntent();
});

async function send(action, body) {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const response = await fetch(`/api/v1/student-classes/${props.courseId}/agreed-fee-correction/${action}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${session.access_token || ''}` },
    body: JSON.stringify(body),
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok) throw Object.assign(new Error(json.message || '無法完成更正，請稍後重試。'), { status: response.status });
  return json;
}

async function review() {
  busy.value = true; error.value = '';
  const snapshot = currentSnapshot();
  requestGeneration = intentGeneration;
  requestSnapshot = snapshot;
  pending = {
    scope: props.invoiceId ? 'billed' : 'unbilled',
    original_invoice_id: props.invoiceId,
    new_charge: snapshot.new_charge,
    reason: snapshot.reason,
    idempotency_key: crypto.randomUUID(),
  };
  try {
    const plan = await send('preview', pending);
    // Drop stale responses: generation drifted or inputs no longer match the signed request.
    if (requestGeneration !== intentGeneration || !snapshotsEqual(snapshot, currentSnapshot())) {
      invalidatePreviewState();
      error.value = '內容已變更，請重新預覽。';
      return;
    }
    preview.value = plan;
    submitted = false;
  } catch (e) {
    invalidatePreviewState();
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function backToEdit() {
  intentGeneration += 1;
  invalidatePreviewState();
  error.value = '';
}

async function apply() {
  if (!preview.value || !pending || !requestSnapshot || !snapshotsEqual(requestSnapshot, currentSnapshot())) {
    invalidatePreviewState();
    error.value = '內容已變更，請重新預覽。';
    return;
  }
  busy.value = true; error.value = ''; submitted = true;
  const applyGeneration = intentGeneration;
  const applySnapshot = requestSnapshot;
  try {
    await send('apply', { ...pending, preview_token: preview.value.preview_token });
    if (applyGeneration !== intentGeneration || !snapshotsEqual(applySnapshot, currentSnapshot())) {
      // UI intent drifted after apply started; backend may have accepted the signed payload —
      // do not emit success for a mismatched screen. Operator must re-open/re-preview.
      invalidatePreviewState();
      error.value = '內容已變更，請重新預覽並確認結果。';
      return;
    }
    emit('changed'); emit('close');
  } catch (e) {
    error.value = e.message;
    // A lost response keeps the identical key/payload for retry. A definitive conflict requires a fresh preview.
    if ([409, 422].includes(e.status)) { invalidatePreviewState(); }
  } finally { busy.value = false; }
}
</script>

<template>
  <AtDialog open title="只更正約定費用" title-id="agreed-fee-title" size="sm" :close-on-backdrop="!busy" @close="!busy && $emit('close')">
    <form v-if="!preview" class="fee-form" @submit.prevent="review">
      <label>新的約定費用（整數元）<input v-model="newCharge" data-testid="fee-charge" type="number" min="1" max="99999999" step="1" required :disabled="busy" /></label>
      <label>更正原因<input v-model="reason" data-testid="fee-reason" minlength="3" maxlength="80" required :disabled="busy" /></label>
      <AtButton type="submit" :loading="busy" data-testid="fee-preview">預覽更正</AtButton>
    </form>
    <div v-else class="fee-preview" data-testid="fee-plan">
      <dl><dt>目前儲存的約定費用</dt><dd>{{ money(preview.old_charge) }}</dd>
        <dt>所選原帳單金額</dt><dd>{{ money(preview.original_invoice_total) }}</dd>
        <dt>新的約定費用</dt><dd>{{ money(preview.new_charge) }}</dd>
        <dt>約定費用差額</dt><dd>{{ money(preview.charge_delta) }}</dd>
        <dt>帳單應收差額</dt><dd>{{ money(preview.obligation_delta) }}</dd>
        <dt>更正後帳單待收</dt><dd>{{ money(preview.new_outstanding) }}</dd></dl>
      <p>{{ preview.message }}</p>
      <p>堂數 {{ preview.unchanged.SessionCount }}、已用 {{ preview.unchanged.UsedSessions }}、剩餘 {{ preview.unchanged.RemainingSessions }} 不變；單價 {{ money(preview.unchanged.Rate) }} 與起訖日 {{ preview.unchanged.StartDate }} ～ {{ preview.unchanged.EndDate }} 不變。</p>
      <p>新增收款 0、付款回報 0、收據 0、退款 0、通知 0。上課、出席與扣堂紀錄不變。</p>
    </div>
    <p v-if="error" role="alert">{{ error }}</p>
    <template #actions>
      <AtButton variant="secondary" :disabled="busy" @click="$emit('close')">取消</AtButton>
      <AtButton v-if="preview && !submitted" variant="secondary" :disabled="busy" data-testid="fee-back" @click="backToEdit">返回修改</AtButton>
      <AtButton v-if="preview" :loading="busy" data-testid="fee-apply" @click="apply">{{ submitted ? '重試同一筆更正' : '確認只更正費用' }}</AtButton>
    </template>
  </AtDialog>
</template>
<style scoped>
.fee-form { display: grid; gap: var(--ds-space-4, 16px); }
.fee-form label { display: grid; gap: var(--ds-space-2, 8px); }
.fee-form input { border: 1px solid var(--ds-hairline-input); padding: 8px; border-radius: var(--ds-radius-sm); }
.fee-form input:disabled { opacity: 0.65; cursor: not-allowed; }
.fee-preview dl { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.fee-preview dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
</style>
