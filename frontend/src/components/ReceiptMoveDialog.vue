<script setup>
// 收據移到正確帳單 (#5113, in-app #522): pick the bill that should hold the receipt, look at both balances, give a reason, confirm.
// A bottom sheet on a phone, a dialog on a desktop. Every refusal is a plain sentence with a next step (lib/receiptMoveCopy.js).
import { computed, ref, watch } from 'vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { toastResult } from '@/components/app-overlay';
import { authedFetch } from '../lib/authedFetch.js';
import { applyReceiptMove, getReceiptMoveTargets, previewReceiptMove } from '../lib/paymentActions.js';
import { REASON_CHIPS, WARNING_COPY, refusalOf } from '../lib/receiptMoveCopy.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  reportId: { type: Number, default: null },
  studentId: { type: Number, default: null }, // the student the receipt is recorded on (the default pick)
  studentName: { type: String, default: '' },
  campusId: { type: Number, default: null },
  amount: { type: Number, default: 0 },
  fromLabel: { type: String, default: '' }, // plain words for the bill the receipt sits on now
});
const emit = defineEmits(['update:open', 'moved', 'changed']);

const money = (n) => `NT$ ${Number(n || 0).toLocaleString('zh-TW')}`;
const period = (p) => (p ? String(p).replace('-', '/') : '');
// Same-subject contracts must look different: add the contract start and the bill's issue date.
const sideLabel = (t) => [t.student_name, targetLabel(t)].filter(Boolean).join(' · ');
const targetLabel = (t) => [t.subject || '課程', period(t.billing_period), t.start_date && `${t.start_date} 開始`, t.issue_date && `${t.issue_date} 開立`].filter(Boolean).join(' · ');

const pickedStudent = ref({ id: null, name: '' });
const targets = ref(null); // null = loading
const source = ref(null); // where the money sits, named by the server from the bill that holds the payment
const chosen = ref(null);
const plan = ref(null);
const reason = ref('');
const busy = ref(false); // preview or apply in flight
const refusal = ref(null); // { code, kind, text }
const finding = ref(false); // the 換一位學生 search box is open
const query = ref('');
const found = ref(null);
const searching = ref(false);
let seq = 0;

const dead = computed(() => ['hq', 'end'].includes(refusal.value?.kind));
const reasonOk = computed(() => reason.value.trim().length >= 2);
const loadingTargets = computed(() => targets.value === null && !refusal.value);

async function loadTargets(studentId, keep = null) {
  const mine = ++seq;
  targets.value = null;
  refusal.value = keep; // a refusal that sent us back to the list stays on screen above it
  chosen.value = null;
  plan.value = null;
  const res = await getReceiptMoveTargets(props.reportId, studentId);
  if (mine !== seq) return;
  if (res.ok) {
    targets.value = res.data.targets || [];
    source.value = res.data.source || null;
    return;
  }
  targets.value = [];
  refusal.value = refusalOf(res, 'targets');
}

async function choose(t) {
  if (busy.value) return;
  chosen.value = t;
  await runPreview();
}

async function runPreview() {
  const mine = ++seq;
  busy.value = true;
  refusal.value = null;
  plan.value = null;
  const res = await previewReceiptMove(props.reportId, chosen.value.invoice_id);
  if (mine !== seq) return;
  busy.value = false;
  if (res.ok) {
    plan.value = res.data;
    return;
  }
  refusal.value = refusalOf(res, 'preview');
  if (refusal.value.kind === 'pick') await loadTargets(pickedStudent.value.id, refusal.value); // the list was stale: show it again
}

async function apply() {
  if (!plan.value || !reasonOk.value || busy.value) return;
  const mine = ++seq;
  busy.value = true;
  refusal.value = null;
  const res = await applyReceiptMove(props.reportId, {
    target_invoice_id: chosen.value.invoice_id,
    state_hash: plan.value.state_hash,
    reason: reason.value.trim(),
  });
  if (mine !== seq) return;
  busy.value = false;
  if (res.ok) {
    toastResult('已移到正確帳單，已開立新收據。如果移錯，可以在新收據再移一次。');
    emit('moved', res.data);
    emit('update:open', false);
    return;
  }
  refusal.value = refusalOf(res, 'apply');
  if (['reassign_state_changed', 'reassign_state_hash_required'].includes(refusal.value.code)) plan.value = null; // the old figures are stale
  // The outcome may be unknown (lost response): have the parent reread the authoritative ledger.
  if (['network', 'fallback'].includes(refusal.value.code)) emit('changed');
  if (refusal.value.kind === 'pick') await loadTargets(pickedStudent.value.id, refusal.value);
}

async function search() {
  const q = query.value.trim();
  if (!q || !props.campusId) return;
  searching.value = true;
  try {
    const resp = await authedFetch(`/api/v1/students?campus_id=${Number(props.campusId)}&per_page=8&name=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
    const data = await resp.json().catch(() => ({}));
    found.value = resp.ok ? (data.data || data || []).filter((s) => s && s.id) : [];
  } catch {
    found.value = [];
  } finally {
    searching.value = false;
  }
}

function pickStudent(s) {
  pickedStudent.value = { id: Number(s.id), name: s.name };
  finding.value = false;
  found.value = null;
  query.value = '';
  loadTargets(pickedStudent.value.id);
}

function backToPick() {
  seq++;
  busy.value = false;
  plan.value = null;
  chosen.value = null;
  refusal.value = null;
}

function askHq() {
  window.dispatchEvent(new CustomEvent('alltrue-open-bug-report', { detail: {
    description: `移動收據時：${refusal.value.text}`,
    relatedReference: `收據ID ${props.reportId}`,
  } }));
  emit('update:open', false);
}

watch(() => [props.open, props.reportId], ([open]) => {
  seq++;
  plan.value = null;
  chosen.value = null;
  refusal.value = null;
  reason.value = '';
  busy.value = false;
  finding.value = false;
  found.value = null;
  query.value = '';
  targets.value = null;
  pickedStudent.value = { id: props.studentId, name: props.studentName };
  if (open && props.reportId) loadTargets(null);
}, { immediate: true });
</script>

<template>
  <Dialog :open="open" @update:open="(o) => { if (!busy) emit('update:open', o); }">
    <DialogContent
      class="tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto tw:max-sm:top-auto tw:max-sm:bottom-0 tw:max-sm:left-0 tw:max-sm:max-w-full tw:max-sm:translate-x-0 tw:max-sm:translate-y-0 tw:max-sm:rounded-b-none"
      data-testid="receipt-move-dialog"
    >
      <DialogHeader>
        <DialogTitle>移到正確帳單</DialogTitle>
        <DialogDescription>這張收據記在別人的帳單上。可以直接移到正確的帳單，金額不變，舊收據會作廢並留下紀錄。</DialogDescription>
      </DialogHeader>

      <p class="tw:text-sm" data-testid="rm-source">這筆 <strong class="tw:tabular-nums">{{ money(amount) }}</strong> 現在記在：{{ (source ? sideLabel(source) : fromLabel) || '原帳單' }}</p>

      <Alert v-if="refusal" :tone="dead ? 'danger' : 'warning'" data-testid="rm-refusal">{{ refusal.text }}</Alert>

      <!-- Step 1: pick the bill -->
      <div v-if="!plan && !dead && !(busy && chosen)" class="tw:space-y-3" data-testid="rm-pick">
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:text-sm">
          <span>要移到：<strong>{{ pickedStudent.name || '這位學生' }}</strong> 的帳單</span>
          <Button variant="outline" class="tw:min-h-11" data-testid="rm-change-student" @click="finding = !finding">換一位學生</Button>
        </div>
        <form v-if="finding" class="tw:space-y-2" data-testid="rm-find" @submit.prevent="search">
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">學生姓名（同一分校）
            <Input v-model="query" class="tw:min-h-11" placeholder="輸入姓名" data-testid="rm-query" />
          </label>
          <Button type="submit" variant="outline" class="tw:min-h-11" :disabled="searching || !query.trim()" data-testid="rm-search">{{ searching ? '搜尋中…' : '搜尋' }}</Button>
          <p v-if="found && !found.length" class="tw:text-sm tw:text-muted-foreground" data-testid="rm-find-empty">這個分校找不到這位學生。請確認姓名，或只輸入姓。</p>
          <ul v-if="found?.length" class="tw:space-y-2">
            <li v-for="s in found" :key="s.id">
              <Button variant="outline" class="tw:min-h-11 tw:w-full tw:justify-start" :data-testid="`rm-student-${s.id}`" @click="pickStudent(s)">{{ s.name }}</Button>
            </li>
          </ul>
        </form>

        <div v-if="loadingTargets" class="tw:space-y-2" role="status" data-testid="rm-loading">
          <p class="tw:text-sm">正在讀取可以移過去的帳單…</p>
          <Skeleton class="tw:h-14 tw:w-full" />
          <Skeleton class="tw:h-14 tw:w-full" />
        </div>
        <p v-else-if="targets && !targets.length && !refusal" class="tw:text-sm tw:text-muted-foreground" data-testid="rm-empty">
          {{ pickedStudent.name || '這位學生' }}沒有可以收這筆款的帳單。請先確認帳單已經開立、金額夠收這筆款，或按「換一位學生」。
        </p>
        <ul v-else-if="targets?.length" class="tw:space-y-2">
          <li v-for="t in targets" :key="t.invoice_id">
            <button
              type="button"
              class="tw:flex tw:min-h-12 tw:w-full tw:items-center tw:justify-between tw:gap-2 tw:rounded-lg tw:border tw:bg-background tw:p-3 tw:text-left tw:text-sm tw:hover:bg-accent"
              :disabled="busy"
              :data-testid="`rm-target-${t.invoice_id}`"
              @click="choose(t)"
            >
              <span>{{ targetLabel(t) }}</span>
              <span class="tw:tabular-nums tw:text-muted-foreground">還差 {{ money(t.remaining) }}</span>
            </button>
          </li>
        </ul>
      </div>

      <p v-if="busy && chosen && !plan" role="status" data-testid="rm-previewing">正在計算移動後的結果…</p>

      <!-- Step 2: review, reason, confirm -->
      <div v-if="plan" class="tw:space-y-3 tw:text-sm" data-testid="rm-plan">
        <p class="tw:text-base tw:font-semibold">把這筆 {{ money(plan.amount) }} 移到：{{ plan.to.student_name || pickedStudent.name }} · {{ targetLabel(plan.to.subject ? plan.to : chosen) }}</p>
        <ul class="tw:space-y-1 tw:tabular-nums">
          <li data-testid="rm-from-label">原帳單：{{ sideLabel(plan.from) }}</li>
          <li data-testid="rm-from-balance">原帳單：還差 {{ money(plan.from.balance_before) }} → {{ money(plan.from.balance_after) }}</li>
          <li data-testid="rm-to-balance">正確帳單：還差 {{ money(plan.to.balance_before) }} → {{ money(plan.to.balance_after) }}</li>
        </ul>
        <p v-for="w in plan.warnings || []" :key="w" data-testid="rm-warning">{{ WARNING_COPY[w] }}</p>
        <p>舊收據會作廢並留下紀錄，並開立新收據。</p>
        <p v-if="plan.needs_role === 'super_admin'" data-testid="rm-hq-note">這筆超過主任可自行處理的範圍，現在是用總部帳號處理。</p>
        <div class="tw:space-y-2">
          <p class="tw:font-medium">為什麼要移？（必填，會留下紀錄）</p>
          <div class="tw:flex tw:flex-wrap tw:gap-2">
            <Button v-for="c in REASON_CHIPS" :key="c" variant="outline" class="tw:min-h-11" :data-testid="`rm-chip-${c}`" @click="reason = c === '其他' ? reason : c">{{ c }}</Button>
          </div>
          <Textarea v-model="reason" rows="2" maxlength="500" placeholder="例如：選錯學生，應該是弟弟的帳單" aria-label="移動原因" data-testid="rm-reason" />
        </div>
      </div>

      <DialogFooter class="tw:gap-2">
        <Button variant="outline" class="tw:min-h-11" :disabled="busy" data-testid="rm-close" @click="emit('update:open', false)">關閉</Button>
        <Button v-if="refusal?.kind === 'hq'" variant="outline" class="tw:min-h-11" data-testid="rm-ask-hq" @click="askHq">回報給總部</Button>
        <Button v-if="refusal?.kind === 'reload'" class="tw:min-h-11" data-testid="rm-reload" @click="loadTargets(pickedStudent.id)">重新讀取</Button>
        <Button v-if="refusal?.kind === 'preview' && chosen" class="tw:min-h-11" :disabled="busy" data-testid="rm-repreview" @click="runPreview">重新預覽</Button>
        <Button v-if="chosen && !dead" variant="outline" class="tw:min-h-11" :disabled="busy" data-testid="rm-back" @click="backToPick">重選帳單</Button>
        <Button v-if="plan && !dead" class="tw:min-h-11" :disabled="!reasonOk || busy" :aria-busy="busy || undefined" data-testid="rm-apply" @click="apply">確認移動</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
