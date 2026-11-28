<template>
  <SectionCard title="智慧登帳引導" class="tw:mb-4" data-testid="billing-guide">
    <template #actions>
      <span v-if="bill" class="tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums" data-testid="bg-progress">第 {{ position }} 筆／共 {{ total }} 筆</span>
      <Button variant="outline" size="sm" class="tw:min-h-11" data-testid="bg-exit" @click="$emit('close')">離開引導</Button>
    </template>
    <Progress :model-value="total ? (done.size / total) * 100 : 100" class="tw:mx-5 tw:w-auto" aria-label="引導進度" />

    <div v-if="!bill" class="tw:grid tw:gap-3 tw:p-5" data-testid="bg-finished">
      <p class="tw:m-0 tw:text-tpl-list tw:font-bold">都看過了，沒有下一筆。</p>
      <p class="tw:m-0 tw:text-tpl-secondary">沒有登記收款的帳單仍然是「未繳」，會留在帳單清單。</p>
    </div>

    <div v-else class="tw:grid tw:gap-4 tw:p-5">
      <header class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
        <h4 class="tw:m-0 tw:text-tpl-list tw:font-bold" data-testid="bg-title">{{ facts.student || NO_DATA }}・{{ facts.course || NO_DATA }}</h4>
        <Badge :variant="bill.kind === 'owing' && !confirmedId && !gone ? 'destructive' : 'secondary'" data-testid="bg-state">{{ stageLabel }}</Badge>
      </header>

      <dl class="tw:m-0 tw:grid tw:grid-cols-1 tw:gap-x-6 tw:gap-y-2 tw:sm:grid-cols-2" data-testid="bg-facts">
        <div v-for="f in factRows" :key="f.label" class="tw:flex tw:justify-between tw:gap-3 tw:border-b tw:border-border tw:py-1.5">
          <dt class="tw:text-tpl-secondary">{{ f.label }}</dt>
          <dd class="tw:m-0 tw:text-right tw:font-medium tw:tabular-nums" :class="{ 'tw:text-tpl-secondary tw:font-normal': f.missing }">{{ f.value }}</dd>
        </div>
      </dl>

      <dl class="tw:m-0 tw:grid tw:grid-cols-3 tw:gap-2" data-testid="bg-money">
        <div v-for="m in moneyRows" :key="m.label" class="tw:rounded-lg tw:border tw:border-border tw:px-3 tw:py-2">
          <dt class="tw:text-tpl-caption tw:text-tpl-secondary">{{ m.label }}</dt>
          <dd class="tw:m-0 tw:font-bold tw:tabular-nums" :class="{ 'tw:text-destructive': m.due }">{{ m.value }}</dd>
        </div>
      </dl>

      <ul v-if="bill.warnings?.length" class="tw:m-0 tw:grid tw:gap-1 tw:pl-5 tw:text-tpl-secondary" data-testid="bg-warnings">
        <li v-for="w in bill.warnings" :key="w">{{ w }}</li>
      </ul>

      <section v-if="report && !confirmedId" class="tw:grid tw:gap-1 tw:rounded-lg tw:border tw:border-border tw:px-3 tw:py-2" aria-label="付款回報" data-testid="bg-report">
        <h5 class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">付款回報（還沒確認入帳）</h5>
        <p class="tw:m-0"><strong>{{ money(report.amount) }}</strong>・{{ report.payment_date ? String(report.payment_date).slice(0, 10) : '沒有日期' }}・{{ paymentMethodLabel(report.payment_method) }}<template v-if="report.account_last5">・後5碼 {{ report.account_last5 }}</template></p>
        <p v-if="report.note" class="tw:m-0 tw:text-tpl-secondary">備註：{{ report.note }}</p>
      </section>

      <details v-if="bill.class_id != null" :key="currentKey" class="tw:text-tpl-secondary" data-testid="bg-lessons" @toggle="onLessons">
        <summary class="tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center">上課明細</summary>
        <p v-if="lessonsState === 'loading'" class="tw:m-0">載入中…</p>
        <p v-else-if="lessonsState === 'error'" class="tw:m-0">上課明細載入失敗。</p>
        <p v-else-if="lessonsState === 'ready' && !lessons.length" class="tw:m-0">{{ NO_DATA }}</p>
        <ol v-else class="tw:m-0 tw:pl-5">
          <li v-for="s in lessons" :key="s.class_session_id">{{ formatSessionDate(s.date) }} {{ s.start_time || '' }} {{ STATUS_ZH[s.status] || '' }}</li>
        </ol>
      </details>

      <p v-if="notice" class="tw:m-0 tw:font-medium" role="status" data-testid="bg-notice">{{ notice }}</p>
      <p v-if="reloadFailed" class="tw:m-0 tw:text-destructive" role="alert" data-testid="bg-reload-failed">重新載入失敗，畫面可能不是最新的。請按右上角的重新整理。</p>
      <p v-if="actionError" class="tw:m-0 tw:text-destructive" role="alert" data-testid="bg-error">{{ actionError }}</p>

      <!-- One question per bill: only what the system cannot know by itself. -->
      <section class="tw:grid tw:gap-3 tw:rounded-lg tw:bg-secondary tw:p-4" data-testid="bg-step">
        <template v-if="confirmedId">
          <p class="tw:m-0 tw:font-bold">已確認入帳。<template v-if="receipt">本次實收 {{ money(receipt.content_snapshot.total_amount) }}。</template></p>
          <p v-if="facts.outstanding > 0" class="tw:m-0 tw:text-tpl-secondary" data-testid="bg-remaining">這張帳單還有未繳 {{ money(facts.outstanding) }}，會留在帳單清單。</p>
          <div v-if="receipt" class="tw:flex tw:flex-wrap tw:gap-2">
            <Button class="tw:min-h-11" data-testid="bg-doc-copy" @click="copyDocText">{{ copyLabel('複製收據文字') }}</Button>
            <Button variant="outline" class="tw:min-h-11" data-testid="bg-doc-preview" @click="previewOpen = !previewOpen">{{ previewOpen ? '收起預覽' : '預覽' }}</Button>
            <Button variant="outline" class="tw:min-h-11" :disabled="exporting" data-testid="bg-doc-download" @click="downloadDoc">下載收據</Button>
          </div>
          <Button v-else variant="outline" class="tw:min-h-11 tw:justify-self-start" :disabled="busy" data-testid="bg-receipt-retry" @click="openReceipt(confirmedId)">重新載入收據</Button>
        </template>

        <template v-else-if="gone">
          <p class="tw:m-0 tw:font-bold" data-testid="bg-gone">這筆現在不在「要處理」裡了（可能已經處理，或狀態變了）。要確認請回帳單清單看。</p>
        </template>

        <template v-else-if="bill.class_id == null">
          <p class="tw:m-0 tw:font-bold">這是共用方案，帳單和收款在各門成員課上，請到學生帳務處理。</p>
          <p v-if="bill.src?.schedule_mode === 'date' && Number(bill.src?.monthly_sessions) === 0" class="tw:m-0 tw:text-tpl-secondary" data-testid="bg-plan-no-lessons">預估 NT$ 0，是因為這個月還沒有點名紀錄。</p>
          <Button class="tw:min-h-11 tw:justify-self-start" data-testid="bg-open-ledger" @click="$emit('ledger', bill, null)">到學生帳務</Button>
        </template>

        <template v-else-if="bill.kind === 'confirm'">
          <p class="tw:m-0 tw:font-bold">{{ report ? `這筆 ${money(report.amount)} 收到了嗎？` : '這筆有回報等確認入帳。' }}</p>
          <p class="tw:m-0 tw:text-tpl-secondary">確認入帳後才算「已收」，才有收據。</p>
          <div class="tw:flex tw:flex-wrap tw:gap-2">
            <Button class="tw:min-h-11" :disabled="busy || !report" data-testid="bg-confirm" @click="confirmNow">{{ recordedId ? '現在確認入帳' : '收到了，確認入帳' }}</Button>
            <Button variant="outline" class="tw:min-h-11" :disabled="busy" data-testid="bg-reject" @click="$emit('reject', bill)">沒收到，退回</Button>
          </div>
        </template>

        <template v-else-if="bill.kind === 'owing'">
          <p class="tw:m-0 tw:font-bold">家長繳了嗎？</p>
          <p class="tw:m-0 tw:text-tpl-secondary">還沒繳就不用做任何事，這筆維持「未繳」。</p>
          <Button class="tw:min-h-11 tw:justify-self-start" data-testid="bg-record" @click="entryOpen = true">繳了，登記收款</Button>
        </template>

        <template v-else-if="renewal">
          <p class="tw:m-0 tw:font-bold">{{ bill.label }}：這期已收，堂數快用完了，要不要續課？</p>
          <div v-if="canDocs" class="tw:flex tw:flex-wrap tw:gap-2">
            <Button class="tw:min-h-11" data-testid="bg-renew" @click="$emit('renew', bill)">去續課</Button>
            <Button v-if="rowActions[bill.class_id]?.settle" variant="outline" class="tw:min-h-11" @click="$emit('settle', bill)">結案（不續報）</Button>
          </div>
        </template>

        <template v-else-if="bill.kind === 'issue'">
          <p class="tw:m-0 tw:font-bold">{{ bill.label }}：還沒開帳單，所以還不能收款。</p>
          <Button class="tw:min-h-11 tw:justify-self-start" data-testid="bg-issue" @click="$emit('ledger', bill, 'issue')">去開帳單</Button>
        </template>

        <template v-else>
          <p class="tw:m-0 tw:font-bold">{{ bill.label }}：{{ bill.help || '金額或期間對不上，先核對再收款。' }}</p>
          <Button class="tw:min-h-11 tw:justify-self-start" data-testid="bg-review" @click="$emit('ledger', bill, null)">去核對</Button>
        </template>

        <!-- 繳費單: right here for a bill that is still 未繳; otherwise the reason and the entry above. -->
        <template v-if="!confirmedId && !gone && bill.kind !== 'confirm'">
          <div v-if="slipDoc" class="tw:flex tw:flex-wrap tw:gap-2" data-testid="bg-slip">
            <Button variant="outline" class="tw:min-h-11" data-testid="bg-doc-copy" @click="copyDocText">{{ copyLabel('複製繳費單文字') }}</Button>
            <Button variant="outline" class="tw:min-h-11" data-testid="bg-doc-preview" @click="previewOpen = !previewOpen">{{ previewOpen ? '收起預覽' : '預覽' }}</Button>
            <Button variant="outline" class="tw:min-h-11" :disabled="exporting" data-testid="bg-doc-download" @click="downloadDoc">下載繳費單</Button>
          </div>
          <p v-else-if="slipReason" class="tw:m-0 tw:text-tpl-secondary" data-testid="bg-slip-reason">現在不能給繳費單：{{ slipReason }}。</p>
        </template>
      </section>

      <div v-if="shownDoc && previewOpen" class="tw:overflow-x-auto" data-testid="bg-doc">
        <BillingDocument ref="docRef" :doc="shownDoc" />
      </div>

      <footer class="tw:flex tw:flex-wrap tw:justify-end tw:gap-2">
        <template v-if="!confirmedId && !gone">
          <!-- in-app #606: an ended contract that will not be collected. Same rule and dialog as the bill list's 改成不收. -->
          <p v-if="rowActions[bill.class_id]?.waive && rowActions[bill.class_id].waiveBlock" class="tw:m-0 tw:mr-auto tw:self-center tw:text-tpl-secondary" data-testid="bg-waive-block">現在不能改成不收：{{ rowActions[bill.class_id].waiveBlock }}</p>
          <Button v-else-if="rowActions[bill.class_id]?.waive" variant="outline" class="tw:min-h-11 tw:mr-auto" :disabled="busy || exporting" data-testid="bg-waive" @click="$emit('waive', bill)">改成不收</Button>
          <Button variant="ghost" class="tw:min-h-11" :disabled="busy || exporting" data-testid="bg-later" @click="advance('later')">稍後處理</Button>
          <Button variant="ghost" class="tw:min-h-11" :disabled="busy || exporting" data-testid="bg-skip" @click="advance('done')">略過</Button>
        </template>
        <Button :variant="confirmedId || gone ? 'default' : 'outline'" class="tw:min-h-11" :disabled="busy || exporting" data-testid="bg-next" @click="advance('done')">處理下一筆</Button>
      </footer>
    </div>

    <PaymentEntryModal :show="entryOpen" :row="entryRow" placement="right" @close="entryOpen = false" @confirmed="onRecorded" @conflict="onConflict" />
  </SectionCard>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { SectionCard } from '@/components/ui/page';
import BillingDocument from '../BillingDocument.vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';
import { askConfirm } from '../../composables/useConfirmDialog';
import { authedFetch } from '../../lib/authedFetch.js';
import { TODO_KINDS, isPaidCountRenewal } from '../../lib/billRows.js';
import { NO_DATA, guideFacts, guideQueue, nextBill } from '../../lib/billingGuide.js';
import { slipAvailability } from '../../lib/billingDocState.js';
import { STATUS_ZH, formatSessionDate, paymentMethodLabel, paymentSlipView, receiptView, slipPlainText } from '../../lib/billingDocumentView.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import { confirmReport } from '../../lib/paymentActions.js';
import { adaptPaymentReportReceipt, buildReceiptCopyText, paymentReportReceiptUrl } from '../../lib/paymentReportReceipt.js';
import { receiptImageBlob } from '../../lib/receiptImage.js';
import { loadSlipData } from '../../lib/slipData.js';

// 智慧登帳引導: the bill list's 要處理 rows, one at a time. It only asks what the system cannot know (was it paid?), and
// every write is an existing one: 登記 = PaymentEntryModal, 確認入帳 = confirmReport. A bill nobody paid stays 未繳.
const props = defineProps({
  bills: { type: Array, default: () => [] }, // billRows() of the page's /alerts/tuition rows
  branchId: { type: [Number, String], default: null },
  canDocs: { type: Boolean, default: false },
  rowActions: { type: Object, default: () => ({}) },
  reload: { type: Function, default: async () => true }, // the page rereads /alerts/tuition; false = it failed
});
defineEmits(['close', 'ledger', 'reject', 'renew', 'settle', 'waive']);

const money = (v) => `NT$ ${Number(v || 0).toLocaleString('zh-TW')}`;
const done = reactive(new Set());
const later = ref([]);
const queue = computed(() => guideQueue(props.bills));
const currentKey = ref(nextBill(queue.value)?.key ?? null);
const snapshot = ref(null);
const live = computed(() => props.bills.find((b) => b.key === currentKey.value) || null);
// From 確認入帳 on, the screen stays on the bill that was paid: after a full payment the server's row moves on to the
// contract's next unpaid bill, and that bill's amounts must not appear beside this payment's receipt.
const frozen = ref(false);
const paidInvoiceId = ref(null);
watch(live, (b) => { if (b && !frozen.value) snapshot.value = b; }, { immediate: true });
const bill = computed(() => (currentKey.value == null ? null : (!frozen.value && live.value) || snapshot.value));
const total = computed(() => done.size + queue.value.filter((b) => !done.has(b.key)).length);
const position = computed(() => Math.min(done.size + 1, total.value));
// Someone else (or another tab) already handled it: the server no longer lists it as something to do.
const gone = computed(() => !frozen.value && (!live.value || !TODO_KINDS.includes(live.value.kind)));

// ── This bill's ledger: its invoice amounts and the pending parent/office report ──
const ledger = ref(null);
const busy = ref(false);
const notice = ref('');
const reloadFailed = ref(false);
const actionError = ref('');
const recordedId = ref(null);
const confirmedId = ref(null);
const receipt = ref(null);
const entryOpen = ref(false);
const lessons = ref([]);
const lessonsState = ref('idle');
const slipRaw = ref(null);
const slipError = ref('');
const previewOpen = ref(false);
const exporting = ref(false);
const copied = ref('');
const docRef = ref(null);
let seq = 0;

const classId = computed(() => Number(bill.value?.class_id || 0));
const memberIds = computed(() => {
  const courses = ledger.value?.courses || [];
  const pkg = courses.find((c) => Number(c.id) === classId.value)?.package_id;
  return pkg ? courses.filter((c) => c.package_id === pkg).map((c) => Number(c.id)) : [classId.value];
});
const report = computed(() => (ledger.value?.receipts || []).find((r) => r.status === 'pending' && memberIds.value.includes(Number(r.student_class_id))) || null);
const invoice = computed(() => {
  const id = Number((frozen.value ? paidInvoiceId.value : bill.value?.src?.payable_invoice_id) || 0);
  return (id && (ledger.value?.invoices || []).find((i) => Number(i.id) === id && i.status !== 'void')) || null;
});
const facts = computed(() => {
  const f = guideFacts(bill.value, invoice.value);
  if (!frozen.value) return f;
  // After a payment only the reread ledger knows that bill (confirming may even have issued it): show its numbers,
  // or say there are none, never the ones from before the payment.
  const i = invoice.value;
  if (!i) return { ...f, receivable: null, received: null, outstanding: null, estimate: null };
  return { ...f, billed: true, estimate: null, bill_period: i.period_start || i.period_end ? `${String(i.period_start || '—').slice(0, 10)} ~ ${String(i.period_end || '—').slice(0, 10)}` : f.bill_period,
    receivable: Number(i.total_amount), received: Number(i.calculated_applied_amount), outstanding: Number(i.outstanding_amount) };
});
const renewal = computed(() => Boolean(bill.value) && isPaidCountRenewal(bill.value));
const entryRow = computed(() => (bill.value ? { ...bill.value.src, id: bill.value.class_id } : null));
const stageLabel = computed(() => (confirmedId.value ? '已確認入帳' : gone.value ? '不在要處理' : bill.value.label));

const cell = (label, value, fallback = NO_DATA) => ({ label, value: value ?? fallback, missing: value == null });
const factRows = computed(() => {
  const f = facts.value;
  return [
    cell(f.is_package ? '方案' : '課程', f.course),
    cell('計費方式', f.mode),
    cell('課程期間', f.course_period),
    cell('本次帳單計費期間', f.bill_period, f.billed ? NO_DATA : '還沒開帳單'),
    cell('繳費期限', f.due_date),
  ];
});
const moneyRows = computed(() => {
  const f = facts.value;
  const amount = (v) => (v == null ? NO_DATA : money(v));
  if (f.amount_pending) return [{ label: '應收', value: '金額需核對' }, { label: '已收', value: amount(f.received) }, { label: '未繳', value: '金額需核對' }];
  if (!f.billed) return [{ label: '預估', value: amount(f.estimate) }, { label: '已收', value: amount(f.received) }, { label: '未繳', value: '還沒開帳單' }];
  return [{ label: '應收', value: amount(f.receivable) }, { label: '已收', value: amount(f.received) }, { label: '未繳', value: amount(f.outstanding), due: f.outstanding > 0 }];
});

async function loadLedger() {
  const mine = ++seq;
  if (!classId.value) { ledger.value = null; return; }
  try {
    const params = new URLSearchParams({ student_class_id: String(classId.value) });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    const resp = await authedFetch(`/api/v1/accounting/ledger?${params}`, { headers: { Accept: 'application/json' } });
    const json = await resp.json().catch(() => ({}));
    if (mine !== seq) return;
    if (!resp.ok) throw new Error(json.message || `載入失敗（${resp.status}）`);
    ledger.value = json;
  } catch (e) {
    if (mine === seq) { ledger.value = null; actionError.value = humanizeApiErrorMessage(e.message || '載入失敗'); }
  }
}

// ── 繳費單: the slip of THIS bill (its invoice, else its contract), only when the shared rule allows one ──
const slip = computed(() => (bill.value ? slipAvailability({ ...bill.value.src, id: bill.value.class_id }, props.canDocs) : { ok: false, reason: '' }));
const slipDoc = computed(() => (slipRaw.value ? paymentSlipView(slipRaw.value) : null));
const slipReason = computed(() => (slip.value.ok ? slipError.value : slip.value.reason));
async function loadSlip() {
  const mine = seq;
  slipRaw.value = null; slipError.value = '';
  if (!slip.value.ok || bill.value.kind === 'confirm') return;
  const invoiceId = Number(bill.value.src.payable_invoice_id) || null;
  try {
    const raw = await loadSlipData({ invoiceId, studentClassId: invoiceId ? null : bill.value.class_id });
    if (mine === seq) slipRaw.value = raw;
  } catch (e) {
    if (mine === seq) slipError.value = e.message || '繳費單載入失敗';
  }
}

async function showBill() {
  ledger.value = null; lessons.value = []; lessonsState.value = 'idle';
  notice.value = ''; actionError.value = ''; recordedId.value = null; confirmedId.value = null; receipt.value = null;
  frozen.value = false; paidInvoiceId.value = null; reloadFailed.value = false;
  previewOpen.value = false; copied.value = '';
  slipRaw.value = null; slipError.value = ''; // now, not after the ledger answers: never the previous bill's slip
  if (!bill.value) return;
  await loadLedger();
  await loadSlip();
}
watch(currentKey, showBill, { immediate: true });
// The page reread the rows behind the guide (its 重新整理, the drawer, the 退回 dialog): read this bill's ledger and slip
// again. The guide's own rereads (refresh) do that themselves.
let refreshing = false;
watch(() => live.value?.src, (src, was) => { if (src && was && !refreshing && !frozen.value) loadLedger().then(loadSlip); });

async function refresh() {
  // The list (and its error line) is hidden behind the guide, so a failed reread is said here.
  refreshing = true;
  try { reloadFailed.value = await props.reload() === false; } finally { refreshing = false; }
  await loadLedger();
  if (!confirmedId.value) await loadSlip();
}

function advance(how) {
  const key = currentKey.value;
  if (how === 'later') later.value = [...later.value.filter((k) => k !== key), key];
  else done.add(key);
  snapshot.value = null;
  frozen.value = false; // before the key changes, so the next bill is tracked from its first render
  currentKey.value = nextBill(queue.value, { done, later: later.value })?.key ?? null;
  if (currentKey.value === key) showBill(); // the only one left came round again
}

// ── 登記 (existing form) → 現在確認入帳 → 收據 ──
async function onRecorded(result) {
  entryOpen.value = false;
  recordedId.value = Number(result?.report_id) || null;
  // 免費／贈送 needs no confirmation and has no report: repeat what the server said.
  notice.value = recordedId.value ? '已登記，還沒確認入帳。確認入帳後才算已收。' : (result?.message || '已登記。');
  await refresh();
}
async function onConflict(data) {
  // A second 登記 for a course that already has a pending report is refused by the server: carry on with that report.
  if (data?.report_id) {
    entryOpen.value = false;
    recordedId.value = Number(data.report_id);
    notice.value = '這門課已經有一筆待確認入帳的回報，沒有重複登記。';
  }
  await refresh();
}

async function confirmNow() {
  const r = report.value;
  if (!r || busy.value) return;
  if (!await askConfirm({ title: '確認入帳？', message: `確認收到 ${money(r.amount)}？確認後算「已收」。金額不對請取消，改按「退回」。`, confirmLabel: '確認入帳' })) return;
  if (busy.value) return;
  busy.value = true; actionError.value = ''; notice.value = '';
  const id = Number(r.report_id);
  snapshot.value = bill.value;
  frozen.value = true; // keep the bill being paid on screen through the reread
  const res = await confirmReport(id);
  await refresh();
  // A resend of a report that was already confirmed is refused by the server; the ledger then shows it confirmed, so the
  // director gets that payment's receipt instead of an error (and no second payment).
  const confirmed = res.ok || (ledger.value?.receipts || []).some((x) => Number(x.report_id) === id && x.status === 'confirmed');
  busy.value = false;
  if (!confirmed) {
    frozen.value = false;
    if (live.value) snapshot.value = live.value;
    actionError.value = res.message;
    await loadSlip(); // the reread ran while frozen: the bill may be 未繳 again (its report was 退回 elsewhere)
    return;
  }
  paidInvoiceId.value = (res.ok ? res.data?.invoice_id : null) ?? (ledger.value?.receipts || []).find((x) => Number(x.report_id) === id)?.invoice_id ?? null;
  confirmedId.value = id;
  await openReceipt(id);
}

/** Read-only: the receipt of that one confirmed report. Reading it again never creates a payment or a receipt. */
async function openReceipt(id) {
  busy.value = true; actionError.value = '';
  try {
    const resp = await authedFetch(paymentReportReceiptUrl(id), { headers: { Accept: 'application/json' } });
    const body = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(body.message || `載入收據失敗（${resp.status}）`);
    receipt.value = adaptPaymentReportReceipt(body, id);
  } catch (e) {
    actionError.value = `已確認入帳，但收據還沒載入：${humanizeApiErrorMessage(e.message || '載入收據失敗')}`;
  } finally {
    busy.value = false;
  }
}

// ── The document on screen (收據 after 確認入帳, otherwise 繳費單): copy as text, preview, download as image ──
const shownDoc = computed(() => (confirmedId.value ? (receipt.value ? receiptView(receipt.value) : null) : slipDoc.value));
const copyLabel = (label) => ({ done: '已複製', failed: '複製失敗，請再試一次' }[copied.value] || label);
async function copyDocText() {
  const text = receipt.value ? buildReceiptCopyText(receipt.value.content_snapshot, receipt.value.receipt_number) : slipPlainText(slipDoc.value);
  try { await navigator.clipboard.writeText(text); copied.value = 'done'; } catch { copied.value = 'failed'; }
  setTimeout(() => { copied.value = ''; }, 2000);
}
async function downloadDoc() {
  exporting.value = true; actionError.value = '';
  // Name the file from the document being captured (moving on is also blocked until the image is done).
  const filename = receipt.value ? `電子收據-${receipt.value.receipt_number || 'receipt'}.png` : shownDoc.value.filename;
  try {
    previewOpen.value = true; // the image is the preview itself
    await nextTick();
    await document.fonts?.ready;
    const blob = await receiptImageBlob({ source: docRef.value?.$el });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch {
    actionError.value = '圖片產生失敗，請再試一次，或先用「複製文字」。';
  } finally {
    exporting.value = false;
  }
}

async function onLessons(e) {
  if (!e.target.open || lessonsState.value !== 'idle') return;
  const key = currentKey.value; // a reload of the same bill must not drop the answer
  lessonsState.value = 'loading';
  try {
    const resp = await authedFetch(`/api/v1/accounting/contracts/${classId.value}/sessions`, { headers: { Accept: 'application/json' } });
    if (!resp.ok) throw new Error(String(resp.status));
    const json = await resp.json();
    if (key !== currentKey.value) return;
    lessons.value = json.sessions || [];
    lessonsState.value = 'ready';
  } catch { if (key === currentKey.value) lessonsState.value = 'error'; }
}
</script>
