<template>
  <AtDialog :open="open" placement="right" size="md" :title="title" title-id="bill-qv-title" @close="$emit('close')">
    <div v-if="loading" role="status" aria-label="載入中" data-testid="qv-loading"><AtSkeleton :rows="4" height="24px" /></div>
    <AtInlineAlert v-else-if="error" tone="danger" title="載入失敗" data-testid="qv-error">
      {{ error }}
      <template #action><AtButton variant="secondary" size="sm" shape="rect" data-testid="qv-retry" @click="load">重試</AtButton></template>
    </AtInlineAlert>
    <div v-else class="qv">
      <div class="qv__head">
        <AtBadge :tone="bill.kind === 'confirm' ? 'warning' : bill.kind === 'owing' ? 'danger' : 'neutral'" :label="bill.label" />
        <ActionMenu :groups="menuGroups" label="更多操作" trigger-class="qv__menu" @select="(id) => $emit('menu', id)" />
      </div>
      <p v-if="!amounts && bill.kind !== 'waived'" class="qv__muted" data-testid="qv-unbilled">還沒開帳單</p>
      <dl v-else-if="amounts" class="qv__money" data-testid="qv-money">
        <div><dt>應收</dt><dd>{{ money(amounts.due) }}</dd></div>
        <div><dt>已收</dt><dd>{{ money(amounts.received) }}</dd></div>
        <div><dt>未收</dt><dd :class="{ due: amounts.owed > 0 }">{{ money(amounts.owed) }}</dd></div>
      </dl>
      <section v-if="report" class="qv__report" data-testid="qv-report" aria-label="家長回報">
        <p><strong>{{ money(report.amount) }}</strong>・{{ report.payment_date ? dateText(report.payment_date) : '未記錄日期' }}・{{ methodText(report.payment_method) }}<template v-if="report.account_last5">・後5碼 {{ report.account_last5 }}</template></p>
        <p v-if="report.note" class="qv__muted">備註：{{ report.note }}</p>
        <p v-if="actionError" class="qv__error" role="alert" data-testid="qv-action-error">{{ actionError }}</p>
        <div class="qv__actions">
          <AtButton size="sm" shape="rect" :loading="busy" :disabled="busy" data-testid="qv-confirm" @click="confirmOpen = true">確認入帳</AtButton>
          <AtButton variant="secondary" size="sm" shape="rect" :loading="busy" :disabled="busy" data-testid="qv-reject" @click="openReject">退回</AtButton>
        </div>
      </section>
      <p v-else-if="rejectWanted" class="qv__muted" data-testid="qv-no-report">這筆已經沒有待確認的回報了。</p>
      <AtButton v-if="!report && !NO_RECORD.includes(bill.kind)" variant="secondary" size="sm" shape="rect" data-testid="qv-record" @click="$emit('record')">登記收款</AtButton>
      <details class="qv__lessons" data-testid="qv-lessons" @toggle="onToggle">
        <summary>上課日<span aria-hidden="true" class="qv__marker">{{ lessonsOpen ? ' ▾' : ' ▸' }}</span></summary>
        <p v-if="lessonsState === 'loading'" class="qv__muted">載入中…</p>
        <p v-else-if="lessonsState === 'error'" class="qv__muted">上課日載入失敗。</p>
        <ol v-else>
          <li v-for="s in lessons" :key="s.class_session_id"><span>{{ formatSessionDate(s.date) }}</span> <span>{{ s.start_time || '' }}</span> <span>{{ STATUS_ZH[s.status] || '' }}</span></li>
        </ol>
      </details>
    </div>
    <AtDialog :open="confirmOpen" title="確認入帳？" size="sm" title-id="qv-confirm-title" initial-focus="[data-testid='qv-confirm-cancel']" @close="confirmOpen = false">
      <p>確認收到 {{ money(report?.amount) }}？確認後算「已收」。金額不對請取消，改按「退回」。</p>
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" data-testid="qv-confirm-cancel" @click="confirmOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" data-testid="qv-confirm-submit" :loading="busy" @click="decide('confirm')">確認入帳</AtButton>
      </template>
    </AtDialog>
    <AtDialog :open="rejectOpen" title="退回這筆繳費回報" size="sm" title-id="qv-reject-title" @close="rejectOpen = false">
      <label class="qv__label" for="qv-reject-reason">退回原因（家長會看到）</label>
      <AtTextarea id="qv-reject-reason" v-model="rejectReason" :rows="3" data-testid="qv-reject-reason" />
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" data-testid="qv-reject-cancel" @click="rejectOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="!rejectReason.trim()" data-testid="qv-reject-submit" :loading="busy" @click="decide('reject', rejectReason.trim())">退回這筆</AtButton>
      </template>
    </AtDialog>
  </AtDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import ActionMenu from '../ActionMenu.vue';
import AtBadge from '../design-system/AtBadge.vue';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import AtSkeleton from '../design-system/AtSkeleton.vue';
import AtTextarea from '../design-system/AtTextarea.vue';
import { authedFetch } from '../../lib/authedFetch.js';
import { STATUS_ZH, formatSessionDate } from '../../lib/billingDocumentView.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import { confirmReport, rejectReport } from '../../lib/paymentActions.js';

// Billing v3 step 2 (Square quick view): one contract's bill at a glance. Reads the existing /accounting/ledger call;
// 確認入帳 / 退回 use paymentActions (same asks as the contract card). The page owns the toast and the reload.
const props = defineProps({
  open: { type: Boolean, default: false },
  bill: { type: Object, default: null }, // a BillList row (billRows)
  branchId: { type: [Number, String], default: null },
  menuGroups: { type: Array, default: () => [] },
  rejectWanted: { type: Boolean, default: false }, // opened from the row's ⋯ 退回: go straight to the reason
});
const emit = defineEmits(['close', 'menu', 'record', 'done']);

const loading = ref(false);
const error = ref('');
const payload = ref(null);
const busy = ref(false);
const actionError = ref('');
const confirmOpen = ref(false);
const rejectOpen = ref(false);
const rejectReason = ref('');
const lessons = ref([]);
const lessonsState = ref('idle');
const lessonsOpen = ref(false);
let seq = 0;

const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
const dateText = (d) => String(d).slice(0, 10);
const methodText = (m) => ({ cash: '現金', transfer: '匯款' }[m] || '未記錄方式');
const title = computed(() => (props.bill ? [props.bill.student_name, props.bill.subject, props.bill.period].filter(Boolean).join('・') : ''));

const classId = computed(() => Number(props.bill?.class_id || 0));
const memberIds = computed(() => {
  const courses = payload.value?.courses || [];
  const pkg = courses.find((c) => Number(c.id) === classId.value)?.package_id;
  return pkg ? courses.filter((c) => c.package_id === pkg).map((c) => Number(c.id)) : [classId.value];
});
// The pending parent report that covers this contract (any member of a shared package counts, as the contract card does).
const report = computed(() => (payload.value?.receipts || []).find((r) => r.status === 'pending' && memberIds.value.includes(Number(r.student_class_id))) || null);
// Same rule as the drawer's amountsFor(): no non-void invoice = no amounts (a bill-less contract is not "NT$ 0").
const amounts = computed(() => {
  const list = (payload.value?.invoices || []).filter((i) => Number(i.student_class_id) === classId.value && i.status !== 'void');
  if (!list.length) return null;
  const sum = (k) => list.reduce((t, i) => t + Number(i[k] || 0), 0);
  return { due: sum('total_amount'), received: sum('calculated_applied_amount'), owed: sum('outstanding_amount') };
});

async function load() {
  const mine = ++seq; // every open / close / contract change starts a new generation, so late answers are dropped
  if (!props.open || !classId.value) return;
  loading.value = true;
  error.value = '';
  payload.value = null;
  lessons.value = []; lessonsState.value = 'idle'; lessonsOpen.value = false;
  try {
    const params = new URLSearchParams({ student_class_id: String(classId.value) });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    const resp = await authedFetch(`/api/v1/accounting/ledger?${params}`, { headers: { Accept: 'application/json' } });
    const json = await resp.json().catch(() => ({}));
    if (mine !== seq) return;
    if (!resp.ok) throw new Error(json.message || `載入失敗（${resp.status}）`);
    payload.value = json;
  } catch (e) {
    if (mine === seq) error.value = humanizeApiErrorMessage(e.message || '載入失敗');
  } finally {
    if (mine === seq) loading.value = false;
  }
  if (mine === seq && props.rejectWanted && report.value) openReject();
}
watch(() => [props.open, props.bill?.key], load, { immediate: true });

function openReject() { rejectReason.value = ''; rejectOpen.value = true; }

const NO_RECORD = ['paid', 'waived', 'issue']; // nothing to register on a collected, waived or not-yet-billed row
async function onToggle(e) {
  lessonsOpen.value = e.target.open;
  if (!e.target.open || lessonsState.value !== 'idle') return;
  const mine = seq;
  const id = classId.value;
  const current = () => mine === seq && id === classId.value; // same generation and still the same contract
  lessonsState.value = 'loading';
  try {
    const resp = await authedFetch(`/api/v1/accounting/contracts/${id}/sessions`, { headers: { Accept: 'application/json' } });
    if (!resp.ok) throw new Error(String(resp.status));
    const json = await resp.json();
    if (!current()) return;
    lessons.value = json.sessions || [];
    lessonsState.value = 'ready';
  } catch { if (current()) lessonsState.value = 'error'; }
}

async function decide(action, note = '') {
  const r = report.value;
  if (!r || busy.value) return;
  busy.value = true; actionError.value = '';
  const res = action === 'reject' ? await rejectReport(r.report_id, note) : await confirmReport(r.report_id);
  busy.value = false;
  confirmOpen.value = false; rejectOpen.value = false;
  if (!res.ok) { actionError.value = res.message; return; }
  emit('done', { action, reportId: Number(r.report_id) });
}
</script>

<style scoped>
.qv{display:grid;gap:12px}
.qv__head{display:flex;justify-content:space-between;align-items:center}
.qv__money{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:0}
.qv__money div{border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg);padding:8px 10px}
.qv__money dt,.qv__muted{font-size:var(--ds-font-size-sm);color:var(--ds-ink-mute)}
.qv__money dd{margin:0;font-size:var(--ds-font-size-lg);font-weight:700;font-variant-numeric:tabular-nums}
.qv__money dd.due{color:var(--ds-danger)}
.qv__report{display:grid;gap:6px;border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg);padding:10px 12px}
.qv__report p{margin:0}
.qv__actions{display:flex;gap:8px}
.qv__error{color:var(--ds-danger)}
.qv__label{display:block;font-size:var(--ds-font-size-sm);margin-bottom:4px}
.qv__lessons summary{cursor:pointer;min-height:44px;display:flex;align-items:center}
.qv__lessons ol{margin:0;padding-left:20px;font-size:var(--ds-font-size-sm)}
.qv :deep(.am__trigger){min-height:44px;min-width:44px}
</style>
