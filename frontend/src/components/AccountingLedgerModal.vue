<template>
  <Transition name="ledger-fade">
    <div v-if="show" class="ledger-overlay" @click.self="$emit('close')">
      <div class="ledger-modal" role="dialog" aria-modal="true" aria-label="學生帳務對帳">
        <div class="ledger-header">
          <div>
            <p class="ledger-eyebrow">AR Ledger</p>
            <h3>學生帳務對帳</h3>
            <p class="ledger-subtitle">
              {{ payload?.student?.name || '載入中' }} · Invoice / Receipt / Payment 同源查核
            </p>
          </div>
          <button class="ledger-close" type="button" @click="$emit('close')" aria-label="關閉">×</button>
        </div>

        <div v-if="loading" class="ledger-state">載入對帳資料中...</div>
        <div v-else-if="error" class="ledger-state ledger-error">{{ error }}</div>
        <template v-else-if="payload">
          <div class="ledger-summary">
            <div>
              <strong>{{ formatCurrency(payload.summary?.invoice_total) }}</strong>
              <span>應收合計</span>
            </div>
            <div>
              <strong>{{ formatCurrency(payload.summary?.applied_total) }}</strong>
              <span>已套用收款</span>
            </div>
            <div>
              <strong>{{ formatCurrency(payload.summary?.outstanding_total) }}</strong>
              <span>未結清</span>
            </div>
            <div :class="{ warn: (payload.summary?.overpaid_total || 0) > 0 }">
              <strong>{{ formatCurrency(payload.summary?.overpaid_total) }}</strong>
              <span>溢收/待沖銷</span>
            </div>
            <div :class="{ warn: (payload.summary?.anomaly_count || 0) > 0 }">
              <strong>{{ payload.summary?.anomaly_count || 0 }}</strong>
              <span>異常標籤</span>
            </div>
          </div>

          <div v-if="payload.anomalies?.length" class="ledger-alerts">
            <div
              v-for="a in payload.anomalies"
              :key="`${a.code}-${a.invoice_id || 'na'}-${a.report_id || 'na'}-${a.payment_id || 'na'}`"
              :class="['ledger-alert', `ledger-alert--${a.severity || 'warning'}`]"
            >
              <strong>{{ anomalyLabel(a.code) }}</strong>
              <span>{{ a.message }}</span>
            </div>
          </div>

          <section v-if="ledgerExceptions.length" class="ledger-section">
            <h4>例外處理區</h4>
            <div class="ledger-receipts">
              <div v-for="x in ledgerExceptions" :key="x.key" class="ledger-receipt">
                <strong>{{ x.title }}</strong>
                <span>{{ x.message }}</span>
                <small>{{ x.detail }}</small>
                <button v-if="x.can_void" class="ledger-action ledger-action--danger" type="button" :disabled="busyReportId === x.report_id" @click="voidReport(x.report_id)">撤銷/沖銷</button>
                <span v-if="!x.can_void && !x.report_id" class="ledger-muted">需人工資料修復</span>
              </div>
            </div>
          </section>

          <section class="ledger-section">
            <h4>帳單與付款套用</h4>
            <div v-if="!payload.invoices?.length" class="ledger-empty">此學生尚無 Invoice 帳單。</div>
            <div v-else class="ledger-table-wrap">
              <table class="ledger-table">
                <thead>
                  <tr>
                    <th>帳單 / 課程</th>
                    <th>應繳日</th>
                    <th>應收</th>
                    <th>套用</th>
                    <th>溢收</th>
                    <th>未結清</th>
                    <th>狀態</th>
                    <th>已收款紀錄</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inv in payload.invoices" :key="inv.id">
                    <td>
                      <strong>{{ inv.invoice_no || `INV-${inv.id}` }}</strong>
                      <small>{{ inv.course_ref || `COURSE-${inv.student_class_id}` }} · {{ formatPeriod(inv.billing_period) }}</small>
                    </td>
                    <td>{{ inv.due_date || '—' }}</td>
                    <td>{{ formatCurrency(inv.total_amount) }}</td>
                    <td>{{ formatCurrency(inv.calculated_applied_amount) }}</td>
                    <td :class="{ due: (inv.overpaid_amount || 0) > 0 }">{{ formatCurrency(inv.overpaid_amount) }}</td>
                    <td :class="{ due: (inv.outstanding_amount || 0) > 0 }">{{ formatCurrency(inv.outstanding_amount) }}</td>
                    <td><span class="ledger-chip">{{ invoiceStatusLabel(inv.status) }}</span></td>
                    <td>
                      <div v-if="inv.payments?.length" class="ledger-lines">
                        <span v-for="p in inv.payments" :key="p.id" :class="{ void: p.is_void, due: p.application_status === 'overpayment_pending_review' }">
                          {{ p.paid_at || '未記錄' }} · {{ p.is_void ? '沖銷' : paymentMethodLabel(p.method) }}
                          {{ signedCurrency(p.amount) }} · {{ applicationStatusLabel(p.application_status) }}
                          <em v-if="p.applied_amount">套用 {{ formatCurrency(p.applied_amount) }}</em>
                          <em v-if="p.unapplied_amount">溢收 {{ formatCurrency(p.unapplied_amount) }}</em>
                          <em v-if="p.receipt_no">{{ p.receipt_no }}</em>
                        </span>
                      </div>
                      <span v-else class="ledger-muted">—</span>
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
                        >沖銷作廢</button>
                        <span v-else class="ledger-muted">—</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="ledger-section">
            <h4>收據流水</h4>
            <div v-if="!payload.receipts?.length" class="ledger-empty">此學生尚無收據/核帳紀錄。</div>
            <div v-else class="ledger-receipts">
              <div v-for="r in payload.receipts" :key="r.report_id" class="ledger-receipt">
                <strong>{{ r.receipt_no }}</strong>
                <span>{{ r.payment_date || '未記錄日期' }}</span>
                <span>{{ paymentMethodLabel(r.payment_method) }}</span>
                <span>{{ formatCurrency(r.amount) }}</span>
                <span :class="['ledger-chip', `status-${r.status}`]">{{ reportStatusLabel(r.status) }}</span>
                <small>帳單 #{{ r.invoice_id || '未套用' }} · {{ r.course_ref || `COURSE-${String(r.student_class_id || '—').padStart(6, '0')}` }}</small>
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

const props = defineProps({ show: Boolean, studentClassId: [Number, String], reportId: [Number, String], branchId: [Number, String] });

const emit = defineEmits(['close', 'changed']);

const loading = ref(false);
const error = ref('');
const payload = ref(null);
const busyReportId = ref(null);
const busyInvoiceId = ref(null);

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
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
    if (!resp.ok) throw new Error(json.message || `載入失敗（${resp.status}）`);
    payload.value = json;
  } catch (e) {
    error.value = e.message || '載入對帳資料失敗';
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
      detail: [a.invoice_id ? `帳單 #${a.invoice_id}` : '', a.report_id ? `收據 #${a.report_id}` : '', a.payment_id ? `Payment #${a.payment_id}` : ''].filter(Boolean).join(' · '),
      report_id: a.report_id || null,
      can_void: a.action?.type === 'void_report',
    });
  });
  (payload.value?.invoices || []).forEach((inv) => {
    (inv.payments || []).forEach((p) => {
      if (p.application_status !== 'overpayment_pending_review') return;
      rows.push({
        key: `p-${p.id}`,
        title: '溢收/疑似重複收款',
        message: `${p.receipt_no || p.payment_no || '未編號收款'} 有 ${formatCurrency(p.unapplied_amount)} 未套用到帳單`,
        detail: `${inv.invoice_no || `Invoice #${inv.id}`} · ${p.paid_at || '未記錄日期'}`,
        report_id: p.report_id || null,
        can_void: !!p.report_id && !p.is_void,
      });
    });
  });
  return rows;
});

function getAuthRole() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.user?.role || session?.role || '';
}

async function voidReport(reportId) {
  if (!reportId || !['director', 'admin', 'super_admin'].includes(getAuthRole())) return;
  const reason = window.prompt('請輸入撤銷/沖銷原因（會保留稽核紀錄）');
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
    if (!resp.ok) throw new Error(json.message || `撤銷失敗（${resp.status}）`);
    await loadLedger();
    emit('changed');
  } catch (e) {
    error.value = e.message || '撤銷失敗';
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
  const actionLabel = isException ? '沖銷作廢' : '作廢';
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
    if (!resp.ok) throw new Error(json.message || `${actionLabel}失敗（${resp.status}）`);
    await loadLedger();
    emit('changed');
  } catch (e) {
    error.value = e.message || `${actionLabel}失敗`;
  } finally {
    busyInvoiceId.value = null;
  }
}

const labelMap = (map, key) => map[key] || key || '—';
const formatCurrency = (value) => 'NT$ ' + Number(value || 0).toLocaleString('zh-TW');
const signedCurrency = (value) => `${Number(value || 0) > 0 ? '+' : Number(value || 0) < 0 ? '-' : ''}${formatCurrency(Math.abs(Number(value || 0)))}`;
const formatPeriod = (period) => !period ? '—' : (String(period).split('-').length === 2 ? String(period).replace('-', '/') : period);
const paymentMethodLabel = (method) => labelMap({ cash: '現金', transfer: '匯款', void: '沖銷' }, method);
const invoiceStatusLabel = (status) => labelMap({ paid: '已繳', unpaid: '未繳', partial: '部分付款', void: '已作廢' }, status);
const reportStatusLabel = (status) => labelMap({ confirmed: '已核帳', pending: '待核帳', voided: '已撤銷' }, status);
const applicationStatusLabel = (status) => labelMap({ applied: '已套用', partially_applied: '部分套用', overpayment_pending_review: '溢收/待沖銷', voided: '已沖銷' }, status);
const anomalyLabel = (code) => labelMap({ overpayment_pending_review: '溢收/疑似重複收款', duplicate_effective_payments: '同帳單多筆收款', paid_amount_mismatch: '帳單金額不一致', paid_status_with_balance: '已繳狀態仍有餘額', open_status_without_balance: '未繳狀態但已足額', payment_without_receipt: '付款缺收據', receipt_without_payment: '收據缺付款', receipt_without_invoice: '收據未套帳單', confirmed_receipt_without_payment: '核帳缺付款', receipt_payment_outside_ledger: '收據付款不在本帳本' }, code) || '異常';
</script>

<style scoped>
.ledger-overlay{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.45);display:flex;justify-content:flex-end}.ledger-modal{width:min(1040px,96vw);height:100vh;overflow:auto;background:var(--surface,#fff);color:var(--text,#111827);box-shadow:-16px 0 44px rgba(15,23,42,.22);padding:24px}.ledger-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.ledger-eyebrow{margin:0 0 4px;color:var(--primary,#2563eb);font-size:12px;font-weight:800;letter-spacing:.08em}.ledger-header h3{margin:0;font-size:24px}.ledger-subtitle{margin:6px 0 0;color:var(--text-light,#64748b)}.ledger-close{border:0;background:transparent;font-size:28px;cursor:pointer;color:var(--text-light,#64748b)}.ledger-state,.ledger-empty{padding:28px;border:1px dashed #cbd5e1;border-radius:14px;color:var(--text-light,#64748b);text-align:center}.ledger-error{color:#b91c1c;background:#fef2f2;border-color:#fecaca}.ledger-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.ledger-summary>div{border:1px solid #e2e8f0;border-radius:14px;padding:14px;background:#f8fafc}.ledger-summary strong{display:block;font-size:20px}.ledger-summary span{color:var(--text-light,#64748b);font-size:12px}.ledger-summary .warn{background:#fffbeb;border-color:#fde68a}.ledger-alerts,.ledger-lines,.ledger-receipts{display:grid;gap:8px}.ledger-alerts{margin-bottom:16px}.ledger-alert{display:flex;gap:8px;border-radius:12px;padding:10px 12px;background:#fffbeb;color:#92400e}.ledger-alert--critical{background:#fef2f2;color:#991b1b}.ledger-section{margin-top:20px}.ledger-section h4{margin:0 0 10px}.ledger-table-wrap{overflow-x:auto}.ledger-table{width:100%;border-collapse:collapse;font-size:13px}.ledger-table th,.ledger-table td{border-bottom:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:top}.ledger-table th{color:var(--text-light,#64748b);background:#f8fafc}.due{color:#b91c1c;font-weight:800}.ledger-chip{display:inline-flex;border-radius:999px;padding:2px 8px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700}.ledger-lines span,.ledger-receipt{color:var(--text,#111827)}.ledger-lines .void{color:var(--text-light,#64748b);text-decoration:line-through}.ledger-lines em{margin-left:4px;color:var(--text-light,#64748b);font-style:normal}.ledger-muted,.ledger-receipt small,.ledger-table small{color:var(--text-light,#64748b)}.ledger-receipt{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1px solid #e2e8f0;border-radius:12px}.ledger-actions{display:flex;gap:6px;flex-wrap:wrap}.ledger-action{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:4px 10px;cursor:pointer}.ledger-action--danger{border-color:#fecaca;color:#b91c1c}.ledger-action--warning{border-color:#fed7aa;color:#9a3412;background:#fff7ed}.status-voided{background:#fef3c7;color:#92400e}.status-pending{background:#f1f5f9;color:#475569}.ledger-fade-enter-active,.ledger-fade-leave-active{transition:opacity .16s ease}.ledger-fade-enter-from,.ledger-fade-leave-to{opacity:0}@media (max-width:760px){.ledger-modal{width:100vw;padding:18px}.ledger-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
