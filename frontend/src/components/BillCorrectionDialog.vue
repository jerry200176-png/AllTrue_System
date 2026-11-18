<script setup>
// 更正帳單 (step 2): preview the corrected bill in plain words, then apply it with the preview's state_hash.
// Count bills: the director types the new total. Monthly bills: the server prices the missed lessons.
import { computed, ref, watch } from 'vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { HQ_CODES, REFUSAL_COPY } from '../lib/billCorrectionCopy.js';
import { applyBillCorrection, previewBillCorrection } from '../lib/paymentActions.js';

const props = defineProps({ open: { type: Boolean, default: false }, invoiceId: { type: Number, default: null } });
const emit = defineEmits(['update:open', 'corrected', 'changed']);

// Refusals the director can recover from on this screen; any other refusal ends the flow (nothing left to press but 關閉).
const RETRYABLE = new Set(['state_changed', 'network', 'reason_short', 'fallback', 'new_total_required', 'unchanged_total', 'preview_failed', 'bad_request']);

const money = (n) => `NT$ ${Number(n || 0).toLocaleString('zh-TW')}`;

const loading = ref(false);
const plan = ref(null);
const isCount = ref(false);
const newTotal = ref('');
const reason = ref('');
const refusal = ref(null); // { code, text }
let seq = 0;

const dead = computed(() => !!refusal.value && !RETRYABLE.has(refusal.value.code));
const reasonOk = computed(() => reason.value.trim().length >= 2);
const totalOk = computed(() => /^\d+$/.test(String(newTotal.value)) && Number(newTotal.value) >= 1 && Number(newTotal.value) <= 999999);

// phase 'preview' never claims an unsure outcome: nothing was written yet. Only an apply can be unconfirmed.
function refuse(res, phase) {
  let code = res.code;
  if (res.status === 403 || res.data?.message === 'Forbidden') code = 'forbidden';
  else if (!code && res.status === 422) code = res.data?.errors?.reason ? 'reason_short' : 'bad_request';
  else if (!code || !Object.hasOwn(REFUSAL_COPY, code)) code = phase === 'preview' ? 'preview_failed' : 'fallback';
  else if (phase === 'preview' && (code === 'network' || code === 'fallback')) code = 'preview_failed';
  if (code === 'state_changed' || code === 'bad_request') plan.value = null; // the old figures are stale: show them again only after a new preview
  refusal.value = { code, text: REFUSAL_COPY[code] };
}

function askHq() {
  window.dispatchEvent(new CustomEvent('alltrue-open-bug-report', { detail: {
    description: `更正帳單時：${refusal.value.text}`,
    relatedReference: `帳單ID ${props.invoiceId}`,
  } }));
  emit('update:open', false);
}
const canRepreview = computed(() => ['state_changed', 'preview_failed', 'bad_request'].includes(refusal.value?.code));

async function preview() {
  const mine = ++seq;
  loading.value = true;
  plan.value = null;
  refusal.value = null;
  const asked = isCount.value && totalOk.value ? Number(newTotal.value) : null;
  const body = asked === null ? {} : { new_total: asked };
  const res = await previewBillCorrection(props.invoiceId, body);
  if (mine !== seq) return;
  loading.value = false;
  if (isCount.value && asked !== null && asked !== Number(newTotal.value)) return; // the total was edited while waiting: drop this answer
  if (res.ok) { plan.value = res.data; return; }
  // A count bill answers new_total_required until a total is typed: that is the cue to show the input, not a failure.
  if (res.code === 'new_total_required' && !isCount.value) { isCount.value = true; return; }
  refuse(res, 'preview');
}

async function apply() {
  if (!plan.value || !reasonOk.value || loading.value) return;
  const mine = ++seq;
  loading.value = true;
  refusal.value = null;
  const res = await applyBillCorrection(props.invoiceId, {
    state_hash: plan.value.state_hash,
    reason: reason.value.trim(),
    ...(plan.value.shape === 'count' ? { new_total: plan.value.new_total } : {}),
  });
  if (mine !== seq) return;
  loading.value = false;
  if (res.ok) {
    emit('corrected', res.data);
    emit('update:open', false);
    return;
  }
  refuse(res, 'apply');
  // The outcome may be unknown (lost response): have the parent reread the authoritative ledger.
  if (refusal.value.code === 'network' || refusal.value.code === 'fallback') emit('changed');
}

watch(() => [props.open, props.invoiceId], ([open]) => {
  seq++;
  plan.value = null;
  refusal.value = null;
  isCount.value = false;
  newTotal.value = '';
  reason.value = '';
  loading.value = false;
  if (open && props.invoiceId) preview();
}, { immediate: true });

watch(newTotal, () => { if (plan.value && Number(newTotal.value) !== plan.value.new_total) plan.value = null; });
</script>

<template>
  <Dialog :open="open" @update:open="(o) => { if (!loading) emit('update:open', o); }">
    <DialogContent class="tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto" data-testid="bill-correction-dialog">
      <DialogHeader>
        <DialogTitle>更正帳單</DialogTitle>
        <DialogDescription>先看更正後的結果，確認沒問題再送出。已經收的錢不會變，也不會自動退費或通知家長。</DialogDescription>
      </DialogHeader>

      <p v-if="loading && !plan" role="status" data-testid="bc-loading">正在計算更正後的帳單…</p>

      <Alert v-if="refusal" :tone="dead ? 'danger' : 'warning'" data-testid="bc-refusal">{{ refusal.text }}</Alert>

      <div v-if="!dead" class="tw:space-y-3">
        <label v-if="isCount" class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">新的帳單總額（元）
          <Input v-model="newTotal" :disabled="loading" type="number" inputmode="numeric" min="1" max="999999" data-testid="bc-new-total" />
        </label>
        <Button v-if="isCount && !plan" variant="outline" class="tw:min-h-11" :disabled="loading || !totalOk" data-testid="bc-preview" @click="preview">看更正後的結果</Button>

        <div v-if="plan" class="tw:space-y-2 tw:text-sm" data-testid="bc-plan">
          <p class="tw:text-base tw:font-semibold">這張帳單原本 {{ money(plan.old_total) }}，更正後 {{ money(plan.new_total) }}。</p>
          <p v-if="plan.moved_amount > 0">已收的 {{ money(plan.moved_amount) }} 會一起轉到新帳單，收到的錢不變。</p>
          <p v-if="plan.outstanding_after > 0">更正後還要再收 {{ money(plan.outstanding_after) }}。</p>
          <p v-else-if="plan.overpaid_after > 0">更正後多收了 {{ money(plan.overpaid_after) }}，不會自動退費，請之後在學生帳務處理。</p>
          <p v-else>更正後這張帳單剛好收齊。</p>
          <p v-if="plan.added_lessons?.length" data-testid="bc-lessons">多算進來的上課日：{{ plan.added_lessons.join('、') }}。</p>
          <Alert v-if="plan.range_shortfall > 0" tone="warning" title="請先核對" data-testid="bc-shortfall">
            帳單期間內有幾堂課是開完帳單後才加進來的，這次要補收 {{ money(plan.range_shortfall) }}。請先確認這些課真的該收費，再按「確認更正」。
          </Alert>
        </div>

        <label v-if="plan" class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">更正原因（必填，會留下紀錄）
          <Textarea v-model="reason" rows="2" maxlength="80" placeholder="例如：課程改成新的單價" data-testid="bc-reason" />
        </label>
      </div>

      <DialogFooter>
        <Button variant="outline" class="tw:min-h-11" :disabled="loading" @click="emit('update:open', false)">關閉</Button>
        <Button v-if="refusal && HQ_CODES.has(refusal.code)" variant="outline" class="tw:min-h-11" data-testid="bc-ask-hq" @click="askHq">回報給總部</Button>
        <Button v-if="canRepreview" class="tw:min-h-11" :disabled="loading" data-testid="bc-repreview" @click="preview">重新預覽</Button>
        <Button v-else-if="!dead && !canRepreview" class="tw:min-h-11" :disabled="!plan || !reasonOk || loading" data-testid="bc-apply" @click="apply">確認更正</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
