<template>
  <Transition name="ledger-fade">
    <div v-if="show" class="ledger-overlay" @click.self="$emit('close')">
      <div
        ref="modalEl"
        class="ledger-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ledger-modal-title"
        tabindex="-1"
        @keydown.esc.stop.prevent="onEsc"
        @keydown.tab="trapTab"
        @keydown="onStepKey"
      >
        <div class="ledger-header">
          <div>
            <h3 id="ledger-modal-title">{{ panelTitle }}</h3>
          </div>
          <div v-if="canStep" class="ledger-steps" role="group" aria-label="切換學生">
            <button class="ledger-step" type="button" aria-label="上一位學生" data-testid="ledger-step-prev" @click="$emit('step', -1)"><span aria-hidden="true">↑</span></button>
            <button class="ledger-step" type="button" aria-label="下一位學生" data-testid="ledger-step-next" @click="$emit('step', 1)"><span aria-hidden="true">↓</span></button>
          </div>
          <button class="ledger-close" type="button" @click="$emit('close')" aria-label="關閉學生帳務"><span aria-hidden="true">×</span></button>
        </div>

        <div v-if="loading" class="ledger-state">載入中…</div>
        <div v-else-if="error" class="ledger-state ledger-error">{{ error }}</div>
        <template v-else-if="payload">
          <div v-if="actionError" class="ledger-state ledger-error" role="alert" data-testid="ledger-action-error">
            {{ actionError }} 下方是已重新讀取的最新帳務，確認後再操作。
            <button type="button" data-testid="ledger-action-reload" @click="loadLedger()">重新整理</button>
          </div>
          <PaymentAllocation
            v-if="allocating"
            :student-name="payload.student?.name || '學生'"
            :contracts="allocContracts"
            :on-confirmed="onAllocConfirmed"
            @close="onAllocClose"
            @done="onAllocDone"
          />
          <div v-show="!allocating">
          <p v-if="initialActionNote" class="ledger-empty" role="status" data-testid="ledger-initial-action-note">{{ initialActionNote }}</p>
          <!-- PRD v2 D8: owed-by-today first, same rule as the student list. -->
          <div class="ledger-owed" data-testid="ledger-owed">
            <p class="ledger-conclusion" data-testid="ledger-conclusion">
              <strong>{{ conclusion }}</strong>
              <AtHelpDisclosure label="為什麼？">
                <p>「未繳」是到今天已到期、還沒收到的金額；之後才到期的另列在下面。</p>
                <p>「待確認入帳」是家長說繳了、你還沒按「確認入帳」的金額，確認前不算已收。</p>
                <p v-if="overpaidTotal > 0">多收記在同一張帳單上，不會自動抵上面的未繳金額。</p>
              </AtHelpDisclosure>
            </p>
            <p class="ledger-owed__label">{{ owedLabel }}</p>
            <p class="ledger-owed__now" :class="{ due: owedSplit.now > 0 }" data-testid="ledger-owed-now">{{ formatCurrency(owedSplit.now) }}</p>
            <p v-if="owedSplit.later > 0" class="ledger-owed__later" data-testid="ledger-owed-later">之後還會到期 {{ formatCurrency(owedSplit.later) }}</p>
            <p v-if="overpaidTotal > 0" class="ledger-owed__over" role="status" data-testid="ledger-overpaid">多收 {{ formatCurrency(overpaidTotal) }}（舊資料）。{{ ledgerExceptions.some((x) => x.can_void) ? '詳情在「要先處理」。' : '這筆多收沒有對應的收款，系統無法自動處理。可按下面的「回報給系統管理員」。' }}</p>
          </div>

          <section v-if="ledgerExceptions.length" class="ledger-section">
            <h4>要先處理</h4>
            <div class="ledger-receipts">
              <div v-for="x in visibleExceptions" :key="x.key" class="ledger-receipt">
                <strong>{{ x.title }}</strong>
                <span>{{ x.message }}</span>
                <small>{{ x.detail }}</small>
                <button
                  v-if="x.can_void"
                  class="ledger-action ledger-action--danger"
                  type="button"
                  :disabled="busyReportId === x.report_id"
                  @click="voidReport(x.report_id)"
                >{{ x.action_label || '撤銷收款' }}</button>
                <small v-if="x.hint" class="ledger-muted" data-testid="ledger-exception-hint">{{ x.hint }}</small>
                <template v-if="!x.can_void && !x.report_id">
                  <span class="ledger-muted" data-testid="ledger-exception-drift">{{ x.drift_line }}</span>
                  <button class="ledger-action" type="button" data-testid="ledger-exception-report" @click="reportToAdmin(x)">回報給系統管理員</button>
                </template>
              </div>
            </div>
            <button
              v-if="ledgerExceptions.length > EXCEPTION_PREVIEW"
              class="ledger-more"
              type="button"
              @click="showAllExceptions = !showAllExceptions"
            >
              {{ showAllExceptions ? '收起' : `還有 ${ledgerExceptions.length - EXCEPTION_PREVIEW} 項` }}
            </button>
          </section>

          <section v-if="contractCourses.length" class="ledger-section">
            <h4>合約（每一堂課與付款）</h4>
            <p v-if="contractCourses.some(hasMultiplePackageBills)" class="ledger-empty" data-testid="ledger-package-batch-note">共用方案有多門課的帳單待收，請從要處理的課程卡「登記這筆」；不加入下方批次收款。</p>
            <div class="ledger-contracts">
              <ContractCard
                v-for="c in contractCourses"
                :key="c.id"
                :course="c"
                :focused="isOpenedCourse(c)"
                :can-view-docs="canManageInvoices()"
                :outstanding="owedFor(c.id)"
                :pending-report="packageLead(c) ? pendingReportFor(c.id) : null"
                :shared-follower="!packageLead(c)"
                :shared-lead-subject="packageLead(c) ? '' : (packageLeadOf(c)?.subject || '')"
                :shared-member-subjects="sharedMembersOf(c)"
                :last-payment="payLineFor(c)"
                :amounts="amountsFor(c.id)"
                :month-lines="monthLinesFor(c.id)"
                :bill-draft="draftFor(c.id)"
                :rate-note="issuedBillRateNote(c, payload.invoices)"
                :can-resolve-legacy="canManageInvoices()"
                :has-confirmed-report="hasConfirmedReportFor(c.id)"
                :auto-confirm="autoConfirmId === Number(c.id)"
                :row-actions="rowActions[Number(c.id)] || null"
                @row-action="(kind) => emit('row-action', { kind, courseId: Number(c.id) })"
                @record="openEntry"
                @legacy-backfill="openEntry($event, 'legacy-backfill')"
                @issue="openIssue"
                @confirmed="openReceipt"
                @changed="onPanelChanged"
                @slip="openSlip"
              />
            </div>
          </section>

          <section class="ledger-section">
            <h4>帳單</h4>
            <div v-if="ledgerBothEmpty && payload.scope?.no_payment_obligation" class="ledger-empty">輔導課不需繳費，所以這裡不會有帳單或收據。</div>
            <div v-else-if="ledgerBothEmpty && payload.invoices?.length" class="ledger-empty" data-testid="ledger-target-empty">這門課沒有帳單和收款。下面是這位學生的其他紀錄。</div>
            <div v-else-if="ledgerBothEmpty" class="ledger-empty">還沒開帳單，也沒收款。登記收款後會出現在這裡。</div>
            <div v-if="!payload.invoices?.length && !ledgerBothEmpty" class="ledger-empty">此學生尚無帳單。</div>
            <div v-else-if="payload.invoices?.length" class="ledger-table-wrap">
              <table class="ledger-table">
                <thead>
                  <tr>
                    <th class="ledger-col-expand" scope="col"><span class="sr-only">展開上課日與收款</span></th>
                    <th>帳單（科目）</th>
                    <th>應繳日</th>
                    <th class="num">應收</th>
                    <th class="num">已收</th>
                    <th class="num">未繳</th>
                    <th>狀態</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="inv in payload.invoices" :key="inv.id">
                    <tr :class="{ 'ledger-row--open': isExpanded(inv.id), 'ledger-row--attention': needsAttention(inv) }">
                      <td class="ledger-col-expand">
                        <button
                          class="ledger-expand"
                          type="button"
                          :aria-expanded="isExpanded(inv.id)"
                          :aria-label="isExpanded(inv.id) ? '收合上課日與收款' : '展開上課日與收款'"
                          :title="isExpanded(inv.id) ? undefined : '展開上課日'"
                          :data-testid="`ledger-invoice-toggle-${inv.id}`"
                          @click="toggleExpand(inv.id)"
                        >
                          <span aria-hidden="true">{{ isExpanded(inv.id) ? '▾' : '▸' }}</span>
                          <em v-if="inv.payments?.length" class="ledger-pay-count">{{ inv.payments.length }}</em>
                        </button>
                      </td>
                      <td class="ledger-cell-title">
                        <strong>{{ formatAccountingLedgerInvoiceLabel(inv) }}</strong>
                        <small v-if="isOtherCourseInvoice(inv)" class="ledger-other-course" data-testid="ledger-other-course">其他課程</small>
                        <small>{{ inv.period_start && inv.period_end ? `${inv.period_start.replaceAll('-', '/')}–${inv.period_end.replaceAll('-', '/')}` : formatPeriod(inv.billing_period) }}</small>
                        <small v-if="rateNoteFor(inv)" class="ledger-rate-hint" :data-testid="`ledger-rate-hint-${inv.id}`">開單時金額 {{ formatCurrency(rateNoteFor(inv).issued) }} ／ 目前課程金額 {{ formatCurrency(rateNoteFor(inv).current) }}（金額不同可能是約定調整，不代表帳單有誤）</small>
                        <small v-if="(inv.overpaid_amount || 0) > 0" class="ledger-overpay-hint">多收 {{ formatCurrency(inv.overpaid_amount) }}</small>
                      </td>
                      <td data-label="應繳日">{{ fmtDate(inv.due_date) }}</td>
                      <td class="num" data-label="應收">{{ formatCurrency(inv.total_amount) }}</td>
                      <td class="num" data-label="已收">{{ formatCurrency(inv.calculated_applied_amount) }}</td>
                      <td class="num" data-label="未繳" :class="{ due: (inv.outstanding_amount || 0) > 0 }">{{ formatCurrency(inv.outstanding_amount) }}</td>
                      <td data-label="狀態">
                        <span :class="['ledger-chip', invoiceStatusClass(inv.status)]">{{ invoiceStatusLabel(inv.status) }}</span>
                        <span v-if="inv.zero_amount_reason === ZERO_REASON_FREE_GIFT" class="ledger-chip chip--muted" :data-testid="`ledger-free-gift-${inv.id}`">{{ FREE_GIFT_LABEL }}</span>
                      </td>
                      <td class="ledger-cell-actions">
                        <div class="ledger-actions">
                          <button
                            v-if="canDirectVoidInvoice(inv)"
                            class="ledger-action ledger-action--danger"
                            type="button"
                            :disabled="isBusyInvoice(inv)"
                            @click="voidInvoice(inv, 'direct')"
                          >作廢</button>
                          <button
                            v-else-if="canExceptionVoidInvoice(inv)"
                            class="ledger-action ledger-action--warning"
                            type="button"
                            :disabled="isBusyInvoice(inv)"
                            @click="voidInvoice(inv, 'exception')"
                          >更正並作廢</button>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="isExpanded(inv.id)" class="ledger-detail-row">
                      <td colspan="8">
                        <LedgerCoverageDates
                          :coverage-key="`inv-${inv.id}`"
                          :entry="coverage[`inv-${inv.id}`]"
                          @retry="loadCoverage('inv', inv.id)"
                          @toggle-all="toggleCoverageAll(`inv-${inv.id}`)"
                        />
                        <div class="ledger-timeline" aria-label="收款時間線">
                          <p v-if="!inv.payments?.length" class="ledger-muted">尚無收款紀錄</p>
                          <ol v-else class="ledger-timeline__list">
                            <li
                              v-for="p in inv.payments"
                              :key="p.id"
                              :class="[
                                'ledger-timeline__item',
                                { 'is-void': p.is_void, 'is-overpay': p.application_status === 'overpayment_pending_review' },
                              ]"
                            >
                              <div class="ledger-timeline__when">{{ p.paid_at ? fmtDate(p.paid_at) : '未記錄日期' }}</div>
                              <div class="ledger-timeline__body">
                                <strong>{{ p.is_void ? '更正收款' : paymentMethodLabel(p.method) }} {{ signedCurrency(p.amount) }}</strong>
                                <span :class="['ledger-chip', applicationStatusClass(p.application_status)]">
                                  {{ applicationStatusLabel(p.application_status) }}
                                </span>
                                <div class="ledger-timeline__meta">
                                  <span v-if="p.applied_amount">記入 {{ formatCurrency(p.applied_amount) }}</span>
                                  <span v-if="p.unapplied_amount">多收 {{ formatCurrency(p.unapplied_amount) }}</span>
                                  <span v-if="p.note">備註：{{ p.note }}</span>
                                </div>
                                <BillingNotesPanel subject-type="payment" :subject-id="p.id" />
                              </div>
                            </li>
                          </ol>
                        </div>
                        <BillingNotesPanel subject-type="invoice" :subject-id="inv.id" />
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </section>

          <!-- #4041: deleting a course voids its bill; that bill's notes stay readable here, never editable. -->
          <section v-if="payload.voided_invoice_notes?.length" class="ledger-section" data-testid="ledger-voided-invoice-notes">
            <h4>已刪除課程的帳單備註</h4>
            <div v-for="g in payload.voided_invoice_notes" :key="g.invoice_id" class="ledger-voided-notes">
              <strong>{{ g.invoice_no }}</strong> <small>{{ g.course_ref }}{{ g.label }}</small>
              <ol class="ledger-voided-notes__list">
                <li v-for="(n, i) in g.notes" :key="n.id ?? `legacy-${i}`">
                  <small>{{ n.legacy ? '原備註（舊資料）' : `${n.author_name || '未知'}・${fmtDate(n.created_at)}` }}</small>
                  <p>{{ n.body }}</p>
                </li>
              </ol>
            </div>
            <p class="ledger-muted">帳單已作廢，這些備註只能查看，不能新增或修改。</p>
          </section>

          <section v-if="payload.receipts?.length || !ledgerBothEmpty" class="ledger-section">
            <h4>收款紀錄</h4>
            <div v-if="!payload.receipts?.length" class="ledger-empty">此學生尚無收款紀錄。</div>
            <div v-else-if="shownReceipts.length" class="ledger-receipts ledger-receipts--compact">
              <div v-for="r in shownReceipts" :key="r.report_id" class="ledger-receipt">
                <strong>{{ formatCurrency(r.amount) }}</strong>
                <span>{{ r.payment_date ? fmtDate(r.payment_date) : '未記錄日期' }}</span>
                <span>{{ paymentMethodLabel(r.payment_method) }}</span>
                <span :class="['ledger-chip', reportStatusClass(r.status)]">{{ reportStatusLabel(r.status) }}</span>
                <small>{{ formatLedgerReceiptBillLine(r) }}</small>
                <!-- in-app #425: same 查看收據 as the 帳務中心 收款紀錄 tab, on every confirmed row. -->
                <button
                  v-if="r.status === 'confirmed' && canManageInvoices()"
                  class="ledger-more"
                  type="button"
                  :data-testid="`ledger-receipt-view-${r.report_id}`"
                  @click="viewReceipt(r.report_id)"
                >查看收據</button>
                <button
                  v-if="canShowReceiptCoverage(r)"
                  class="ledger-more ledger-receipt-toggle"
                  type="button"
                  :aria-expanded="isReceiptExpanded(r.report_id)"
                  :data-testid="`ledger-receipt-toggle-${r.report_id}`"
                  @click="toggleReceipt(r.report_id)"
                >{{ isReceiptExpanded(r.report_id) ? '收合上課日' : '展開上課日' }}</button>
                <small v-if="r.account_last5 || r.note" class="ledger-receipt-extra" :title="r.note || undefined">
                  <template v-if="r.account_last5">後5碼 {{ r.account_last5 }}</template>
                  <template v-if="r.account_last5 && r.note"> · </template>
                  <template v-if="r.note">備註：{{ r.note }}</template>
                </small>
                <BillingNotesPanel subject-type="payment_report" :subject-id="r.report_id" />
                <LedgerCoverageDates
                  v-if="isReceiptExpanded(r.report_id)"
                  class="ledger-receipt-coverage"
                  :coverage-key="`rcpt-${r.report_id}`"
                  :entry="coverage[`rcpt-${r.report_id}`]"
                  @retry="loadCoverage('rcpt', r.report_id)"
                  @toggle-all="toggleCoverageAll(`rcpt-${r.report_id}`)"
                />
              </div>
            </div>
            <button
              v-if="otherReceipts.length"
              class="ledger-more"
              type="button"
              :aria-expanded="showOtherReceipts"
              data-testid="ledger-other-receipts-toggle"
              @click="showOtherReceipts = !showOtherReceipts"
            >{{ showOtherReceipts ? '收合其他紀錄' : `已退回／已撤銷（${otherReceipts.length}）` }}</button>
          </section>

          <!-- PRD v2 D5/D18: one 登記收款 for every open contract (allocation step); each card keeps 登記這筆. -->
          <div v-if="allocContracts.length" class="ledger-sticky-pay" data-testid="ledger-sticky-pay">
            <p id="ledger-pay-scope" class="ledger-muted ledger-sticky-pay__scope" data-testid="ledger-pay-scope">{{ payScopeText }}</p>
            <button type="button" class="ledger-sticky-pay__btn" data-testid="ledger-sticky-pay-btn" aria-describedby="ledger-pay-scope" @click="allocating = true">
              登記收款<small>{{ allocContracts.length > 1 ? `${allocContracts.length} 份合約` : allocContracts[0].subject }}</small>
            </button>
          </div>
          </div>
        </template>
      </div>
      <!-- F-2 (Founder A): step 2 right after step 1, same person, but its own button and its own request. -->
      <AtDialog :open="!!justRecorded" title="已登記，待確認入帳" size="sm" title-id="ledger-recorded-title" @close="justRecorded = null">
        <p v-if="justRecorded" class="ledger-issue" data-testid="ledger-recorded-text">
          {{ payload?.student?.name || '這位學生' }}已登記{{ justRecordedAmount != null ? ` ${formatCurrency(justRecordedAmount)}` : '' }}，現在是「待確認入帳」。確認收到款項後，按「現在確認入帳」才算已收；也可以稍後在課程卡確認。
        </p>
        <p v-if="justRecorded?.error" class="ledger-error-text" role="alert" data-testid="ledger-recorded-error">{{ justRecorded.error }}</p>
        <template #actions>
          <AtButton variant="secondary" size="sm" shape="rect" data-testid="ledger-recorded-later" :disabled="justRecorded?.busy === true" @click="justRecorded = null">稍後再確認</AtButton>
          <AtButton size="sm" shape="rect" :loading="justRecorded?.busy === true" data-testid="ledger-recorded-confirm" @click="confirmJustRecorded">現在確認入帳</AtButton>
        </template>
      </AtDialog>
      <AtDialog :open="!!issueDraft" title="開立這期帳單？" size="sm" title-id="ledger-issue-title" @close="closeIssue">
        <p v-if="issueDraft" class="ledger-issue" data-testid="ledger-issue-text">
          {{ issueDraft.subject }}・{{ fmtDate(issueDraft.proposed_start_date) }}–{{ fmtDate(issueDraft.proposed_end_date) }}・{{ issueDraft.period_sessions }} 堂・{{ formatCurrency(issueDraft.amount) }}<template v-if="issueDraft.due_date">・繳費期限 {{ fmtDate(issueDraft.due_date) }}</template>
        </p>
        <p v-if="issueError" class="ledger-error-text" role="alert">{{ issueError }}</p>
        <template #actions>
          <AtButton variant="secondary" size="sm" shape="rect" @click="closeIssue">取消</AtButton>
          <AtButton size="sm" shape="rect" :loading="issueBusy" data-testid="ledger-issue-submit" @click="submitIssue">開立帳單</AtButton>
        </template>
      </AtDialog>
      <PaymentEntryModal :show="entryOpen" :row="entryRow" :mode="entryMode" @close="entryOpen = false" @confirmed="onEntryRecorded" @pending="onPanelChanged" @conflict="onEntryConflict" />
      <ReceiptModal :show="receiptOpen" :report-id="receiptReportId" :later-label="receiptLaterLabel" :return-focus-to="modalEl" @close="receiptOpen = false" />
      <PaymentSlipModal :show="slipOpen" :invoice-id="slipInvoiceId" :student-class-id="slipStudentClassId" :return-focus-to="modalEl" @close="slipOpen = false" />
    </div>
  </Transition>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { formatDate as fmtDate } from '../lib/billingDocumentView.js';
import { splitOwed } from '../lib/studentBillingRows.js';
import { isDirectorRole } from '../lib/roleCapabilities.js';
import {
  formatAccountingLedgerInvoiceLabel,
  formatLedgerReceiptBillLine,
  formatLedgerAnomalyDetail,
  formatLedgerDriftLine,
  buildLedgerIssueReport,
} from '../lib/studentClassDisplay.js';
import { humanizeApiErrorMessage } from '../lib/humanizeApiErrorMessage.js';
import { confirmReport, voidReport as voidReportAction, voidInvoice as voidInvoiceAction } from '../lib/paymentActions.js';
import { FREE_GIFT_LABEL, INVOICE_STATUS_LABELS, REPORT_STATUS_LABELS, ZERO_REASON_FREE_GIFT, LEGACY_CONFIRMED_REPORT_HELP, canResolveLegacyPaidFlag, isWaivedContract, latestStandingPayment } from '../lib/courseMoneyState.js';
import LedgerCoverageDates from './LedgerCoverageDates.vue';
import BillingNotesPanel from './BillingNotesPanel.vue';
import ContractCard from './tuition/ContractCard.vue';
import { issuedBillRateNote } from '../lib/issuedBillRateNote.js';
import ReceiptModal from './ReceiptModal.vue';
import PaymentSlipModal from './PaymentSlipModal.vue';
import AtButton from './design-system/AtButton.vue';
import AtDialog from './design-system/AtDialog.vue';
import AtHelpDisclosure from './design-system/AtHelpDisclosure.vue';
import { useMonthlyRenewal } from '../composables/course-management/useMonthlyRenewal';
import PaymentEntryModal from './PaymentEntryModal.vue';
import PaymentAllocation from './tuition/PaymentAllocation.vue';
import {
  canShowReceiptCoverage,
  coverageFromReceipt,
  coverageFromSlip,
  invoiceCoverageUrl,
  receiptCoverageUrl,
} from '../lib/ledgerCoverageDates.js';

const EXCEPTION_PREVIEW = 2;

const props = defineProps({
  show: Boolean, studentClassId: [Number, String], reportId: [Number, String], studentId: [Number, String], branchId: [Number, String], canStep: Boolean, studentWide: Boolean,
  // In-app #398: 'record' opens 登記收款 for the opened course once the ledger has loaded (only if its card offers it).
  initialAction: { type: String, default: null },
  // F-5a1: { [studentClassId]: { waive, void, settle } } from the page's alert rows (the old table's rules).
  rowActions: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'changed', 'step', 'row-action']);
defineExpose({ reload: () => loadLedger(), focus: () => modalEl.value?.focus() });

// a11y: Esc closes, Tab stays inside the panel, focus returns to the opener.
const modalEl = ref(null);
let returnFocusEl = null;
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
function onEsc() {
  if (!entryOpen.value) emit('close');
}
// ↑/↓ moves to the adjacent student (Linear-style peek). Only when the drawer itself or a step button has focus,
// so arrows still work inside fields and selects.
function onStepKey(event) {
  if (!props.canStep || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
  const t = event.target;
  if (t !== modalEl.value && !t.classList?.contains('ledger-step')) return;
  event.preventDefault();
  emit('step', event.key === 'ArrowDown' ? 1 : -1);
}
function trapTab(event) {
  const panel = modalEl.value;
  if (!panel) return;
  const items = [...panel.querySelectorAll(FOCUSABLE)];
  if (!items.length) { event.preventDefault(); panel.focus(); return; }
  const first = items[0];
  const last = items[items.length - 1];
  if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
// A different student keeps the drawer open: start at the top.
watch(() => [props.studentClassId, props.studentId], () => { if (modalEl.value) modalEl.value.scrollTop = 0; });
watch(() => props.show, async (open) => {
  if (open) {
    returnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    await nextTick();
    modalEl.value?.focus();
  } else if (returnFocusEl && !props.canStep) {
    const el = returnFocusEl;
    returnFocusEl = null;
    if (el.isConnected) el.focus();
  }
}, { immediate: true });

const loading = ref(false);
const error = ref('');
// #4551: a failed action (void report/invoice) is not a failed load: keep the ledger on screen, reread it, show the reason.
const actionError = ref('');
const payload = ref(null);
const busyReportId = ref(null);
const busyInvoiceId = ref(null);
const expandedIds = ref(new Set());
const showAllExceptions = ref(false);
// in-app #446: the one action a director can take on a drift they cannot undo; the launcher opens pre-filled with IDs.
const reportToAdmin = (x) => {
  const inv = (payload.value?.invoices || []).find((i) => Number(i.id) === Number(x.anomaly?.invoice_id));
  window.dispatchEvent(new CustomEvent('alltrue-open-bug-report', { detail: buildLedgerIssueReport(x.anomaly, inv, payload.value?.student?.id) }));
};
const showOtherReceipts = ref(false);
// 涵蓋上課日, loaded lazily per expanded row: { 'inv-41': { state, sessions, showAll } }.
const coverage = ref({});
const expandedReceiptIds = ref(new Set());

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

function needsAttention(inv) {
  return (inv.overpaid_amount || 0) > 0
    || (inv.outstanding_amount || 0) > 0
    || (inv.anomalies?.length || 0) > 0
    || (inv.payments || []).some((p) => p.application_status === 'overpayment_pending_review');
}

function isExpanded(id) {
  return expandedIds.value.has(Number(id));
}

function toggleExpand(id) {
  const next = new Set(expandedIds.value);
  const key = Number(id);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  expandedIds.value = next;
  if (next.has(key)) ensureCoverage('inv', key);
}

function autoExpandAttention() {
  const next = new Set();
  for (const inv of payload.value?.invoices || []) {
    if (needsAttention(inv) && inv.payments?.length) next.add(Number(inv.id));
  }
  expandedIds.value = next;
  next.forEach((id) => ensureCoverage('inv', id));
}

function isReceiptExpanded(id) {
  return expandedReceiptIds.value.has(Number(id));
}

function toggleReceipt(id) {
  const next = new Set(expandedReceiptIds.value);
  const key = Number(id);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  expandedReceiptIds.value = next;
  if (next.has(key)) ensureCoverage('rcpt', key);
}

function ensureCoverage(kind, id) {
  const entry = coverage.value[`${kind}-${id}`];
  if (!entry || entry.state === 'error') loadCoverage(kind, id);
}

function toggleCoverageAll(key) {
  const entry = coverage.value[key];
  if (entry) coverage.value = { ...coverage.value, [key]: { ...entry, showAll: !entry.showAll } };
}

// NFR-005: a failure here only marks this block; ledger amounts stay as loaded.
async function loadCoverage(kind, id) {
  const key = `${kind}-${id}`;
  const ledgerAtStart = payload.value;
  coverage.value = { ...coverage.value, [key]: { state: 'loading', sessions: [], showAll: false } };
  let next;
  try {
    const token = getToken();
    if (!token) throw new Error('no_token');
    const url = kind === 'inv' ? invoiceCoverageUrl(id) : receiptCoverageUrl(id);
    const resp = await fetch(url, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    if (!resp.ok) throw new Error(`coverage_${resp.status}`);
    const json = await resp.json();
    const sessions = kind === 'inv' ? coverageFromSlip(json) : coverageFromReceipt(json, id);
    next = { state: 'ready', sessions, showAll: false };
  } catch {
    next = { state: 'error', sessions: [], showAll: false };
  }
  // Ignore a response that lands after the ledger was reloaded for another student.
  if (payload.value === ledgerAtStart) coverage.value = { ...coverage.value, [key]: next };
}

let ledgerRequestVersion = 0;
async function loadLedger() {
  const requestVersion = ++ledgerRequestVersion;
  loading.value = false;
  payload.value = null;
  if (!props.show) return;
  const studentClassId = Number(props.studentClassId || 0);
  const reportId = Number(props.reportId || 0);
  const studentId = Number(props.studentId || 0);
  if (!studentClassId && !reportId && !studentId) {
    error.value = '缺少課程或收款資訊，無法開啟學生帳務。';
    return;
  }

  loading.value = true;
  error.value = '';
  actionError.value = '';
  showAllExceptions.value = false;
  coverage.value = {};
  expandedReceiptIds.value = new Set();
  try {
    const token = getToken();
    if (!token) throw new Error('請先登入');
    const params = new URLSearchParams();
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (reportId) params.set('report_id', String(reportId));
    if (studentId && !studentClassId && !reportId) params.set('student_id', String(studentId));
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));

    const resp = await fetch(`/api/v1/accounting/ledger?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const json = await resp.json().catch(() => ({}));
    if (requestVersion !== ledgerRequestVersion) return;
    if (!resp.ok) throw new Error(humanizeApiErrorMessage(json.message || `載入失敗（${resp.status}）`));
    payload.value = json;
    autoExpandAttention();
    runInitialAction(loadDrafts());
  } catch (e) {
    if (requestVersion !== ledgerRequestVersion) return;
    error.value = humanizeApiErrorMessage(e.message || '載入學生帳務失敗');
  } finally {
    if (requestVersion === ledgerRequestVersion) loading.value = false;
  }
  if (requestVersion === ledgerRequestVersion && payload.value) {
    await nextTick();
    scrollToOpenedContract();
  }
}

watch(() => [props.show, props.studentClassId, props.reportId, props.studentId, props.branchId], loadLedger, { immediate: true });

// In-app #396: opened from one course, the panel marks that contract card and scrolls to it,
// so the director does not have to scroll through every contract to find it.
// #3987: a student-wide launch passes an arbitrary first course only to load the ledger; it is not a course scope.
const isOpenedCourse = (c) => !props.studentWide && Number(props.studentClassId || 0) > 0 && Number(c?.id) === Number(props.studentClassId);
function scrollToOpenedContract() {
  if (props.studentWide || !Number(props.studentClassId || 0) || contractCourses.value.length < 2) return;
  if (isOpenedCourse(contractCourses.value[0])) return;
  const card = modalEl.value?.querySelector(`[data-testid="contract-card-${Number(props.studentClassId)}"]`);
  card?.scrollIntoView?.({ block: 'start' });
}

// #3912: a combined bill belongs to every course it has a line for (covered_student_class_ids), not only its anchor.
const invoiceCoversCourse = (inv, id) => {
  const covered = Array.isArray(inv?.covered_student_class_ids) && inv.covered_student_class_ids.length
    ? inv.covered_student_class_ids : [inv?.student_class_id];
  return covered.some((x) => Number(x) === Number(id));
};
// 開啟的是單一課程時，列表裡別的課程的帳單要標「其他課程」（#3894）。
const isOtherCourseInvoice = (inv) => {
  const scopeId = props.studentWide ? 0 : Number(payload.value?.scope?.student_class_id || 0);
  return !!scopeId && !invoiceCoversCourse(inv, scopeId);
};
// Empty-state is judged for the opened course when the API reports one, else student-wide.
const ledgerBothEmpty = computed(() => {
  const scopeId = props.studentWide ? 0 : Number(payload.value?.scope?.student_class_id || 0);
  const invoices = (payload.value?.invoices || []).filter((inv) => !scopeId || invoiceCoversCourse(inv, scopeId));
  const receipts = (payload.value?.receipts || []).filter((r) => !scopeId || Number(r.student_class_id) === scopeId);
  return !invoices.length && !receipts.length;
});

const ledgerExceptions = computed(() => {
  const rows = [];
  (payload.value?.anomalies || []).forEach((a, idx) => {
    rows.push({
      key: `a-${idx}-${a.code}-${a.report_id || 'na'}-${a.payment_id || 'na'}`,
      title: anomalyLabel(a.code),
      message: a.message,
      detail: formatLedgerAnomalyDetail(a),
      report_id: a.report_id || null,
      can_void: a.action?.type === 'void_report',
      drift_line: formatLedgerDriftLine((payload.value?.invoices || []).find((i) => Number(i.id) === Number(a.invoice_id))),
      anomaly: a,
      severity: a.severity === 'critical' ? 0 : 1,
    });
  });
  (payload.value?.invoices || []).forEach((inv) => {
    (inv.payments || []).forEach((p) => {
      if (p.application_status !== 'overpayment_pending_review') return;
      rows.push({
        key: `p-${p.id}`,
        title: `多收 ${formatCurrency(p.unapplied_amount)}`,
        message: `${fmtDate(p.paid_at)} ${paymentMethodLabel(p.method)} ${formatCurrency(p.amount)}，比帳單多 ${formatCurrency(p.unapplied_amount)}`,
        detail: formatAccountingLedgerInvoiceLabel(inv),
        action_label: '撤銷這筆收款',
        hint: '多收的錢沒有記進任何帳單。若是重複收款，按「撤銷這筆收款」。',
        report_id: p.report_id || null,
        can_void: !!p.report_id && !p.is_void,
        drift_line: formatLedgerDriftLine(inv),
        anomaly: { code: 'overpayment_pending_review', invoice_id: inv.id, student_class_id: inv.student_class_id },
        severity: 1,
      });
    });
  });
  return rows.sort((a, b) => a.severity - b.severity);
});

// Default list = money that counts (已收 / 待核對入帳); rejected, voided and 0 元 rows sit under 其他紀錄.
const isMainReceipt = (r) => ['confirmed', 'pending'].includes(r.status) && Number(r.amount || 0) > 0;
const otherReceipts = computed(() => (payload.value?.receipts || []).filter((r) => !isMainReceipt(r)));
const shownReceipts = computed(() => [...(payload.value?.receipts || []).filter(isMainReceipt), ...(showOtherReceipts.value ? otherReceipts.value : [])]);
const panelTitle = computed(() => (payload.value?.student?.name ? `${payload.value.student.name}的帳務` : '學生帳務'));
const owedSplit = computed(() => splitOwed((payload.value?.invoices || [])
  .filter((inv) => inv.status !== 'void')
  .map((inv) => ({ amount: inv.outstanding_amount, due_date: inv.due_date }))));
// 開啟單一課程、但未繳來自其他課程時，標題要說清楚是整個學生的金額。
const owedLabel = computed(() => {
  const scopeId = props.studentWide ? 0 : Number(payload.value?.scope?.student_class_id || 0);
  const others = (payload.value?.invoices || []).filter((inv) => inv.status !== 'void' && Number(inv.student_class_id) !== scopeId);
  const dueNow = splitOwed(others.map((inv) => ({ amount: inv.outstanding_amount, due_date: inv.due_date }))).now > 0; // 只算到今天已到期的，同 owedSplit
  return scopeId && dueNow ? '此學生到今天未繳（含其他課程）' : '到今天未繳';
});
const overpaidTotal = computed(() => Number(payload.value?.summary?.overpaid_total || 0));
// F-1: one-line conclusion. Only sums figures already on screen (owedSplit.now, pending receipts); no new money rule.
const pendingConfirmTotal = computed(() => (payload.value?.receipts || [])
  .filter((r) => r.status === 'pending').reduce((sum, r) => sum + Number(r.amount || 0), 0));
// F-1: the headline summarises the per-course state the cards below already show, so it cannot contradict them.
// Tutoring (#4699): the API flags every course (courses[].no_payment_obligation, the scope.no_payment_obligation rule);
// the scope flag still covers the opened course for an older payload.
const hasNoObligation = (c) => c.no_payment_obligation === true || c.class_type === 'tutoring'
  || (payload.value?.scope?.no_payment_obligation === true && Number(c.id) === Number(payload.value?.scope?.student_class_id));
const courseNeedsMoney = (c) => !(hasNoObligation(c) || isWaivedContract(c));
const conclusion = computed(() => {
  const cs = (payload.value?.courses || []).filter(courseNeedsMoney);
  const parts = [];
  if (cs.some((c) => owedFor(c.id) <= 0 && c.paid && payLineFor(c)?.legacyUnverified)) parts.push('需核對'); // same as the card's payline
  if (owedSplit.value.now > 0) parts.push(`未繳 ${formatCurrency(owedSplit.value.now)}`);
  if (pendingConfirmTotal.value > 0) parts.push(`待確認入帳 ${formatCurrency(pendingConfirmTotal.value)}`);
  if (cs.some((c) => !c.paid && owedFor(c.id) <= 0)) parts.push('還沒開帳單'); // same as the card's 還沒開帳單
  if (parts.length) return parts.join('・');
  return owedSplit.value.later > 0 ? '到今天沒有未繳' : '已收齊'; // a future-due bill is not 已收齊
});

const owedFor = (id) => (payload.value?.invoices || [])
  .filter((inv) => Number(inv.student_class_id) === Number(id))
  .reduce((sum, inv) => sum + Number(inv.outstanding_amount || 0), 0);
const courseInvoices = (id) => (payload.value?.invoices || [])
  .filter((inv) => Number(inv.student_class_id) === Number(id) && inv.status !== 'void');
// Reversals are netted per invoice (latestStandingPayment, same rule as the server's last-paid resolver), never filtered by row.
const lastInvoicePayment = (ids) => ids.flatMap((id) => courseInvoices(id)).map((inv) => latestStandingPayment(inv.payments))
  .filter(Boolean).sort((a, b) => b.date.localeCompare(a.date))[0] || null;
// Pay line (spec 2.3): latest confirmed receipt = same source as the printed receipt; invoice payments next.
// #4150: the contract's PayDate / Charge / Paid flag are legacy metadata, not proof of a payment, so they never
// become a 繳費日 line; the card shows the neutral 需核對 note instead (G-009 dual truth, display only).
// Waiver and a current-term 免費／贈送 are server verdicts on the course row (ContractMoneyState::isWaived,
// Invoice::freeGiftHolds); neither is guessed here from balances, Stop or a gift marker on an old bill.
// A shared count-mode package is one obligation (#3753): markPackagePaid() flags every member, while the receipt and
// Payment stay on the member used for collection, so evidence is read across packageMemberIds (as pendingReportFor).
const payLineFor = (course) => {
  const ids = packageMemberIds(course.id);
  const rc = (payload.value?.receipts || [])
    .filter((r) => ids.includes(Number(r.student_class_id)) && r.status === 'confirmed' && Number(r.amount) > 0 && r.payment_date)
    .sort((a, b) => String(b.payment_date).localeCompare(String(a.payment_date)) || Number(b.report_id) - Number(a.report_id))[0];
  if (rc) return { date: String(rc.payment_date).slice(0, 10), amount: Number(rc.amount) };
  const paid = lastInvoicePayment(ids);
  if (paid) return paid;
  if (isWaivedContract(course)) return { waived: true };
  // in-app #428: a trial that carries its payment date has no invoice by design; the server derives this
  // (StudentClass::isTrialFeeRecordedByDate, same rule as legacy_paid_flag_only), so it is not 需核對 legacy data.
  // #4339 review: show that recorded date and the trial fee, never 還沒繳費 beside 已收清.
  // A package-created trial member carries Charge=0 (the fee lives on the package): show the date only, never NT$ 0.
  if (course.trial_fee_recorded_by_date === true && course.paid_at) return { date: course.paid_at, amount: Number(course.charge) > 0 ? Number(course.charge) : null };
  if (course.full_discount_settled === true) return { fullDiscount: true }; // #4656: settled by its NT$0 renewal bill
  return (course.paid_at || course.paid) && course.free_gift_settled !== true ? { legacyUnverified: true } : null;
};
const amountsFor = (id) => {
  const list = courseInvoices(id);
  if (!list.length) return null;
  const sum = (k) => list.reduce((t, inv) => t + Number(inv[k] || 0), 0);
  return { due: sum('total_amount'), received: sum('calculated_applied_amount'), owed: sum('outstanding_amount') };
};
const monthLinesFor = (id) => Object.fromEntries(courseInvoices(id).map((inv) => {
  const key = String(inv.billing_period || inv.period_start || '').slice(0, 7);
  const paid = latestStandingPayment(inv.payments);
  return [key, { due: Number(inv.total_amount || 0), paidDate: paid ? paid.date : '', paidAmount: Number(inv.calculated_applied_amount || 0) }];
}).filter(([k]) => k));
// #3753 (D18): members of one shared CoursePackage are one obligation; one pending report covers them all.
const packageMemberIds = (id) => {
  const pkg = (payload.value?.courses || []).find((c) => Number(c.id) === Number(id))?.package_id;
  return pkg ? (payload.value?.courses || []).filter((c) => c.package_id === pkg).map((c) => Number(c.id)) : [Number(id)];
};
const pendingReportFor = (id) => {
  const ids = packageMemberIds(id);
  const r = (payload.value?.receipts || []).find((x) => ids.includes(Number(x.student_class_id)) && x.status === 'pending');
  return r ? { report_id: Number(r.report_id), amount: Number(r.amount || 0), date: r.payment_date || '' } : null;
};
// Mirror the existing server refusal by report status (any member of a shared package counts), independently of payment display completeness.
const hasConfirmedReportFor = (id) => {
  const ids = packageMemberIds(id);
  return (payload.value?.receipts || []).some((r) => ids.includes(Number(r.student_class_id)) && r.status === 'confirmed');
};
// #4115: the issued-bill vs current-contract note for the bill row (display only).
const rateNoteFor = (inv) => {
  const course = (payload.value?.courses || []).find((c) => Number(c.id) === Number(inv.student_class_id));
  const note = course ? issuedBillRateNote(course, payload.value?.invoices) : null;
  return note && note.invoiceId === Number(inv.id) ? note : null;
};
// Contracts with money still due come first (PRD v2 §0.3).
const contractCourses = computed(() => [...(payload.value?.courses || [])].sort((a, b) => owedFor(b.id) - owedFor(a.id)));
// One card of a shared package carries its single payment action; the other members point to it. Lead = the member
// holding a pending report, else the first one that still needs payment (mixed states), else the first member.
// #3892 review: a legacy package whose bills are already open on several members stays actionable per member (each
// card records against its own bills) until reconciled; package-wide allocation is a follow-up.
const packageLeadOf = (c) => {
  if (!c.package_id) return c;
  const members = contractCourses.value.filter((x) => x.package_id === c.package_id);
  const pending = (payload.value?.receipts || []).find((r) => r.status === 'pending' && members.some((m) => Number(m.id) === Number(r.student_class_id)));
  if (!pending && members.filter((m) => owedFor(m.id) > 0).length > 1) return c;
  return (pending && members.find((m) => Number(m.id) === Number(pending.student_class_id)))
    // #4002: a tutoring member has no payment obligation (the server answers tutoring_no_payment_obligation),
    // so a payable regular member leads; the lines below stay as fallbacks (e.g. an all-tutoring package).
    || members.find((m) => !hasNoObligation(m) && !isWaivedContract(m) && (owedFor(m.id) > 0 || !m.paid))
    // #4205: a waiver is terminal (Paid=0 only), so a member that still owes leads before a waived one.
    || members.find((m) => !isWaivedContract(m) && (owedFor(m.id) > 0 || !m.paid))
    || members.find((m) => owedFor(m.id) > 0 || !m.paid) || members[0];
};
const packageLead = (c) => packageLeadOf(c).id === c.id;
// F-3: the lead card of a shared package names the members that follow it (empty when members are billed separately).
const sharedMembersOf = (c) => (c.package_id && packageLead(c)
  ? contractCourses.value.filter((m) => m.package_id === c.package_id && m.id !== c.id && !packageLead(m)).map((m) => m.subject) : []);
// Multiple existing member bills cannot share one batch: the first report blocks siblings. Keep explicit card actions.
const hasMultiplePackageBills = (c) => !!c.package_id && contractCourses.value.filter((m) => m.package_id === c.package_id && owedFor(m.id) > 0).length > 1;

// D18: every contract the card would offer 登記收款 for (no pending report, money due or not yet billed, not waived),
// oldest open invoice first; the allocation step fills the oldest and never exceeds what is owed.
const oldestOpenInvoice = (courseId) => (payload.value?.invoices || [])
  // directorRecord rejects invoices already marked paid or void.
  .filter((inv) => Number(inv.student_class_id) === Number(courseId) && Number(inv.outstanding_amount || 0) > 0 && !['paid', 'void'].includes(inv.status))
  .sort((a, b) => String(a.due_date || a.billing_period || a.issue_date || '').localeCompare(String(b.due_date || b.billing_period || b.issue_date || ''))
    || Number(a.id) - Number(b.id))[0];
const needsLegacyChoice = (course) => !payload.value?.scope?.no_payment_obligation && canResolveLegacyPaidFlag(course, {
  legacyUnverified: payLineFor(course)?.legacyUnverified === true, canResolveLegacy: canManageInvoices(),
  waived: isWaivedContract(course), pendingReport: pendingReportFor(course.id), sharedFollower: !packageLead(course),
});
const allocContracts = computed(() => (payload.value?.scope?.no_payment_obligation ? [] : contractCourses.value
  .filter((c) => !hasNoObligation(c) && !needsLegacyChoice(c) && !hasMultiplePackageBills(c) && packageLead(c) && !pendingReportFor(c.id) && !isWaivedContract(c) && (owedFor(c.id) > 0 || !c.paid))
  .map((c) => {
    const inv = oldestOpenInvoice(c.id);
    return { id: c.id, subject: c.subject, start_date: c.start_date, end_date: c.end_date, owed: Number(owedFor(c.id)) || 0, invoice_id: inv?.id || null, key: String(inv?.due_date || inv?.billing_period || inv?.issue_date || '~') };
  })
  .sort((a, b) => a.key.localeCompare(b.key) || a.id - b.id)));
// In-app #437: the director opened a course with nothing to collect and the button collected another one; say so plainly.
const payScopeText = computed(() => {
  const opened = contractCourses.value.find(isOpenedCourse);
  const subjects = [...new Set(allocContracts.value.map((c) => c.subject).filter(Boolean))].join('、');
  if (!opened || allocContracts.value.some((c) => c.id === opened.id) || !subjects) {
    return '此入口處理這位學生的可收款合約，不限於點開的課程；請在下一步確認科目與金額。';
  }
  // Only say "nothing to collect" when it is true; a shared-package or pending course is just left out of this button.
  return !(owedFor(opened.id) > 0) && (opened.paid || isWaivedContract(opened))
    ? `你點開的「${opened.subject}」目前沒有要收的款；這個按鈕是登記「${subjects}」的收款，下一步可再確認科目與金額。`
    : `這個按鈕是登記「${subjects}」的收款，不含你點開的「${opened.subject}」；「${opened.subject}」請看它自己的課程卡。`;
});
const allocating = ref(false);
// Back from the result page after money was reported (or confirmed there): the ledger behind it is stale.
// Money was booked from the allocation result: tell the list (and reload here unless 完成／back will).
function onAllocConfirmed() {
  emit('changed');
  if (!allocating.value) loadLedger();
}
async function onAllocClose(changed) {
  allocating.value = false;
  if (changed === true) await onPanelChanged();
}
async function onAllocDone() {
  allocating.value = false;
  await onPanelChanged();
}

// 開帳單 in place: only where the existing monthly-drafts endpoint has a ready draft for this contract;
// confirming uses the same renew-monthly request as 本月待開帳單 (no new money logic).
const drafts = ref({});
const issueDraft = ref(null);
const issueError = ref('');
const issueBusy = ref(false);
const { submit: submitRenewal } = useMonthlyRenewal({
  form: ref({}), warnings: ref([]), previewRequestId: ref(0),
  isModalOpen: () => false, currentCourseId: () => null,
});
const todayStr = () => new Date().toISOString().slice(0, 10);
async function loadDrafts() {
  drafts.value = {};
  try {
    const token = getToken();
    if (!token) return;
    const params = new URLSearchParams({ month: todayStr().slice(0, 7) });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    const resp = await fetch(`/api/v1/accounting/monthly-drafts?${params}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok || !Array.isArray(json.data)) return;
    drafts.value = Object.fromEntries(json.data
      .filter((r) => r.status === 'ready' && r.proposed_end_date && r.proposed_end_date > todayStr())
      .map((r) => [Number(r.student_class_id), r]));
  } catch { drafts.value = {}; }
}
const draftFor = (id) => drafts.value[Number(id)] || null;
const openIssue = (draft) => { issueError.value = ''; issueDraft.value = draft; };
const closeIssue = () => { issueDraft.value = null; };
async function submitIssue() {
  const d = issueDraft.value;
  if (!d || issueBusy.value) return;
  issueBusy.value = true;
  issueError.value = '';
  try {
    const out = await submitRenewal({ id: d.student_class_id }, d.proposed_end_date);
    if (out.status !== 'ok') { issueError.value = out.message || '請重新登入後再試'; return; }
    issueDraft.value = null;
    await onPanelChanged();
  } catch {
    issueError.value = '連線失敗，請稍後再試';
  } finally {
    issueBusy.value = false;
  }
}

// PRD v2 D2/D9: 登記收款 (step 1) opens the existing entry form inside the panel.
const entryOpen = ref(false);
const entryRow = ref(null);
const entryMode = ref('record');
function openEntry(course, mode = 'record') {
  if (mode === 'legacy-backfill' && hasConfirmedReportFor(course.id)) {
    initialActionNote.value = LEGACY_CONFIRMED_REPORT_HELP;
    return;
  }
  entryMode.value = mode;
  // Oldest open invoice first (PRD v2 D18); no invoice yet → amount left for the director.
  const oldest = oldestOpenInvoice(course.id);
  // in-app #432: no open bill on a monthly contract → the month and amount the server will check (collection_period).
  const collect = oldest ? null : course.collection_period;
  entryRow.value = {
    id: course.id,
    charge: mode === 'legacy-backfill' ? (course.current_contract_charge ?? course.charge) : course.charge,
    student_name: payload.value?.student?.name,
    subject: course.subject,
    invoice_id: oldest?.id,
    billing_period: oldest ? oldest.billing_period : collect?.billing_period,
    ...(collect ? { estimated_amount: Number(collect.amount) } : {}),
    payable_status: oldest ? 'invoiced' : 'unbilled',
    payable_amount: oldest ? Number(oldest.outstanding_amount) : null,
    payable_outstanding: oldest ? Number(oldest.outstanding_amount) : null,
  };
  entryOpen.value = true;
}
// #398/#405: same step as the 待處理 queue — after 確認入帳 the receipt opens here; 稍後再開 keeps it in 收款紀錄.
const receiptOpen = ref(false);
const receiptReportId = ref(null);
const receiptLaterLabel = ref('');
function openReceipt(reportId) {
  receiptReportId.value = Number(reportId);
  receiptLaterLabel.value = '稍後再開';
  receiptOpen.value = true;
}
// In-app #425: viewing a confirmed row's receipt is not the post-確認入帳 step, so no 稍後再開 label.
function viewReceipt(reportId) {
  openReceipt(reportId);
  receiptLaterLabel.value = '';
}
// In-app #425: 繳費單 on a contract card, same modal and request as the 帳務中心 list (oldest open bill first).
const slipOpen = ref(false);
const slipInvoiceId = ref(null);
const slipStudentClassId = ref(null);
function openSlip(course) {
  const inv = oldestOpenInvoice(course.id);
  slipInvoiceId.value = inv ? Number(inv.id) : null;
  slipStudentClassId.value = inv ? null : Number(course.id);
  slipOpen.value = true;
}
watch(() => [props.show, props.studentClassId, props.studentId], () => { receiptOpen.value = false; });
watch(() => [props.show, props.studentClassId, props.studentId], () => { slipOpen.value = false; });

// In-app #398: 「登記繳費回報」 from 課程查找 lands on the entry form, with the ledger behind it. Armed once per open;
// a course whose card would not offer 登記這筆 (pending report, waived, tutoring, package member) just shows the ledger.
let pendingInitialAction = null;
watch(() => props.show, (open) => { pendingInitialAction = open ? props.initialAction : null; }, { immediate: true });
const initialActionNote = ref('');
watch(() => props.show, () => { initialActionNote.value = ''; });
watch(() => entryOpen.value && entryMode.value === 'legacy-backfill' && hasConfirmedReportFor(entryRow.value?.id), (blocked) => {
  if (blocked) { entryOpen.value = false; initialActionNote.value = LEGACY_CONFIRMED_REPORT_HELP; }
});
// F-2: 'confirm' (list row 確認入帳) asks the card's own confirm question straight away.
const autoConfirmId = ref(null);
watch(() => props.show, () => { autoConfirmId.value = null; });
async function runInitialAction(draftsReady) {
  const action = pendingInitialAction;
  pendingInitialAction = null;
  const id = Number(props.studentClassId || 0);
  if (action === 'confirm') {
    // A shared package shows its one pending report on the lead card, which may not be the alert's anchor id.
    const course = contractCourses.value.find((c) => Number(c.id) === id);
    const leadId = course ? Number(packageLeadOf(course).id) : id;
    if (pendingReportFor(leadId)) autoConfirmId.value = leadId;
    return;
  }
  if (action === 'issue') {
    // Spec T3: 開帳單 on the list row asks the same 開立這期帳單？ question as the card button.
    await draftsReady;
    // The drawer may have moved to another student (↑/↓) or closed while the drafts loaded.
    if (!props.show || Number(props.studentClassId || 0) !== id) return;
    const draft = draftFor(id);
    if (draft) openIssue(draft);
    else initialActionNote.value = '這門課目前沒有可以開立的帳單。';
    return;
  }
  if (action !== 'record') return;
  const course = contractCourses.value.find((c) => Number(c.id) === id);
  if (!course) return;
  if (needsLegacyChoice(course)) {
    if (hasConfirmedReportFor(course.id)) {
      initialActionNote.value = LEGACY_CONFIRMED_REPORT_HELP;
      return;
    }
    initialActionNote.value = '這門課只有舊資料標記已收，請先在課程卡確認是否已收款，再選擇補登收款或改成未繳。';
    return;
  }
  if (allocContracts.value.some((c) => Number(c.id) === id)) {
    openEntry(course);
    return;
  }
  // Say why the form did not open (the same conditions the card uses), instead of falling back silently.
  initialActionNote.value = recordBlockedReason(course);
}
function recordBlockedReason(c) {
  if (payload.value?.scope?.no_payment_obligation || hasNoObligation(c)) return '輔導課不用繳費，所以沒有打開登記表。';
  if (pendingReportFor(c.id)) return '這門課已有待確認入帳的回報，請在下面的課程卡按「確認入帳」或「退回」。';
  if (isWaivedContract(c)) return '這門課已確認不收，所以沒有打開登記表。';
  if (c.package_id) return '這是共用方案，請在下面的課程卡處理收款。';
  return '這門課目前沒有要收的款，所以沒有打開登記表。';
}

async function onEntryConflict() {
  await loadLedger();
  emit('changed');
}

// F-2: a recorded (pending) report offers 現在確認入帳; legacy backfill is already confirmed and offers nothing.
const justRecorded = ref(null);
const justRecordedAmount = computed(() => {
  const r = (payload.value?.receipts || []).find((x) => Number(x.report_id) === justRecorded.value?.reportId);
  return r ? Number(r.amount) : null;
});
const drawerScope = () => `${props.show}|${props.studentClassId}|${props.studentId}`;
async function onEntryRecorded(result) {
  const reportId = Number(result?.report_id);
  const offer = entryMode.value === 'record' && result?.status === 'pending' && reportId > 0 && canManageInvoices();
  const scope = drawerScope();
  await onPanelChanged();
  // The director may have stepped to another student while the ledger reloaded: offer only for a drawer that still shows this report.
  const stillHere = scope === drawerScope()
    && (payload.value?.receipts || []).some((r) => Number(r.report_id) === reportId && r.status === 'pending');
  if (offer && stillHere) justRecorded.value = { reportId, busy: false, error: '' };
}
let promptSeq = 0;
async function confirmJustRecorded() {
  const cur = justRecorded.value;
  if (!cur || cur.busy) return;
  const seq = ++promptSeq;
  justRecorded.value = { ...cur, busy: true, error: '', seq };
  const res = await confirmReport(cur.reportId);
  // A dismissed or replaced prompt must not come back (or confirm a report the drawer no longer shows).
  const same = justRecorded.value?.seq === seq;
  if (!res.ok) { if (same) justRecorded.value = { ...cur, busy: false, error: res.message }; return; }
  if (same) justRecorded.value = null;
  const scope = drawerScope();
  await onPanelChanged();
  if (same && scope === drawerScope()) openReceipt(cur.reportId);
}
watch(() => props.show, () => { justRecorded.value = null; });

async function onPanelChanged() {
  entryOpen.value = false;
  await loadLedger();
  emit('changed');
}

const visibleExceptions = computed(() => (
  showAllExceptions.value
    ? ledgerExceptions.value
    : ledgerExceptions.value.slice(0, EXCEPTION_PREVIEW)
));

function getAuthRole() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.user?.role || session?.role || '';
}

async function voidReport(reportId) {
  if (!reportId || !isDirectorRole(getAuthRole())) return;
  const reason = window.prompt('撤銷這筆收款（帳單金額不變）。請寫原因：');
  if (!reason || !reason.trim()) return;
  busyReportId.value = reportId;
  try {
    const res = await voidReportAction(reportId, reason.trim());
    if (!res.ok) throw new Error(res.message);
    await loadLedger();
    emit('changed');
  } catch (e) {
    const msg = humanizeApiErrorMessage(e.message || '撤銷失敗');
    await loadLedger(); // the outcome may be uncertain (network/5xx): reread the authoritative ledger before the next write
    if (!error.value) actionError.value = msg;
  } finally {
    busyReportId.value = null;
  }
}

const canManageInvoices = () => isDirectorRole(getAuthRole());
const canDirectVoidInvoice = (invoice) => canManageInvoices() && !!invoice?.can_direct_void;
const canExceptionVoidInvoice = (invoice) => canManageInvoices() && !!invoice?.can_exception_void;
const isBusyInvoice = (invoice) => busyInvoiceId.value === invoice?.id;

async function voidInvoice(invoice, mode) {
  if (!invoice?.id || !canManageInvoices()) return;
  const isException = mode === 'exception';
  const actionLabel = isException ? '更正並作廢' : '作廢';
  const reason = window.prompt(`請輸入${actionLabel}原因（會保留稽核紀錄）`);
  if (!reason || reason.trim().length < 3) return;

  busyInvoiceId.value = invoice.id;
  try {
    const res = await voidInvoiceAction(invoice.id, reason.trim(), { exception: isException });
    if (!res.ok) throw new Error(res.message);
    await loadLedger();
    emit('changed');
  } catch (e) {
    const msg = humanizeApiErrorMessage(e.message || `${actionLabel}失敗`);
    await loadLedger(); // the outcome may be uncertain (network/5xx): reread the authoritative ledger before the next write
    if (!error.value) actionError.value = msg;
  } finally {
    busyInvoiceId.value = null;
  }
}

const labelMap = (map, key) => map[key] || key || '—';
const formatCurrency = (value) => 'NT$ ' + Number(value || 0).toLocaleString('zh-TW');
const signedCurrency = (value) => `${Number(value || 0) > 0 ? '+' : Number(value || 0) < 0 ? '-' : ''}${formatCurrency(Math.abs(Number(value || 0)))}`;
const formatPeriod = (period) => !period ? '—' : (String(period).split('-').length === 2 ? String(period).replace('-', '/') : period);
const paymentMethodLabel = (method) => labelMap({ cash: '現金', transfer: '匯款', void: '更正收款' }, method);
const invoiceStatusLabel = (status) => labelMap(INVOICE_STATUS_LABELS, status);
const reportStatusLabel = (status) => labelMap(REPORT_STATUS_LABELS, status);
const applicationStatusLabel = (status) => labelMap({ applied: '已收', partially_applied: '已收（多出部分未入帳）', overpayment_pending_review: '多收', voided: '已撤銷' }, status);
const invoiceStatusClass = (status) => labelMap({
  paid: 'chip--success',
  unpaid: 'chip--danger',
  partial: 'chip--warning',
  void: 'chip--muted',
}, status);
const reportStatusClass = (status) => labelMap({
  confirmed: 'chip--success',
  pending: 'chip--warning',
  voided: 'chip--muted',
  rejected: 'chip--muted',
}, status);
const applicationStatusClass = (status) => labelMap({
  applied: 'chip--success',
  partially_applied: 'chip--warning',
  overpayment_pending_review: 'chip--danger',
  voided: 'chip--muted',
}, status);
const anomalyLabel = (code) => labelMap({
  overpayment_pending_review: '多收',
  duplicate_effective_payments: '同一張帳單收了多次',
  paid_amount_mismatch: '帳單金額對不上',
  paid_status_with_balance: '標示已收，但還有餘額',
  open_status_without_balance: '標示未繳，但金額已收足',
  payment_without_receipt: '收款缺少收據',
  receipt_without_payment: '收據缺少收款',
  receipt_without_invoice: '收據尚未對到帳單',
  confirmed_receipt_without_payment: '已確認收據缺少收款',
  receipt_payment_outside_ledger: '收據收款不在本學生帳本',
}, code) || '需注意';
</script>

<style scoped>
.ledger-overlay{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.28);display:flex;justify-content:flex-end}
.ledger-modal{width:min(720px,100vw);height:100vh;overflow:auto;background:var(--surface,var(--ds-canvas));color:var(--text,var(--ds-ink));box-shadow:-16px 0 44px rgba(15,23,42,.22);padding:20px 22px 32px}
.ledger-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}
.ledger-header h3{margin:0;font-size:22px}
.ledger-modal:focus{outline:none}
.ledger-steps{display:flex;gap:4px;margin-left:auto}
.ledger-step{min-width:44px;min-height:44px;border:1px solid var(--ds-border);border-radius:8px;background:var(--ds-canvas);color:var(--ds-ink);font-size:18px;cursor:pointer}
.ledger-step:focus-visible{outline:2px solid var(--ds-cta);outline-offset:2px}
.ledger-close{border:0;background:transparent;font-size:28px;cursor:pointer;min-width:44px;min-height:44px;color:var(--text-light,var(--ds-ink-mute))}
.ledger-state,.ledger-empty{padding:24px;border:1px dashed var(--ds-canvas-soft);border-radius:12px;color:var(--text-light,var(--ds-ink-mute));text-align:center}
.ledger-error{color:var(--ds-danger);background:var(--ds-danger-wash);border-color:var(--ds-danger-wash)}

.ledger-issue{margin:0;font-size:14px;line-height:1.6}
.ledger-error-text{margin:8px 0 0;color:var(--ds-danger);font-size:13px}
.ledger-owed{display:grid;gap:2px;margin-bottom:14px}
.ledger-owed p{margin:0}
.ledger-owed__label{font-size:12px;color:var(--ds-ink-mute)}
.ledger-owed__now{font-size:20px;font-weight:700;font-variant-numeric:tabular-nums}
.ledger-conclusion{position:relative;display:flex;align-items:center;gap:6px;margin:0 0 6px;font-size:var(--ds-font-size-md)}
.ledger-owed__later{font-size:12px;color:var(--ds-ink-mute)}
.ledger-owed__over{margin-top:8px !important;padding:8px 10px;border-radius:8px;background:var(--ds-warning-wash);color:var(--ds-ink);font-size:13px}

.ledger-section{margin-top:20px}
.ledger-contracts{display:grid;gap:10px}
.ledger-section h4{margin:0 0 12px;font-size:var(--ds-font-size-xl);font-weight:var(--ds-font-weight-bold);line-height:var(--ds-line-tight);letter-spacing:.02em}
.ledger-section+.ledger-section{padding-top:16px;border-top:1px solid var(--ds-border)}
.ledger-more{margin-top:8px;border:0;background:transparent;color:var(--ds-primary-text);font-size:13px;font-weight:600;cursor:pointer;padding:0}
.ledger-table-wrap{overflow-x:auto}
.ledger-table{width:100%;border-collapse:collapse;font-size:13px}
.ledger-table th,.ledger-table td{border-bottom:1px solid var(--ds-border);padding:8px 10px;text-align:left;vertical-align:top}
.ledger-table th{color:var(--text-light,var(--ds-ink-mute));background:var(--ds-canvas-soft);font-weight:600;font-size:12px}
.ledger-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ledger-col-expand{width:44px;padding-left:6px;padding-right:4px}
.ledger-expand{display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;cursor:pointer;color:var(--ds-ink-mute);padding:2px 4px;border-radius:6px}
.ledger-expand:not(:disabled):hover{background:var(--ds-canvas-soft);color:var(--ds-ink)}
.ledger-pay-count{font-style:normal;font-size:12px;font-weight:700;color:var(--ds-ink-mute)}
.ledger-row--open td{background:var(--ds-canvas-soft)}
.ledger-row--attention td:nth-child(2) strong{color:var(--ds-ink)}
.ledger-overpay-hint{display:block;color:var(--ds-danger);font-weight:600}
.ledger-rate-hint{display:block;color:var(--ds-ink-mute)}
.ledger-other-course{display:inline-block;color:var(--ds-ink-mute);font-weight:600}
.due{color:var(--ds-danger);font-weight:700}

.ledger-chip{display:inline-flex;border-radius:6px;padding:2px 7px;background:var(--ds-canvas-soft);color:var(--ds-ink-mute);font-size:12px;font-weight:700}
.ledger-chip.chip--success{background:var(--ds-success-wash);color:var(--ds-success)}
.ledger-chip.chip--warning{background:var(--ds-warning-wash);color:var(--ds-warning)}
.ledger-chip.chip--danger{background:var(--ds-danger-wash);color:var(--ds-danger)}
.ledger-chip.chip--muted{background:var(--ds-canvas-soft);color:var(--ds-ink-mute)}

.ledger-detail-row td{background:var(--ds-canvas);padding:0 10px 12px 44px;border-bottom:1px solid var(--ds-border)}
.ledger-timeline__list{list-style:none;margin:0;padding:8px 0 0;display:grid;gap:8px}
.ledger-timeline__item{display:grid;grid-template-columns:96px 1fr;gap:10px;padding:8px 10px;border:1px solid var(--ds-border);border-radius:10px;background:var(--surface,var(--ds-canvas))}
.ledger-timeline__item.is-void{opacity:.65}
.ledger-timeline__item.is-void strong{text-decoration:line-through}
.ledger-timeline__item.is-overpay{border-color:var(--ds-warning);background:var(--ds-warning-wash)}
.ledger-timeline__when{font-size:12px;color:var(--text-light,var(--ds-ink-mute));font-variant-numeric:tabular-nums}
.ledger-timeline__body{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}
.ledger-timeline__meta{width:100%;display:flex;flex-wrap:wrap;gap:8px;font-size:12px;color:var(--text-light,var(--ds-ink-mute))}
.ledger-ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--ds-ink-mute)}

.ledger-receipts{display:grid;gap:8px}
.ledger-receipt{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--ds-border);border-radius:10px}
.ledger-receipt-toggle{margin-top:0;font-size:12px}
.ledger-receipt-coverage{flex-basis:100%;padding-top:0}
.ledger-receipt-extra{flex-basis:100%;white-space:normal;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ledger-receipts--compact .ledger-receipt{padding:8px 10px}
.ledger-muted,.ledger-receipt small,.ledger-table small{color:var(--text-light,var(--ds-ink-mute))}
.ledger-table small{display:block;margin-top:2px}
.ledger-voided-notes{padding:8px 10px;border:1px dashed var(--ds-border);border-radius:var(--ds-radius-lg);margin-bottom:8px}
.ledger-voided-notes__list{list-style:none;margin:6px 0 0;padding:0;display:grid;gap:6px}
.ledger-voided-notes__list p{margin:2px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}
.ledger-actions{display:flex;gap:6px;flex-wrap:wrap}
/* #4771: a bill with no action shows no empty row (was a stray —). */
.ledger-cell-actions:has(.ledger-actions:empty){display:none}
.ledger-action{border:1px solid var(--ds-border);background:var(--ds-canvas);border-radius:8px;padding:4px 10px;cursor:pointer;font-size:12px}
.ledger-action--danger{border-color:var(--ds-danger-wash);color:var(--ds-danger)}
.ledger-action--warning{border-color:var(--ds-warning-wash);color:var(--ds-danger);background:var(--ds-warning-wash)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ledger-fade-enter-active,.ledger-fade-leave-active{transition:opacity .16s ease}
.ledger-fade-enter-from,.ledger-fade-leave-to{opacity:0}
.ledger-sticky-pay{position:sticky;bottom:-32px;margin:16px -22px -32px;padding:10px 22px calc(10px + env(safe-area-inset-bottom,0px));background:var(--surface,var(--ds-canvas));border-top:1px solid var(--ds-border);z-index:2}
.ledger-sticky-pay__btn{width:100%;min-height:48px;border:0;border-radius:10px;background:var(--ds-cta);color:var(--ds-on-cta);font:inherit;font-size:16px;font-weight:700;cursor:pointer}
.ledger-sticky-pay__btn small{margin-left:8px;font-weight:400;opacity:.85}
.ledger-sticky-pay__scope{margin:0 0 6px}
.ledger-table-wrap{overflow-x:visible}
.ledger-table,.ledger-table tbody,.ledger-table tr,.ledger-table td{display:block;width:100%;box-sizing:border-box}
.ledger-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.ledger-table tr{position:relative;margin-bottom:10px;border:1px solid var(--ds-border);border-radius:10px;padding:8px 10px}
.ledger-table td{border-bottom:0;padding:3px 0}
.ledger-table td[data-label]{display:flex;justify-content:space-between;gap:12px;text-align:right}
.ledger-table td[data-label]::before{content:attr(data-label);color:var(--ds-ink-mute);text-align:left}
.ledger-table .ledger-col-expand{width:auto;position:absolute;top:2px;right:2px;padding:0}
.ledger-expand{min-width:44px;min-height:44px}
.ledger-cell-title{padding-right:48px !important;overflow-wrap:anywhere}
.ledger-cell-actions{padding-top:6px !important}
.ledger-action{min-height:44px;padding:6px 14px}
.ledger-table tr.ledger-detail-row{border:0;padding:0;margin:-6px 0 10px}
.ledger-detail-row td{padding:0}
@media (max-width:768px){
  .ledger-modal{width:100vw;padding:16px}
  .ledger-timeline__item{grid-template-columns:1fr}
  .ledger-sticky-pay{bottom:-16px;margin:16px -16px -16px;padding:10px 16px calc(10px + env(safe-area-inset-bottom,0px))}
}
</style>

