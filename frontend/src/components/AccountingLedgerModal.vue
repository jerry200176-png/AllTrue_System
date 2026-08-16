<template>
  <Transition name="ledger-fade">
    <div v-if="show" class="ledger-overlay" @click.self="$emit('close')">
      <div class="ledger-modal" role="dialog" aria-modal="true" aria-label="學生帳務對帳">
        <div class="ledger-header">
          <div>
            <p class="ledger-eyebrow">學生帳務</p>
            <h3>學生帳務對帳</h3>
            <p class="ledger-subtitle">
              {{ payload?.student?.name || '載入中' }} · 對齊帳單、收款與收據
            </p>
          </div>
          <button class="ledger-close" type="button" @click="$emit('close')" aria-label="關閉">×</button>
        </div>

        <div v-if="loading" class="ledger-state">載入對帳資料中…</div>
        <div v-else-if="error" class="ledger-state ledger-error">{{ error }}</div>
        <template v-else-if="payload">
          <!-- Carbon-style compact metric strip (ops density) -->
          <div class="ledger-strip" aria-label="對帳摘要">
            <div class="ledger-strip__item">
              <span class="ledger-strip__label">應收</span>
              <strong class="ledger-strip__value">{{ formatCurrency(payload.summary?.invoice_total) }}</strong>
            </div>
            <div class="ledger-strip__item">
              <span class="ledger-strip__label">已記入</span>
              <strong class="ledger-strip__value">{{ formatCurrency(payload.summary?.applied_total) }}</strong>
            </div>
            <div class="ledger-strip__item" :class="{ 'is-warn': (payload.summary?.outstanding_total || 0) > 0 }">
              <span class="ledger-strip__label">未結清</span>
              <strong class="ledger-strip__value">{{ formatCurrency(payload.summary?.outstanding_total) }}</strong>
            </div>
            <div class="ledger-strip__item" :class="{ 'is-warn': (payload.summary?.overpaid_total || 0) > 0 }">
              <span class="ledger-strip__label">多收</span>
              <strong class="ledger-strip__value">{{ formatCurrency(payload.summary?.overpaid_total) }}</strong>
            </div>
            <div class="ledger-strip__item" :class="{ 'is-danger': (payload.summary?.anomaly_count || 0) > 0 }">
              <span class="ledger-strip__label">需注意</span>
              <strong class="ledger-strip__value">{{ payload.summary?.anomaly_count || 0 }}</strong>
            </div>
          </div>

          <section v-if="ledgerExceptions.length" class="ledger-section">
            <h4>需先處理</h4>
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
                >撤銷收款</button>
                <span v-if="!x.can_void && !x.report_id" class="ledger-muted">請聯絡總部協助</span>
              </div>
            </div>
            <button
              v-if="ledgerExceptions.length > EXCEPTION_PREVIEW"
              class="ledger-more"
              type="button"
              @click="showAllExceptions = !showAllExceptions"
            >
              {{ showAllExceptions ? '收合異常' : `還有 ${ledgerExceptions.length - EXCEPTION_PREVIEW} 則` }}
            </button>
          </section>

          <section class="ledger-section">
            <h4>帳單</h4>
            <div v-if="!payload.invoices?.length" class="ledger-empty">此學生尚無帳單。</div>
            <div v-else class="ledger-table-wrap">
              <table class="ledger-table">
                <thead>
                  <tr>
                    <th class="ledger-col-expand" scope="col"><span class="sr-only">展開收款</span></th>
                    <th>帳單（科目）</th>
                    <th>應繳日</th>
                    <th class="num">應收</th>
                    <th class="num">已記入</th>
                    <th class="num">未結清</th>
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
                          :aria-label="isExpanded(inv.id) ? '收合收款紀錄' : '展開收款紀錄'"
                          :disabled="!(inv.payments?.length)"
                          @click="toggleExpand(inv.id)"
                        >
                          <span aria-hidden="true">{{ isExpanded(inv.id) ? '▾' : '▸' }}</span>
                          <em v-if="inv.payments?.length" class="ledger-pay-count">{{ inv.payments.length }}</em>
                        </button>
                      </td>
                      <td>
                        <strong>{{ formatLedgerInvoiceLabel(inv) }}</strong>
                        <small>{{ formatLedgerCourseLabel(inv) }} · {{ formatPeriod(inv.billing_period) }}</small>
                        <small v-if="(inv.overpaid_amount || 0) > 0" class="ledger-overpay-hint">多收 {{ formatCurrency(inv.overpaid_amount) }}</small>
                      </td>
                      <td>{{ inv.due_date || '—' }}</td>
                      <td class="num">{{ formatCurrency(inv.total_amount) }}</td>
                      <td class="num">{{ formatCurrency(inv.calculated_applied_amount) }}</td>
                      <td class="num" :class="{ due: (inv.outstanding_amount || 0) > 0 }">{{ formatCurrency(inv.outstanding_amount) }}</td>
                      <td>
                        <span :class="['ledger-chip', invoiceStatusClass(inv.status)]">{{ invoiceStatusLabel(inv.status) }}</span>
                      </td>
                      <td>
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
                          <span v-else class="ledger-muted">—</span>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="isExpanded(inv.id)" class="ledger-detail-row">
                      <td colspan="8">
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
                              <div class="ledger-timeline__when">{{ p.paid_at || '未記錄日期' }}</div>
                              <div class="ledger-timeline__body">
                                <strong>{{ p.is_void ? '更正收款' : paymentMethodLabel(p.method) }} {{ signedCurrency(p.amount) }}</strong>
                                <span :class="['ledger-chip', applicationStatusClass(p.application_status)]">
                                  {{ applicationStatusLabel(p.application_status) }}
                                </span>
                                <div class="ledger-timeline__meta">
                                  <span v-if="p.applied_amount">記入 {{ formatCurrency(p.applied_amount) }}</span>
                                  <span v-if="p.unapplied_amount">多收 {{ formatCurrency(p.unapplied_amount) }}</span>
                                  <span v-if="p.receipt_no" class="ledger-ref">{{ humanizeDocumentRef(p.receipt_no) }}</span>
                                </div>
                              </div>
                            </li>
                          </ol>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </section>

          <section class="ledger-section">
            <h4>收據紀錄</h4>
            <div v-if="!payload.receipts?.length" class="ledger-empty">此學生尚無收據紀錄。</div>
            <div v-else class="ledger-receipts ledger-receipts--compact">
              <div v-for="r in payload.receipts" :key="r.report_id" class="ledger-receipt">
                <strong>{{ formatCurrency(r.amount) }}</strong>
                <span>{{ r.payment_date || '未記錄日期' }}</span>
                <span>{{ paymentMethodLabel(r.payment_method) }}</span>
                <span :class="['ledger-chip', reportStatusClass(r.status)]">{{ reportStatusLabel(r.status) }}</span>
                <small class="ledger-ref">{{ humanizeDocumentRef(r.receipt_no) }}</small>
                <small>{{ formatLedgerReceiptBillLine(r) }}</small>
              </div>
            </div>
          </section>
        </template>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import {
  formatLedgerCourseLabel,
  formatLedgerInvoiceLabel,
  formatLedgerReceiptBillLine,
  formatLedgerAnomalyDetail,
  humanizeDocumentRef,
} from '../lib/studentClassDisplay.js';
import { humanizeApiErrorMessage } from '../lib/humanizeApiErrorMessage.js';

const EXCEPTION_PREVIEW = 2;

const props = defineProps({ show: Boolean, studentClassId: [Number, String], reportId: [Number, String], branchId: [Number, String] });

const emit = defineEmits(['close', 'changed']);

const loading = ref(false);
const error = ref('');
const payload = ref(null);
const busyReportId = ref(null);
const busyInvoiceId = ref(null);
const expandedIds = ref(new Set());
const showAllExceptions = ref(false);

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
}

function autoExpandAttention() {
  const next = new Set();
  for (const inv of payload.value?.invoices || []) {
    if (needsAttention(inv) && inv.payments?.length) next.add(Number(inv.id));
  }
  expandedIds.value = next;
}

async function loadLedger() {
  if (!props.show) return;
  const studentClassId = Number(props.studentClassId || 0);
  const reportId = Number(props.reportId || 0);
  if (!studentClassId && !reportId) {
    error.value = '缺少課程或收據資訊，無法開啟對帳。';
    return;
  }

  loading.value = true;
  error.value = '';
  payload.value = null;
  showAllExceptions.value = false;
  try {
    const token = getToken();
    if (!token) throw new Error('請先登入');
    const params = new URLSearchParams();
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (reportId) params.set('report_id', String(reportId));
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));

    const resp = await fetch(`/api/v1/accounting/ledger?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(humanizeApiErrorMessage(json.message || `載入失敗（${resp.status}）`));
    payload.value = json;
    autoExpandAttention();
  } catch (e) {
    error.value = humanizeApiErrorMessage(e.message || '載入對帳資料失敗');
  } finally {
    loading.value = false;
  }
}

watch(() => [props.show, props.studentClassId, props.reportId, props.branchId], loadLedger, { immediate: true });

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
      severity: a.severity === 'critical' ? 0 : 1,
    });
  });
  (payload.value?.invoices || []).forEach((inv) => {
    (inv.payments || []).forEach((p) => {
      if (p.application_status !== 'overpayment_pending_review') return;
      rows.push({
        key: `p-${p.id}`,
        title: '多收，疑似重複收款',
        message: `${humanizeDocumentRef(p.receipt_no || p.payment_no) || '未編號收款'} 有 ${formatCurrency(p.unapplied_amount)} 尚未記入帳單`,
        detail: `${formatLedgerInvoiceLabel(inv)} · ${p.paid_at || '未記錄日期'}`,
        report_id: p.report_id || null,
        can_void: !!p.report_id && !p.is_void,
        severity: 1,
      });
    });
  });
  return rows.sort((a, b) => a.severity - b.severity);
});

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
  if (!reportId || !['director', 'admin', 'super_admin'].includes(getAuthRole())) return;
  const reason = window.prompt('請輸入撤銷原因（會保留稽核紀錄）');
  if (!reason || !reason.trim()) return;
  busyReportId.value = reportId;
  try {
    const token = getToken();
    if (!token) throw new Error('請先登入');
    const resp = await fetch(`/api/v1/payment-reports/${reportId}/void`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ void_reason: reason.trim() }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(humanizeApiErrorMessage(json.message || `撤銷失敗（${resp.status}）`));
    await loadLedger();
    emit('changed');
  } catch (e) {
    error.value = humanizeApiErrorMessage(e.message || '撤銷失敗');
  } finally {
    busyReportId.value = null;
  }
}

const canManageInvoices = () => ['director', 'admin', 'super_admin'].includes(getAuthRole());
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
    const token = getToken();
    if (!token) throw new Error('請先登入');
    const path = isException ? 'exception-void' : 'void';
    const resp = await fetch(`/api/v1/invoices/${invoice.id}/${path}`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ reason: reason.trim() }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(humanizeApiErrorMessage(json.message || `${actionLabel}失敗（${resp.status}）`));
    await loadLedger();
    emit('changed');
  } catch (e) {
    error.value = humanizeApiErrorMessage(e.message || `${actionLabel}失敗`);
  } finally {
    busyInvoiceId.value = null;
  }
}

const labelMap = (map, key) => map[key] || key || '—';
const formatCurrency = (value) => 'NT$ ' + Number(value || 0).toLocaleString('zh-TW');
const signedCurrency = (value) => `${Number(value || 0) > 0 ? '+' : Number(value || 0) < 0 ? '-' : ''}${formatCurrency(Math.abs(Number(value || 0)))}`;
const formatPeriod = (period) => !period ? '—' : (String(period).split('-').length === 2 ? String(period).replace('-', '/') : period);
const paymentMethodLabel = (method) => labelMap({ cash: '現金', transfer: '匯款', void: '更正收款' }, method);
const invoiceStatusLabel = (status) => labelMap({ paid: '已繳', unpaid: '未繳', partial: '部分付款', void: '已作廢' }, status);
const reportStatusLabel = (status) => labelMap({ confirmed: '已核帳', pending: '待對帳', voided: '已撤銷' }, status);
const applicationStatusLabel = (status) => labelMap({ applied: '已記入', partially_applied: '部分記入', overpayment_pending_review: '多收待處理', voided: '已更正' }, status);
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
}, status);
const applicationStatusClass = (status) => labelMap({
  applied: 'chip--success',
  partially_applied: 'chip--warning',
  overpayment_pending_review: 'chip--danger',
  voided: 'chip--muted',
}, status);
const anomalyLabel = (code) => labelMap({
  overpayment_pending_review: '多收，疑似重複收款',
  duplicate_effective_payments: '同一帳單有多筆收款',
  paid_amount_mismatch: '帳單金額不一致',
  paid_status_with_balance: '顯示已繳但仍有餘額',
  open_status_without_balance: '顯示未繳但已繳足',
  payment_without_receipt: '收款缺少收據',
  receipt_without_payment: '收據缺少收款',
  receipt_without_invoice: '收據尚未對到帳單',
  confirmed_receipt_without_payment: '已確認收據缺少收款',
  receipt_payment_outside_ledger: '收據收款不在本學生帳本',
}, code) || '需注意';
</script>

<style scoped>
.ledger-overlay{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.45);display:flex;justify-content:flex-end}
.ledger-modal{width:min(920px,96vw);height:100vh;overflow:auto;background:var(--surface,var(--ds-canvas));color:var(--text,var(--ds-ink));box-shadow:-16px 0 44px rgba(15,23,42,.22);padding:20px 22px 32px}
.ledger-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}
.ledger-eyebrow{margin:0 0 4px;color:var(--primary,var(--ds-ink-mute));font-size:12px;font-weight:800;letter-spacing:.08em}
.ledger-header h3{margin:0;font-size:22px}
.ledger-subtitle{margin:6px 0 0;color:var(--text-light,var(--ds-ink-mute));font-size:13px}
.ledger-close{border:0;background:transparent;font-size:28px;cursor:pointer;color:var(--text-light,var(--ds-ink-mute))}
.ledger-state,.ledger-empty{padding:24px;border:1px dashed var(--ds-canvas-soft);border-radius:12px;color:var(--text-light,var(--ds-ink-mute));text-align:center}
.ledger-error{color:var(--ds-danger);background:var(--ds-danger-wash);border-color:var(--ds-danger-wash)}

.ledger-strip{display:flex;flex-wrap:wrap;gap:0;margin-bottom:14px;border:1px solid var(--ds-canvas-soft);border-radius:10px;overflow:hidden;background:var(--ds-canvas)}
.ledger-strip__item{flex:1 1 110px;display:flex;flex-direction:column;gap:2px;padding:10px 12px;border-right:1px solid var(--ds-canvas-soft);min-width:0}
.ledger-strip__item:last-child{border-right:0}
.ledger-strip__label{font-size:11px;font-weight:600;color:var(--text-light,var(--ds-ink-mute));letter-spacing:.02em}
.ledger-strip__value{font-size:16px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.2}
.ledger-strip__item.is-warn .ledger-strip__value{color:var(--ds-warning)}
.ledger-strip__item.is-danger .ledger-strip__value{color:var(--ds-danger)}

.ledger-section{margin-top:18px}
.ledger-section h4{margin:0 0 8px;font-size:14px}
.ledger-more{margin-top:8px;border:0;background:transparent;color:var(--primary,var(--ds-info));font-size:13px;font-weight:600;cursor:pointer;padding:0}
.ledger-table-wrap{overflow-x:auto}
.ledger-table{width:100%;border-collapse:collapse;font-size:13px}
.ledger-table th,.ledger-table td{border-bottom:1px solid var(--ds-canvas-soft);padding:8px 10px;text-align:left;vertical-align:top}
.ledger-table th{color:var(--text-light,var(--ds-ink-mute));background:var(--ds-canvas-soft);font-weight:600;font-size:12px}
.ledger-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ledger-col-expand{width:44px;padding-left:6px;padding-right:4px}
.ledger-expand{display:inline-flex;align-items:center;gap:4px;border:0;background:transparent;cursor:pointer;color:var(--ds-ink-mute);padding:2px 4px;border-radius:6px}
.ledger-expand:disabled{opacity:.35;cursor:default}
.ledger-expand:not(:disabled):hover{background:var(--ds-canvas-soft);color:var(--ds-ink)}
.ledger-pay-count{font-style:normal;font-size:11px;font-weight:700;color:var(--ds-ink-mute)}
.ledger-row--open td{background:var(--ds-canvas-soft)}
.ledger-row--attention td:nth-child(2) strong{color:var(--ds-ink)}
.ledger-overpay-hint{display:block;color:var(--ds-danger);font-weight:600}
.due{color:var(--ds-danger);font-weight:700}

.ledger-chip{display:inline-flex;border-radius:6px;padding:2px 7px;background:var(--ds-canvas-soft);color:var(--ds-ink-mute);font-size:11px;font-weight:700}
.ledger-chip.chip--success{background:var(--ds-success-wash);color:var(--ds-success)}
.ledger-chip.chip--warning{background:var(--ds-warning-wash);color:var(--ds-warning)}
.ledger-chip.chip--danger{background:var(--ds-danger-wash);color:var(--ds-danger)}
.ledger-chip.chip--muted{background:var(--ds-canvas-soft);color:var(--ds-ink-mute)}

.ledger-detail-row td{background:var(--ds-canvas);padding:0 10px 12px 44px;border-bottom:1px solid var(--ds-canvas-soft)}
.ledger-timeline__list{list-style:none;margin:0;padding:8px 0 0;display:grid;gap:8px}
.ledger-timeline__item{display:grid;grid-template-columns:96px 1fr;gap:10px;padding:8px 10px;border:1px solid var(--ds-canvas-soft);border-radius:10px;background:var(--surface,var(--ds-canvas))}
.ledger-timeline__item.is-void{opacity:.65}
.ledger-timeline__item.is-void strong{text-decoration:line-through}
.ledger-timeline__item.is-overpay{border-color:var(--ds-warning);background:var(--ds-warning-wash)}
.ledger-timeline__when{font-size:12px;color:var(--text-light,var(--ds-ink-mute));font-variant-numeric:tabular-nums}
.ledger-timeline__body{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}
.ledger-timeline__meta{width:100%;display:flex;flex-wrap:wrap;gap:8px;font-size:12px;color:var(--text-light,var(--ds-ink-mute))}
.ledger-ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:var(--ds-ink-mute)}

.ledger-receipts{display:grid;gap:8px}
.ledger-receipt{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--ds-canvas-soft);border-radius:10px}
.ledger-receipts--compact .ledger-receipt{padding:8px 10px}
.ledger-muted,.ledger-receipt small,.ledger-table small{color:var(--text-light,var(--ds-ink-mute))}
.ledger-table small{display:block;margin-top:2px}
.ledger-actions{display:flex;gap:6px;flex-wrap:wrap}
.ledger-action{border:1px solid var(--ds-canvas-soft);background:var(--ds-canvas);border-radius:8px;padding:4px 10px;cursor:pointer;font-size:12px}
.ledger-action--danger{border-color:var(--ds-danger-wash);color:var(--ds-danger)}
.ledger-action--warning{border-color:var(--ds-warning-wash);color:var(--ds-danger);background:var(--ds-warning-wash)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ledger-fade-enter-active,.ledger-fade-leave-active{transition:opacity .16s ease}
.ledger-fade-enter-from,.ledger-fade-leave-to{opacity:0}
@media (max-width:760px){
  .ledger-modal{width:100vw;padding:16px}
  .ledger-timeline__item{grid-template-columns:1fr}
  .ledger-detail-row td{padding-left:10px}
}
</style>
