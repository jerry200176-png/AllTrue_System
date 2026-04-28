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

          <section class="ledger-section">
            <h4>帳單與付款套用</h4>
            <div v-if="!payload.invoices?.length" class="ledger-empty">此學生尚無 Invoice 帳單。</div>
            <div v-else class="ledger-table-wrap">
              <table class="ledger-table">
                <thead>
                  <tr>
                    <th>課程</th>
                    <th>期別</th>
                    <th>應繳日</th>
                    <th>應收</th>
                    <th>套用</th>
                    <th>未結清</th>
                    <th>狀態</th>
                    <th>付款/收據</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inv in payload.invoices" :key="inv.id">
                    <td>#{{ inv.student_class_id }}</td>
                    <td>{{ formatPeriod(inv.billing_period) }}</td>
                    <td>{{ inv.due_date || '—' }}</td>
                    <td>{{ formatCurrency(inv.total_amount) }}</td>
                    <td>{{ formatCurrency(inv.calculated_applied_amount) }}</td>
                    <td :class="{ due: (inv.outstanding_amount || 0) > 0 }">{{ formatCurrency(inv.outstanding_amount) }}</td>
                    <td><span class="ledger-chip">{{ invoiceStatusLabel(inv.status) }}</span></td>
                    <td>
                      <div v-if="inv.payments?.length" class="ledger-lines">
                        <span v-for="p in inv.payments" :key="p.id" :class="{ void: p.is_void }">
                          {{ p.paid_at || '未記錄' }} · {{ p.is_void ? '沖銷' : paymentMethodLabel(p.method) }}
                          {{ signedCurrency(p.amount) }}
                          <em v-if="p.receipt_no">{{ p.receipt_no }}</em>
                        </span>
                      </div>
                      <span v-else class="ledger-muted">—</span>
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
                <small>Invoice #{{ r.invoice_id || '未套用' }} · 課程 #{{ r.student_class_id || '—' }}</small>
              </div>
            </div>
          </section>
        </template>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  studentClassId: { type: [Number, String], default: null },
  reportId: { type: [Number, String], default: null },
  branchId: { type: [Number, String], default: null },
});

defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const payload = ref(null);

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

function formatCurrency(value) {
  const n = Number(value || 0);
  return 'NT$ ' + n.toLocaleString('zh-TW');
}

function signedCurrency(value) {
  const n = Number(value || 0);
  const sign = n > 0 ? '+' : n < 0 ? '-' : '';
  return `${sign}${formatCurrency(Math.abs(n))}`;
}

function formatPeriod(period) {
  if (!period) return '—';
  const [y, m] = String(period).split('-');
  return y && m ? `${y}/${m}` : period;
}

function paymentMethodLabel(method) {
  return ({ cash: '現金', transfer: '匯款', void: '沖銷' }[method] || method || '—');
}

function invoiceStatusLabel(status) {
  return ({ paid: '已繳', unpaid: '未繳', partial: '部分付款' }[status] || status || '—');
}

function reportStatusLabel(status) {
  return ({ confirmed: '已核帳', pending: '待核帳', voided: '已撤銷' }[status] || status || '—');
}

function anomalyLabel(code) {
  return ({
    duplicate_effective_payments: '同帳單多筆收款',
    paid_amount_mismatch: '帳單金額不一致',
    paid_status_with_balance: '已繳狀態仍有餘額',
    open_status_without_balance: '未繳狀態但已足額',
    payment_without_receipt: '付款缺收據',
    receipt_without_payment: '收據缺付款',
    receipt_without_invoice: '收據未套帳單',
    confirmed_receipt_without_payment: '核帳缺付款',
    receipt_payment_outside_ledger: '收據付款不在本帳本',
  }[code] || code || '異常');
}
</script>

<style scoped>
.ledger-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  justify-content: flex-end;
}
.ledger-modal {
  width: min(1040px, 96vw);
  height: 100vh;
  overflow: auto;
  background: var(--surface, #fff);
  color: var(--text, #111827);
  box-shadow: -16px 0 44px rgba(15, 23, 42, 0.22);
  padding: 24px;
}
.ledger-header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 18px;
}
.ledger-eyebrow {
  margin: 0 0 4px;
  color: var(--primary, #2563eb);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
}
.ledger-header h3 {
  margin: 0;
  font-size: 24px;
}
.ledger-subtitle {
  margin: 6px 0 0;
  color: var(--text-light, #64748b);
}
.ledger-close {
  border: 0;
  background: transparent;
  font-size: 28px;
  cursor: pointer;
  color: var(--text-light, #64748b);
}
.ledger-state,
.ledger-empty {
  padding: 28px;
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  color: var(--text-light, #64748b);
  text-align: center;
}
.ledger-error {
  color: #b91c1c;
  background: #fef2f2;
  border-color: #fecaca;
}
.ledger-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 14px;
}
.ledger-summary > div {
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
  background: #f8fafc;
}
.ledger-summary strong {
  display: block;
  font-size: 20px;
}
.ledger-summary span {
  color: var(--text-light, #64748b);
  font-size: 12px;
}
.ledger-summary .warn {
  background: #fffbeb;
  border-color: #fde68a;
}
.ledger-alerts {
  display: grid;
  gap: 8px;
  margin-bottom: 16px;
}
.ledger-alert {
  display: flex;
  gap: 8px;
  border-radius: 12px;
  padding: 10px 12px;
  background: #fffbeb;
  color: #92400e;
}
.ledger-alert--critical {
  background: #fef2f2;
  color: #991b1b;
}
.ledger-section {
  margin-top: 20px;
}
.ledger-section h4 {
  margin: 0 0 10px;
}
.ledger-table-wrap {
  overflow-x: auto;
}
.ledger-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ledger-table th,
.ledger-table td {
  border-bottom: 1px solid #e2e8f0;
  padding: 10px;
  text-align: left;
  vertical-align: top;
}
.ledger-table th {
  color: var(--text-light, #64748b);
  background: #f8fafc;
}
.due {
  color: #b91c1c;
  font-weight: 800;
}
.ledger-chip {
  display: inline-flex;
  border-radius: 999px;
  padding: 2px 8px;
  background: #eef2ff;
  color: #3730a3;
  font-size: 12px;
  font-weight: 700;
}
.ledger-lines {
  display: grid;
  gap: 4px;
}
.ledger-lines span,
.ledger-receipt {
  color: var(--text, #111827);
}
.ledger-lines .void {
  color: var(--text-light, #64748b);
  text-decoration: line-through;
}
.ledger-lines em {
  margin-left: 4px;
  color: var(--text-light, #64748b);
  font-style: normal;
}
.ledger-muted {
  color: var(--text-light, #64748b);
}
.ledger-receipts {
  display: grid;
  gap: 8px;
}
.ledger-receipt {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
}
.ledger-receipt small {
  color: var(--text-light, #64748b);
}
.status-voided {
  background: #fef3c7;
  color: #92400e;
}
.status-pending {
  background: #f1f5f9;
  color: #475569;
}
.ledger-fade-enter-active,
.ledger-fade-leave-active {
  transition: opacity 0.16s ease;
}
.ledger-fade-enter-from,
.ledger-fade-leave-to {
  opacity: 0;
}
@media (max-width: 760px) {
  .ledger-modal {
    width: 100vw;
    padding: 18px;
  }
  .ledger-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
