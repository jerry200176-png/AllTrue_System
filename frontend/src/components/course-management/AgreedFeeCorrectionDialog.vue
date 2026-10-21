<template>
  <AtDialog
    :open="open"
    title="更正約定費用"
    title-id="agreed-fee-correction-title"
    size="md"
    :close-on-backdrop="!busy"
    @close="onClose"
  >
    <p class="afc-desc">{{ courseLabel }}／目前約定費用 <span class="afc-num">${{ money(currentCharge) }}</span></p>

    <template v-if="result">
      <AtInlineAlert tone="success" title="已更正約定費用">
        <p class="afc-p">約定費用 <span class="afc-num">${{ money(result.old_charge) }} → ${{ money(result.new_charge) }}</span>。</p>
        <p v-if="result.replacement_invoice_id" class="afc-p">
          原帳單 {{ invoiceRef(result.original_invoice_id) }} 已作廢，新未繳帳單 {{ invoiceRef(result.replacement_invoice_id) }}。
        </p>
        <p v-else class="afc-p">尚未開帳單，未建立新帳單。</p>
        <p class="afc-p">更正紀錄已保存。沒有建立收款、收據、退款或通知。</p>
      </AtInlineAlert>
    </template>

    <template v-else>
      <fieldset class="afc-fieldset" :disabled="busy">
        <legend class="afc-label">更正範圍</legend>
        <label class="afc-radio">
          <input v-model="form.scope" type="radio" name="afc-scope" value="billed" data-testid="afc-scope-billed" />
          有效未繳帳單
        </label>
        <label class="afc-radio">
          <input v-model="form.scope" type="radio" name="afc-scope" value="unbilled" data-testid="afc-scope-unbilled" />
          尚未開帳單
        </label>
      </fieldset>

      <template v-if="form.scope === 'billed'">
        <p v-if="detectedInvoiceId" class="afc-hint" data-testid="afc-detected-invoice">原帳單：{{ invoiceRef(detectedInvoiceId) }}（此課程唯一有效未繳帳單）</p>
        <template v-else>
          <label class="afc-label" for="afc-invoice-id">原帳單編號</label>
          <input id="afc-invoice-id" v-model.number="form.originalInvoiceId" data-testid="afc-invoice-id" type="number" min="1" step="1" class="afc-input" :disabled="busy" />
          <p class="afc-hint">找不到唯一的有效未繳帳單，請填寫要更正的帳單編號（帳單號碼最後 6 碼數字）。</p>
        </template>
      </template>

      <label class="afc-label" for="afc-new-charge">新約定費用（NT$）</label>
      <input id="afc-new-charge" v-model.number="form.newCharge" data-testid="afc-new-charge" type="number" min="1" :max="CHARGE_MAX" step="1" class="afc-input afc-num" :disabled="busy" />

      <label class="afc-label" for="afc-reason">更正原因（必填，{{ REASON_MIN }}–{{ REASON_MAX }} 字）</label>
      <textarea id="afc-reason" v-model="form.reason" data-testid="afc-reason" class="afc-input" rows="2" :maxlength="REASON_MAX" :disabled="busy" />

      <div v-if="plan" class="afc-plan" role="status" data-testid="afc-plan">
        <p class="afc-plan-line afc-num">約定費用 ${{ money(plan.old_charge) }} → ${{ money(plan.new_charge) }}</p>
        <p v-if="plan.original_invoice_id" class="afc-p">
          帳單：原帳單 {{ invoiceRef(plan.original_invoice_id) }}（應繳 <span class="afc-num">${{ money(plan.original_outstanding) }}</span>）作廢，
          改開新未繳帳單（應繳 <span class="afc-num">${{ money(plan.new_outstanding) }}</span>）。
        </p>
        <p v-else class="afc-p">帳單：尚未開帳單，不會建立新帳單。</p>
        <p class="afc-p">{{ plan.message }}</p>
        <p class="afc-p afc-strong">不會建立任何收款、收據、退款或通知。</p>
      </div>
    </template>

    <p v-if="errorMessage" class="afc-error" role="alert" data-testid="afc-error">{{ errorMessage }}</p>

    <template #actions>
      <AtButton variant="ghost" shape="rect" :disabled="busy" @click="onClose">{{ result ? '關閉' : '取消' }}</AtButton>
      <template v-if="!result">
        <AtButton
          v-if="!plan"
          variant="primary"
          shape="rect"
          data-testid="afc-preview"
          :loading="busy"
          @click="runPreview"
        >預覽</AtButton>
        <AtButton
          v-else
          variant="primary"
          shape="rect"
          data-testid="afc-apply"
          :loading="busy"
          :disabled="formChangedSincePreview"
          @click="runApply"
        >確認更正</AtButton>
      </template>
    </template>
  </AtDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtButton from '../design-system/AtButton.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import {
  CHARGE_MAX,
  REASON_MAX,
  REASON_MIN,
  buildCorrectionBody,
  formatInvoiceRef,
  newIdempotencyKey,
  postAgreedFeeCorrection,
  requiresNewPreview,
  singleLiveUnpaidInvoiceId,
  validateCorrectionForm,
} from '../../lib/agreedFeeCorrection.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  course: { type: Object, default: null },
  invoices: { type: Array, default: () => [] },
  courseLabel: { type: String, default: '' },
  // Injected in tests; defaults to the project's authed fetch wrapper.
  fetchImpl: { type: Function, default: undefined },
});
const emit = defineEmits(['close', 'applied']);

const form = ref({ scope: 'billed', originalInvoiceId: null, newCharge: null, reason: '' });
const idempotencyKey = ref('');
const plan = ref(null);
const previewToken = ref('');
const previewedBody = ref(null);
const result = ref(null);
const errorMessage = ref('');
const busy = ref(false);

const currentCharge = computed(() => Number(props.course?.Charge ?? props.course?.charge ?? 0));
const detectedInvoiceId = computed(() => singleLiveUnpaidInvoiceId(props.invoices));

function invoiceRef(id) {
  return formatInvoiceRef(id, props.invoices);
}

function money(value) {
  return Number(value || 0).toLocaleString();
}

function effectiveForm() {
  const f = { ...form.value };
  if (f.scope === 'billed' && detectedInvoiceId.value) f.originalInvoiceId = detectedInvoiceId.value;
  return f;
}

const currentBody = computed(() => buildCorrectionBody(effectiveForm(), idempotencyKey.value));
const formChangedSincePreview = computed(() => !previewedBody.value
  || JSON.stringify(previewedBody.value) !== JSON.stringify(currentBody.value));

function clearPreview() {
  plan.value = null;
  previewToken.value = '';
  previewedBody.value = null;
}

watch(() => props.open, (isOpen) => {
  if (!isOpen) return;
  // One idempotency key per dialog session: preview and apply (and any retry) share it.
  idempotencyKey.value = newIdempotencyKey();
  form.value = { scope: 'billed', originalInvoiceId: null, newCharge: null, reason: '' };
  clearPreview();
  result.value = null;
  errorMessage.value = '';
  busy.value = false;
}, { immediate: true });

// Editing the form after a preview invalidates it; the operator must preview again.
watch(currentBody, () => {
  if (plan.value && formChangedSincePreview.value) clearPreview();
});

async function runPreview() {
  const invalid = validateCorrectionForm(effectiveForm());
  if (invalid) { errorMessage.value = invalid; return; }
  busy.value = true;
  errorMessage.value = '';
  const body = currentBody.value;
  try {
    const res = await postAgreedFeeCorrection(props.course?.id ?? props.course?.ID, 'preview', body, props.fetchImpl);
    if (!res.ok) {
      if (res.code === 'idempotency_conflict') idempotencyKey.value = newIdempotencyKey();
      errorMessage.value = res.message;
      return;
    }
    const { preview_token: token, ...rest } = res.data || {};
    plan.value = rest;
    previewToken.value = String(token || '');
    previewedBody.value = body;
  } catch (e) {
    errorMessage.value = e?.message || '預覽失敗，請稍後再試。';
  } finally {
    busy.value = false;
  }
}

async function runApply() {
  if (!plan.value || !previewToken.value || formChangedSincePreview.value) return;
  busy.value = true;
  errorMessage.value = '';
  try {
    const body = { ...previewedBody.value, preview_token: previewToken.value };
    const res = await postAgreedFeeCorrection(props.course?.id ?? props.course?.ID, 'apply', body, props.fetchImpl);
    if (!res.ok) {
      errorMessage.value = res.message;
      if (requiresNewPreview(res)) {
        if (res.code === 'idempotency_conflict') idempotencyKey.value = newIdempotencyKey();
        clearPreview();
      }
      return;
    }
    result.value = res.data;
    emit('applied', res.data);
  } catch (e) {
    // Network failure: keep the same key and token so a retry is replayed, not duplicated.
    errorMessage.value = e?.message || '更正失敗，請稍後再試。';
  } finally {
    busy.value = false;
  }
}

function onClose() {
  if (busy.value) return;
  emit('close');
}
</script>

<style scoped>
.afc-desc { margin: 0 0 var(--ds-space-3, 12px); color: var(--ds-ink-mute); font-size: 13px; }
.afc-fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: var(--ds-space-3, 12px); }
.afc-label { display: block; margin: var(--ds-space-3, 12px) 0 6px; font-size: 13px; font-weight: 700; color: var(--ds-ink); }
.afc-radio { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: var(--ds-ink); }
.afc-input { width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md, 8px); font-size: 14px; background: var(--ds-canvas); color: var(--ds-ink); }
.afc-hint { margin: 6px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.afc-num { font-variant-numeric: tabular-nums; }
.afc-plan { margin-top: var(--ds-space-4, 16px); padding: 10px 12px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md, 8px); background: var(--ds-canvas-soft); }
.afc-plan-line { margin: 0 0 6px; font-size: 16px; font-weight: 700; color: var(--ds-ink); }
.afc-p { margin: 4px 0 0; font-size: 13px; line-height: 1.5; }
.afc-strong { font-weight: 700; }
.afc-error { margin: var(--ds-space-3, 12px) 0 0; padding: 8px 10px; border-radius: var(--ds-radius-md, 8px); background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 13px; line-height: 1.5; }
</style>
