<template>
  <article :class="['contract', { 'contract--focused': focused }]" :data-testid="`contract-card-${course.id}`">
    <!-- Step 5: one line when collapsed (科目・期間 | 剩幾堂 | 狀態 | 主按鈕 | ••• | ▸); the opened contract starts expanded. -->
    <div class="contract__line" data-testid="contract-line">
      <h5>
        <button type="button" class="contract__toggle" :aria-expanded="open" :aria-controls="open ? bodyId : undefined" data-testid="contract-toggle" @click="open = !open">
          <span aria-hidden="true">{{ open ? '▾' : '▸' }}</span>{{ title }}
        </button>
      </h5>
      <span v-if="!isMonthly && state === 'ready'" class="contract__leftline" data-testid="contract-left-line">剩 {{ upcomingCount }} 堂</span>
      <span v-if="pill" :class="['contract__chip', pill.cls]" data-testid="contract-pill">{{ pill.text }}</span>
      <AtButton v-if="primary === 'confirm'" size="sm" shape="rect" :disabled="busy" data-testid="contract-confirm" @click="openConfirm">確認入帳</AtButton>
      <AtButton v-else-if="primary === 'record'" variant="secondary" size="sm" shape="rect" data-testid="contract-record" @click="$emit('record', course)">登記這筆</AtButton>
      <AtButton v-else-if="primary === 'issue'" variant="secondary" size="sm" shape="rect" data-testid="contract-issue" @click="emit('issue', billDraft)">開帳單 {{ money(billDraft.amount) }}</AtButton>
      <ActionMenu v-if="menuGroups.length" :groups="menuGroups" :label="`${course.subject || '課程'} 的更多操作`" trigger-class="contract__more" @select="onMenu" />
    </div>
    <p v-for="w in (open ? [] : warnings)" :key="w.testid" class="contract__warn" :data-testid="w.testid">{{ w.text }}</p>
    <p v-if="actionError" class="contract__error">{{ actionError }}</p>
    <!-- Dialog owner stays mounted while collapsed so the ••• items can open it. -->
    <LegacyPaidFlagActions v-show="open" v-if="legacyResolvable" ref="legacyRef" hide-buttons :course-id="Number(course.id)" :has-confirmed-report="hasConfirmedReport" :shared-package="Boolean(course.package_id) || course.in_package === true" @backfill="emit('legacy-backfill', course)" @resolved="emit('changed')" />

    <div v-if="open" :id="bodyId" class="contract__body" data-testid="contract-body">
    <header class="contract__head">
      <span v-if="courseRef" class="contract__ref" data-testid="contract-ref">{{ courseRef }}</span>
      <span v-if="focused" class="contract__focused" data-testid="contract-focused">你點開的這門課</span>
      <span class="contract__mode">{{ modeLine }}</span>
    </header>

    <!-- Excel card: pay date + amount on top, remaining lessons as the big number (spec 2.3). -->
    <div class="contract__excel" data-testid="contract-excel">
      <div class="contract__pay">
        <p v-if="legacyUnverified" class="contract__payline contract__payline--review" data-testid="contract-legacy-unverified">{{ LEGACY_FLAG_UNVERIFIED_HELP }}</p>
        <p v-else-if="payLineText" class="contract__payline" data-testid="contract-last-payment">{{ payLineText }}</p>
        <!-- in-app #414 / #4155: contract Charge is not the bill total — show both, never reprice the bill -->
        <p v-if="contractCharge != null" class="contract__charge" data-testid="contract-charge">合約金額 {{ money(contractCharge) }}</p>
        <p v-if="amountsLine" class="contract__amounts" data-testid="contract-amounts">{{ amountsLine }}</p>
      </div>
      <p v-if="memberLine" class="contract__muted" data-testid="contract-package-members">{{ memberLine }}</p>
      <p v-if="!isMonthly && state === 'ready'" class="contract__left" data-testid="contract-left">剩 <strong>{{ upcomingCount }}</strong> 堂</p>
    </div>

    <div class="contract__money" data-testid="contract-money">
      <span v-if="noObligation" class="contract__muted">輔導課不用繳費</span>
      <span v-else-if="waived" class="contract__muted" data-testid="contract-waived">{{ WAIVED_LABEL }}</span>
      <!-- F-3: a shared package is one obligation. A follower shows no 未繳 / 還沒開帳單 of its own, only where to pay. -->
      <span v-else-if="followerPointer" class="contract__muted" data-testid="contract-shared-follower">{{ sharedLeadSubject ? `共用方案，請到「${sharedLeadSubject}」卡處理收款` : '共用方案，請到同方案另一張卡處理收款' }}</span>
      <span v-else-if="outstanding > 0">未繳 <strong class="due">{{ money(outstanding) }}</strong></span>
      <span v-else-if="!course.paid && !fullyCollected" class="contract__muted">還沒開帳單</span>
      <span v-else-if="!legacyUnverified">已收</span>
      <span v-if="pendingReport" class="contract__chip pay-partial">待確認入帳 {{ money(pendingReport.amount) }}{{ pendingReport.date ? `（${shortDate(pendingReport.date)} 家長回報）` : '' }}</span>
      <!-- #4765: taught lessons fall outside the contract dates; billing cannot price them, so no 登記這筆 / 繳費單 until the dates are fixed. -->
      <span v-if="outsideDates.length" class="contract__muted" data-testid="contract-outside">{{ outsideText }}</span>
      <span v-if="slipReasonShown" :id="`contract-slip-reason-${course.id}`" class="contract__muted" data-testid="contract-slip-reason">{{ slipState.reason }}</span>
    </div>

    <!-- #4115 / Founder Q2 = A + D3: display only. A different amount alone is no reason to void, so no void action here. -->
    <div v-if="rateNote" class="contract__ratenote" data-testid="contract-rate-note">
      <p>開單時金額 {{ money(rateNote.issued) }} ／ 目前課程金額 {{ money(rateNote.current) }}</p>
      <p class="contract__muted">帳單金額不會隨課程金額變動。</p>
    </div>

    <div class="contract__memo">
      <template v-if="!editing">
        <p v-if="memo" class="contract__memo-text" data-testid="contract-memo">{{ memo }}</p>
        <p v-else class="contract__muted">沒有合約備註</p>
      </template>
      <template v-else>
        <textarea v-model="draft" rows="3" aria-label="合約備註" data-testid="contract-memo-input" />
        <div class="contract__memo-actions">
          <AtSubmitButton size="sm" shape="rect" :pending="saving" pending-text="儲存中…" data-testid="contract-memo-save" @click="saveMemo">儲存</AtSubmitButton>
          <AtButton size="sm" shape="rect" variant="secondary" :disabled="saving" @click="editing = false">取消</AtButton>
        </div>
      </template>
      <p v-if="memoError" class="contract__error">{{ memoError }}</p>
    </div>

    <p v-if="state === 'loading'" class="contract__muted">上課日載入中…</p>
    <p v-else-if="state === 'error'" class="contract__muted">
      上課日載入失敗，金額不受影響。
      <button class="contract__link" type="button" @click="load">重試</button>
    </p>
    <template v-else>
      <p class="contract__summary" data-testid="contract-summary">
        <!-- in-app #472: 共 = 已上 + 還沒上; leave / cancelled lessons are listed but never counted as lessons. -->
        共 {{ heldCount + upcomingCount }} 堂・已上 {{ heldCount }}・還沒上 {{ upcomingCount }}<template v-if="notCountedCount">・請假／取消 {{ notCountedCount }} 次（不算堂數）</template>
      </p>
      <section v-for="m in months" :key="m.key" class="contract__month">
        <h6>{{ m.label }}<span v-if="m.summary" data-testid="contract-month-summary">　{{ m.summary }}</span></h6>
        <p v-if="isMonthly && monthLineText(m.key)" class="contract__monthpay" data-testid="contract-month-pay">{{ monthLineText(m.key) }}</p>
        <ol>
          <li v-for="s in m.sessions" :key="s.class_session_id" data-testid="contract-session">
            <span class="contract__date">{{ formatSessionDate(s.date) }}</span>
            <span class="contract__time">{{ s.start_time || '' }}</span>
            <span v-if="s.numberText" class="contract__num" data-testid="contract-session-no">{{ s.numberText }}</span>
            <span :class="['contract__chip', `tone-${statusTone(s.status)}`]">{{ STATUS_ZH[s.status] || '' }}</span>
            <span v-if="!noObligation && !(s.payment === 'no_invoice' && !billable(s))" :class="['contract__chip', `pay-${s.payment}`]">{{ PAYMENT_LABELS[s.payment] }}</span>
          </li>
        </ol>
      </section>
      <p v-if="unscheduled > 0" class="contract__muted" data-testid="contract-unscheduled">還有 {{ unscheduled }} 堂還沒排日期</p>
    </template>
    </div>
    <RefundDialog v-if="canRefund" :open="refundOpen" :course-id="Number(course.id)" @close="refundOpen = false" @done="refundOpen = false; emit('changed')" />
    <AtDialog :open="confirmOpen" title="確認入帳？" size="sm" title-id="contract-confirm-title" @close="confirmOpen = false">
      <p class="contract__confirm-text" data-testid="contract-confirm-text">
        確認收到 {{ money(pendingReport?.amount) }}？確認後算「已收」。金額不對請取消，改按「退回」。
      </p>
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" data-testid="contract-confirm-cancel" @click="confirmOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" data-testid="contract-confirm-submit" @click="submitConfirm">確認入帳</AtButton>
      </template>
    </AtDialog>
    <AtDialog :open="rejectOpen" title="退回這筆繳費回報" size="sm" title-id="contract-reject-title" @close="rejectOpen = false">
      <p class="contract__confirm-text">退回後這筆回到未繳，不是退款。</p>
      <label class="contract__reject-label" :for="`contract-reject-reason-${course.id}`">退回原因（家長會看到）</label>
      <AtTextarea :id="`contract-reject-reason-${course.id}`" v-model="rejectReason" :rows="3" data-testid="contract-reject-reason" />
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" data-testid="contract-reject-cancel" @click="rejectOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="!rejectReason.trim()" data-testid="contract-reject-submit" @click="submitReject">退回這筆</AtButton>
      </template>
    </AtDialog>
  </article>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import AtButton from '../design-system/AtButton.vue';
import AtSubmitButton from '../design-system/AtSubmitButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtTextarea from '../design-system/AtTextarea.vue';
import ActionMenu from '../ActionMenu.vue';
import RefundDialog from './RefundDialog.vue';
import LegacyPaidFlagActions from './LegacyPaidFlagActions.vue';
import { confirmReport, rejectReport } from '../../lib/paymentActions.js';
import { STATUS_ZH, statusTone, formatSessionDate } from '../../lib/billingDocumentView.js';
import { formatCourseRef } from '../../lib/studentBillingRows.js';
import { AMOUNT_PENDING_REASON, slipAvailability } from '../../lib/billingDocState.js';
import { LEGACY_FLAG_UNVERIFIED_HELP, TUITION_STATUS_CONFIG, WAIVED_LABEL, canResolveLegacyPaidFlag, isWaivedContract } from '../../lib/courseMoneyState.js';

const props = defineProps({
  course: { type: Object, required: true },
  // In-app #396: the course the billing panel was opened from.
  focused: { type: Boolean, default: false },
  // In-app #425: director/super_admin, same gate as 帳務中心 canViewBillingDocs.
  canViewDocs: { type: Boolean, default: false },
  outstanding: { type: Number, default: 0 },
  pendingReport: { type: Object, default: null },
  // Most recent non-void payment on this contract: { date: 'YYYY-MM-DD', amount }, or { legacyUnverified: true }
  // when only legacy metadata (PayDate / Paid flag) says paid and no payment record exists, or { waived: true } (#4150),
  // or { fullDiscount: true } when a 全額折扣 renewal bill settles the term (#4656).
  lastPayment: { type: Object, default: null },
  // Ready next-period draft from the existing monthly-drafts endpoint (offers 開帳單 in place).
  billDraft: { type: Object, default: null },
  // Invoice totals for this contract { due, received, owed } (sums of API fields; null = no invoice yet).
  amounts: { type: Object, default: null },
  // Monthly mode: { 'YYYY-MM': { due, paidDate, paidAmount } } from this contract's invoices.
  monthLines: { type: Object, default: () => ({}) },
  // #3753: another member of the same shared package carries the payment action.
  sharedFollower: { type: Boolean, default: false },
  // Subject of the member card that actually carries the package's 確認入帳 / 登記這筆 action.
  sharedLeadSubject: { type: String, default: '' },
  // F-3: on the card that carries a shared package's payment, the other members' subjects (one card, one 未繳).
  sharedMemberSubjects: { type: Array, default: () => [] },
  // #4115: { invoiceId, issued, current } from issuedBillRateNote(); null hides the note.
  rateNote: { type: Object, default: null },
  // Director/super_admin: may resolve a legacy-flag-only contract (Founder GO 2026-10-21).
  canResolveLegacy: { type: Boolean, default: false },
  hasConfirmedReport: { type: Boolean, default: false },
  // F-2: the list row's 確認入帳 opens the drawer with this card's confirm dialog already asking (once).
  autoConfirm: { type: Boolean, default: false },
  // F-5a1: { waive, void, settle } booleans the page derives from the same alert row and rules the old table used.
  rowActions: { type: Object, default: null },
  // Remembered by the drawer so a reload keeps the card open.
  expanded: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'record', 'issue', 'confirmed', 'slip', 'legacy-backfill', 'row-action', 'toggle']);

// Billing redesign PRD v2 D16: whether the money for each lesson is in.
const PAYMENT_LABELS = { paid: '已收', partial: '繳了一部分', unpaid: '未繳', no_invoice: '還沒開帳單' };
const HELD = ['attended', 'completed', 'late', 'absent'];
const UPCOMING = ['scheduled', 'rescheduled', 'expected'];
// A leave / cancelled lesson is not billable, so it never reads 還沒開帳單.
const billable = (s) => HELD.includes(s.status) || UPCOMING.includes(s.status);

const state = ref('loading');
const data = ref(null);
const memo = ref('');
const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const memoError = ref('');

const sessions = computed(() => data.value?.sessions || []);
const unscheduled = computed(() => Number(data.value?.unscheduled_count || 0));
const heldCount = computed(() => sessions.value.filter((s) => HELD.includes(s.status)).length);
const upcomingCount = computed(() => sessions.value.filter((s) => UPCOMING.includes(s.status)).length + unscheduled.value);
const notCountedCount = computed(() => sessions.value.filter((s) => !billable(s)).length);

// in-app #457: a monthly contract whose bills are all paid (due > 0, nothing owed) and has no billable lesson left
// without a bill is collected, whatever the legacy Paid flag says; the server refuses 登記這筆 there (nothing owed).
const fullyCollected = computed(() => isMonthly.value && state.value === 'ready' && Number(props.amounts?.due) > 0
  && props.outstanding <= 0 && !sessions.value.some((s) => billable(s) && s.payment === 'no_invoice'));

const dateOnly = (d) => (d ? String(d).slice(0, 10).replaceAll('-', '/') : '');
const title = computed(() => {
  const c = data.value || props.course;
  const range = [dateOnly(c.start_date), dateOnly(c.end_date)].filter(Boolean).join('–');
  return range ? `${c.subject || '課程'}・${range}` : (c.subject || '課程');
});
// Founder 2026-10-15 (Q9=A, #3982): same 「課程 000123」 reference the tables show (plan D11 note).
const courseRef = computed(() => {
  const ref = formatCourseRef(props.course.id);
  return ref === formatCourseRef(null) ? '' : ref;
});
const modeLine = computed(() => ((data.value?.schedule_mode || props.course.schedule_mode) === 'date'
  ? '月結：每個月依當月上課日收費'
  : '堂數制：先買堂數，上一堂扣一堂'));

const isMonthly = computed(() => (data.value?.schedule_mode || props.course.schedule_mode) === 'date');
// 退費 (issue 4843) only for an open lesson-count contract with money in; the server re-checks everything.
const refundOpen = ref(false);
const canRefund = computed(() => !isMonthly.value && Number(props.amounts?.received) > 0 && !props.course.closed_reason && !props.course.stop);
// Server `current_contract_charge` only (AccountingController::currentContractCharge). It is null for a monthly contract
// or a package member, whose stored Charge is no contract price: in-app #432 showed a monthly Charge as 合約金額.
const contractCharge = computed(() => {
  if (noObligation.value) return null; // in-app #475: a tutoring course has no price to show
  const raw = props.course?.current_contract_charge;
  if (raw == null || raw === '') return null;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0 ? n : null;
});
// in-app #500: a bill that is NT$0 all round is not a billing line. Tutoring / waived / full-discount cards already
// say why in the rows around it; anything else gets one short reason instead of a bare 應收 0・已收 0・未繳 0.
const amountsLine = computed(() => {
  const a = props.amounts;
  if (!a || followerPointer.value) return '';
  if (!a.due && !a.received && !a.owed) {
    return noObligation.value || waived.value || props.lastPayment?.fullDiscount || props.course.free_gift_settled === true || props.course.full_discount_settled === true ? '' : '金額未填';
  }
  return `本合約帳單 應收 ${money(a.due)}・已收 ${money(a.received)}・未繳 ${money(a.owed)}`;
});
const shortDate = (d) => { const p = String(d).slice(5, 10).split('-'); return p.length === 2 ? `${Number(p[0])}/${Number(p[1])}` : ''; };
// Same source as the printed receipt (confirmed receipts, then non-void invoice payments; never "fixed" here).
// #4150: legacy metadata alone is not a payment, so it reads 需核對, never 繳費日 (tutoring has nothing to collect).
// #4150 review: a waiver (server ContractMoneyState::isWaived; a real one keeps Paid=0) is terminal, so no unpaid controls.
const waived = computed(() => isWaivedContract(props.course));
const legacyUnverified = computed(() => props.lastPayment?.legacyUnverified === true && !noObligation.value);
// Same scope the server allows (PaymentReportController::legacyFlagRefusal): any contract flagged paid with no report
// waiting for review. An open bill is fine: the server collects against it and caps the amount by its balance.
// F-3: same condition the follower pointer always used.
const followerPointer = computed(() => props.sharedFollower && !noObligation.value && !waived.value
  && (props.outstanding > 0 || !props.course.paid || legacyUnverified.value));
const memberLine = computed(() => (props.sharedMemberSubjects.length && !props.sharedFollower
  ? `共用方案：和「${props.sharedMemberSubjects.join('」「')}」一起收款，這張卡處理全部。` : ''));
const legacyResolvable = computed(() => canResolveLegacyPaidFlag(props.course, {
  legacyUnverified: legacyUnverified.value, canResolveLegacy: props.canResolveLegacy,
  waived: waived.value, pendingReport: props.pendingReport, sharedFollower: props.sharedFollower,
}));
const payLineText = computed(() => {
  const p = props.lastPayment;
  if (noObligation.value) return ''; // in-app #475: the money row says 輔導課不用繳費; never 還沒繳費 on top
  if (p?.waived || waived.value) return WAIVED_LABEL; // #4150: a waiver is not a missing payment, and not 還沒繳費 either
  if (p?.fullDiscount) return '全額折扣，不用繳費'; // #4656
  if (!p?.date) return '還沒繳費';
  return p.amount == null ? `繳費日 ${dateOnly(p.date)}` : `繳費日 ${dateOnly(p.date)}　${money(p.amount)}`;
});
const monthLineText = (key) => {
  const m = props.monthLines?.[key];
  if (!m) return '';
  return `應繳 ${money(m.due)}${m.paidDate ? `　已收 ${shortDate(m.paidDate)} ${money(m.paidAmount)}` : ''}`;
};

// Month payment state from the per-lesson state the endpoint already returns (no money math here).
function monthPayState(list) {
  const pays = list.map((s) => s.payment);
  if (pays.every((p) => p === 'paid')) return '已收';
  if (pays.every((p) => p === 'no_invoice')) return '還沒開帳單';
  if (pays.every((p) => p === 'unpaid' || p === 'no_invoice')) return '未繳';
  return '繳了一部分';
}

// Excel-card numbering: only held/upcoming lessons count, same as the summary line.
const months = computed(() => {
  let n = 0;
  const groups = [];
  for (const s0 of sessions.value) {
    const key = String(s0.date).slice(0, 7);
    if (groups[groups.length - 1]?.key !== key) groups.push({ key, label: `${key.slice(0, 4)} 年 ${Number(key.slice(5, 7))} 月`, sessions: [], counted: 0 });
    const g = groups[groups.length - 1];
    const s = { ...s0, numberText: '' };
    if ([...HELD, ...UPCOMING].includes(s.status)) {
      n += 1;
      g.counted += 1;
      // G-011: a lesson can deduct more or less than 1 and the payload carries no deduction units, so rows show 第 n 堂 only.
      s.numberText = isMonthly.value ? `本月第 ${g.counted} 堂` : `第 ${n} 堂`;
    }
    g.sessions.push(s);
  }
  for (const g of groups) {
    const counted = g.sessions.filter((s) => s.numberText);
    g.summary = isMonthly.value && counted.length ? `${counted.length} 堂${noObligation.value ? '' : `・${monthPayState(counted)}`}` : '';
  }
  return groups;
});

async function load() {
  state.value = 'loading';
  try {
    const resp = await authedFetch(`/api/v1/accounting/contracts/${Number(props.course.id)}/sessions`, { headers: { Accept: 'application/json' } });
    if (!resp.ok) throw new Error(String(resp.status));
    data.value = await resp.json();
    memo.value = data.value.memo || '';
    state.value = 'ready';
  } catch {
    state.value = 'error';
  }
}

function startEdit() {
  draft.value = memo.value;
  memoError.value = '';
  editing.value = true;
}

async function saveMemo() {
  saving.value = true;
  memoError.value = '';
  try {
    // Memo-only PUT leaves payment status untouched (StudentClassPaidStatusTest::test_update_memo_only_does_not_touch_paid).
    const resp = await authedFetch(`/api/v1/student-classes/${Number(props.course.id)}`, {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ Memo: draft.value }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.message || `儲存失敗（${resp.status}）`);
    memo.value = draft.value;
    editing.value = false;
    emit('changed');
  } catch (e) {
    memoError.value = humanizeApiErrorMessage(e.message || '儲存失敗');
  } finally {
    saving.value = false;
  }
}

const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
// #4699: the ledger row says it up front (courses[].no_payment_obligation); the loaded course detail is the fallback.
const noObligation = computed(() => props.course.no_payment_obligation === true || data.value?.class_type === 'tutoring');
const busy = ref(false);
const actionError = ref('');
// In-app #425: 繳費單 uses the 帳務中心 rule (lib/billingDocState.js); the card only maps its state into a list row.
// Shown when it can open, or when the reason is something to act on (金額待確認); paid / reported / follower cards
// already say so on the card, so no disabled button there.
const slipRow = computed(() => ({
  id: props.course.id,
  amount_pending_confirmation: props.course.amount_pending_confirmation === true,
  legacy_paid_without_invoice: legacyUnverified.value && !props.amounts,
  payable_status: props.amounts ? 'invoiced' : 'unbilled',
  payment_status: props.pendingReport ? 'pending_report'
    : (props.outstanding > 0 || (!props.course.paid && !props.amounts)) ? 'unpaid' : 'paid',
}));
const slipState = computed(() => slipAvailability(slipRow.value, props.canViewDocs));
const outsideDates = computed(() => (Array.isArray(props.course.lessons_outside_contract) ? props.course.lessons_outside_contract : []));
const md = (d) => { const [, m, day] = String(d || '').slice(0, 10).split('-'); return m ? `${Number(m)}/${Number(day)}` : ''; };
const showSlip = computed(() => props.canViewDocs && !outsideDates.value.length && !props.sharedFollower && !noObligation.value && !waived.value
  && (slipState.value.ok || slipState.value.reason === AMOUNT_PENDING_REASON));

// PRD v2 D9/D20: step 2 (確認入帳 / 退回) happens right here, no tab switch.
// 確認入帳 books money in one click, so ask first; 取消 has the initial focus (no undo: mistakes go through 撤銷收款).
const confirmOpen = ref(false);
async function openConfirm() {
  confirmOpen.value = true;
  await nextTick();
  await nextTick();
  document.querySelector('[data-testid="contract-confirm-cancel"]')?.focus();
}
function submitConfirm() {
  confirmOpen.value = false;
  decide('confirm');
}
const rejectOpen = ref(false);
const rejectReason = ref('');
function openReject() {
  rejectReason.value = '';
  rejectOpen.value = true;
}
function submitReject() {
  const reason = rejectReason.value.trim();
  if (!reason) return;
  rejectOpen.value = false;
  decide('reject', reason);
}
async function decide(action, note = '') {
  busy.value = true;
  actionError.value = '';
  const reportId = props.pendingReport.report_id;
  const res = action === 'reject' ? await rejectReport(reportId, note) : await confirmReport(reportId);
  busy.value = false;
  if (!res.ok) { actionError.value = res.message; return; }
  // #398/#405: a receipt exists only after confirmation (#1827), so only 確認入帳 hands the report on for the receipt step.
  if (action === 'confirm') emit('confirmed', reportId);
  emit('changed');
}

// ---- Step 5: collapsed one-liner. Expanded = everything the card always showed.
const open = ref(props.focused || props.expanded);
const bodyId = `contract-body-${props.course.id}`;
// The drawer remembers which cards are open, because an action reloads the ledger and remounts every card.
watch(open, (v) => emit('toggle', v));
const legacyRef = ref(null);
const canRecord = computed(() => !props.pendingReport && !followerPointer.value && !outsideDates.value.length && !legacyResolvable.value && !noObligation.value && !waived.value
  && (props.outstanding > 0 || (!props.course.paid && !fullyCollected.value)));
// Same two places the card used to offer 開帳單: nothing owed on an unpaid bill-less term, or a collected term with a ready renewal draft.
const canIssue = computed(() => !!props.billDraft && (fullyCollected.value
  || (!noObligation.value && !waived.value && !followerPointer.value && !(props.outstanding > 0) && !props.course.paid)));
// 登記這筆 leads when money is owed; an unbilled term with a ready draft leads with 開帳單 and keeps 登記這筆 in •••.
const primary = computed(() => (props.pendingReport ? 'confirm' : canRecord.value && (props.outstanding > 0 || !canIssue.value) ? 'record' : canIssue.value ? 'issue' : ''));
const pill = computed(() => {
  const C = TUITION_STATUS_CONFIG;
  if (props.pendingReport) return { text: `${C.pending_report.label} ${money(props.pendingReport.amount)}`, cls: 'pay-partial' };
  if (noObligation.value) return { text: '輔導課', cls: '' };
  if (waived.value) return { text: WAIVED_LABEL, cls: '' };
  if (followerPointer.value) return { text: '共用方案', cls: '' };
  if (legacyUnverified.value) return { text: props.outstanding > 0 ? `需核對 ${money(props.outstanding)}` : '需核對', cls: 'tone-warn' };
  if (props.outstanding > 0) return { text: `${C.unpaid.label} ${money(props.outstanding)}`, cls: 'pay-unpaid' };
  if (!props.course.paid && !fullyCollected.value) return { text: '還沒開帳單', cls: '' };
  return { text: C.paid.label, cls: 'pay-paid' };
});
const outsideText = computed(() => `需核對：${outsideDates.value.map(md).join('、')} 的課不在合約日期（${[md(props.course.start_date), md(props.course.end_date)].join('–')}）內，請先改合約日期`);
const slipReasonShown = computed(() => showSlip.value && !slipState.value.ok);
// Warnings never hide: a collapsed card keeps them as one warning-tone line each.
const warnings = computed(() => [
  legacyUnverified.value && { testid: 'contract-legacy-unverified', text: LEGACY_FLAG_UNVERIFIED_HELP },
  outsideDates.value.length && { testid: 'contract-outside', text: outsideText.value },
  slipReasonShown.value && { testid: 'contract-slip-reason', text: slipState.value.reason },
].filter(Boolean));
const menuGroups = computed(() => {
  const main = [
    props.pendingReport && { id: 'reject', label: '退回這筆', disabled: busy.value },
    canRecord.value && primary.value !== 'record' && { id: 'record', label: '登記這筆' },
    canIssue.value && primary.value !== 'issue' && { id: 'issue', label: `開帳單 ${money(props.billDraft.amount)}` },
    showSlip.value && { id: 'slip', label: '繳費單', disabled: !slipState.value.ok, reason: slipState.value.ok ? '' : slipState.value.reason },
    { id: 'memo', label: '改備註' },
  ].filter(Boolean);
  const ra = props.rowActions || {};
  const risky = [
    ra.waive && { id: 'waive', label: `改成「${WAIVED_LABEL}」`, danger: true },
    ra.void && { id: 'void', label: '撤銷收款', danger: true },
    ra.settle && { id: 'settle', label: '結案（不續報）', danger: true },
    canRefund.value && { id: 'refund', label: '退費', danger: true },
    legacyResolvable.value && !props.hasConfirmedReport && { id: 'legacy-backfill', label: '已收過，補登收款', danger: true },
    legacyResolvable.value && !props.hasConfirmedReport && { id: 'legacy-clear', label: '其實沒收，改成未繳', danger: true },
  ].filter(Boolean);
  return [{ id: 'main', label: '', items: main }, ...(risky.length ? [{ id: 'risk', label: '', items: risky }] : [])];
});
function onMenu(id) {
  if (id === 'reject') openReject();
  else if (id === 'record') emit('record', props.course);
  else if (id === 'issue') emit('issue', props.billDraft);
  else if (id === 'slip') emit('slip', props.course);
  else if (id === 'memo') { open.value = true; startEdit(); }
  else if (['waive', 'void', 'settle'].includes(id)) emit('row-action', id);
  else if (id === 'refund') refundOpen.value = true;
  else if (id === 'legacy-backfill') legacyRef.value?.openBackfill();
  else if (id === 'legacy-clear') legacyRef.value?.openClear();
}

watch(() => props.course.id, load, { immediate: true });
onMounted(() => { if (props.autoConfirm && props.pendingReport) openConfirm(); });
</script>

<style scoped>
.contract{border:1px solid var(--ds-border);border-radius:12px;padding:12px 14px;display:grid;gap:10px;background:var(--surface,var(--ds-canvas))}
.contract__line{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}
.contract__toggle{display:inline-flex;align-items:baseline;gap:6px;max-width:100%;border:0;background:transparent;padding:4px 0;text-align:left;cursor:pointer;color:inherit;font:inherit}
.contract__leftline{font-size:var(--ds-font-size-sm);color:var(--ds-ink-mute);white-space:nowrap}
.contract__warn{margin:0;font-size:var(--ds-font-size-sm);font-weight:600;color:var(--ds-warning)}
.contract__body{display:grid;gap:10px}
.contract__excel{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--ds-border);border-radius:8px;background:var(--ds-canvas-soft)}
.contract__pay{display:grid;gap:2px;min-width:0}
.contract__pay p{margin:0}
.contract__payline{font-size:14px;font-weight:600}
.contract__payline--review{color:var(--ds-ink-mute)}
.contract__amounts{font-size:12px;color:var(--ds-ink-mute)}
.contract__left{margin:0;font-size:14px;white-space:nowrap}
.contract__left strong{font-size:20px;font-variant-numeric:tabular-nums}
.contract__monthpay{margin:0 0 4px;font-size:12px;color:var(--ds-ink-mute)}
.contract__head{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px}
.contract__line h5{margin:0;font-size:15px;flex:1 1 160px;min-width:0}
.contract__mode{font-size:12px;color:var(--ds-ink-mute)}
.contract__ref{font-size:var(--ds-font-size-sm);color:var(--ds-ink-mute);font-variant-numeric:tabular-nums}
.contract--focused{border-color:var(--ds-cta);border-width:2px}
.contract__focused{font-size:var(--ds-font-size-sm);font-weight:600;color:var(--ds-cta)}
.contract__money{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;font-size:14px}
.contract__money .due{color:var(--ds-danger)}
.contract__confirm-text{margin:0;font-size:14px;line-height:1.6}
.contract__reject-label{display:block;margin-bottom:6px;font-size:14px;font-weight:700}
.contract__ratenote{display:grid;gap:4px;padding:8px 10px;border-radius:var(--ds-radius-md);background:var(--ds-canvas-soft);font-size:var(--ds-font-size-sm)}
.contract__ratenote p{margin:0}
.contract__memo{background:var(--ds-canvas-soft);border-radius:8px;padding:8px 10px;display:grid;gap:6px}
.contract__memo-text{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px}
.contract__memo textarea{width:100%;box-sizing:border-box;font:inherit;font-size:14px;padding:6px 8px}
.contract__memo-actions{display:flex;gap:8px}
.contract__summary{margin:0;font-size:13px;font-weight:700}
.contract__month h6{margin:0 0 4px;font-size:12px;color:var(--ds-ink-mute)}
.contract__month ol{list-style:none;margin:0;padding:0;display:grid;gap:4px}
.contract__month li{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;font-size:13px;padding:4px 0;border-bottom:1px solid var(--ds-border)}
.contract__date{min-width:110px;font-variant-numeric:tabular-nums}
.contract__time{min-width:44px;color:var(--ds-ink-mute);font-variant-numeric:tabular-nums}
.contract__num{font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}
.contract__chip{display:inline-flex;border-radius:6px;padding:1px 7px;font-size:12px;font-weight:700;background:var(--ds-canvas-soft);color:var(--ds-ink-mute)}
.contract__chip.pay-paid{background:none;color:var(--ds-success);padding:1px 0;font-weight:600}
.contract__chip.tone-done{background:var(--ds-success-wash);color:var(--ds-success)}
.contract__chip.tone-warn,.contract__chip.pay-partial{background:var(--ds-warning-wash);color:var(--ds-warning)}
.contract__chip.pay-unpaid{background:var(--ds-danger-wash);color:var(--ds-danger)}
.contract__muted{margin:0;font-size:13px;color:var(--ds-ink-mute)}
.contract__error{margin:0;font-size:13px;color:var(--ds-danger)}
.contract__link{border:0;background:transparent;color:var(--ds-primary-text);font-size:13px;font-weight:600;cursor:pointer;padding:0;justify-self:start}
</style>
