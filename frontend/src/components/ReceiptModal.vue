<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal receipt-modal">
      <div class="receipt-header">
        <h3>電子收據</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div v-if="loading" class="receipt-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="receipt-error">
        <p>{{ error }}</p>
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>

      <template v-else-if="receipt">
        <!-- Backfill notice legacy -->
        <div v-if="receipt.is_backfilled" class="receipt-backfill-notice">
          <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px">info</span>
          <span>此收據由系統依舊繳費記錄補建，原始付款方式與日期可能不精確。</span>
        </div>

        <!-- Voided banner -->
        <div v-if="receipt.status === 'voided'" class="receipt-voided-banner">
          <span class="material-symbols-outlined">cancel</span>
          <div>
            <strong>此收據已作廢</strong>
            <p v-if="receipt.void_reason">原因：{{ receipt.void_reason }}</p>
            <p class="voided-meta">作廢時間：{{ formatDateTime(receipt.voided_at) }}</p>
          </div>
        </div>

        <!-- Receipt document preview -->
        <div class="receipt-preview-wrap" :class="{ voided: receipt.status === 'voided' }">
          <div ref="receiptPrintRef" class="receipt-document">
            <div class="receipt-doc-header">
              <div class="receipt-doc-title">電子收據</div>
              <div class="receipt-doc-brand">{{ schoolName }}</div>
            </div>

            <div class="receipt-doc-body">
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">學生姓名</span>
                <span class="receipt-doc-value">{{ snapshot.student_name || '—' }}</span>
              </div>
              <div v-if="snapshot.campus_name" class="receipt-doc-row">
                <span class="receipt-doc-label">分校</span>
                <span class="receipt-doc-value">{{ snapshot.campus_name }}</span>
              </div>
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">修業期間</span>
                <span class="receipt-doc-value">
                  <template v-if="snapshot.study_period">
                    {{ snapshot.study_period.start }} ~ {{ snapshot.study_period.end }}
                  </template>
                  <template v-else>—</template>
                </span>
              </div>
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">收據號碼</span>
                <span class="receipt-doc-value receipt-doc-number">{{ receipt.receipt_number }}</span>
              </div>

              <!-- Items table -->
              <div class="receipt-doc-items">
                <div class="receipt-doc-items-header">
                  <span>收費項目</span>
                  <span class="receipt-doc-amount-col">金額</span>
                </div>
                <div
                  v-for="(item, i) in (snapshot.items || [])"
                  :key="i"
                  class="receipt-doc-item-row"
                >
                  <span>{{ item.description }}</span>
                  <span class="receipt-doc-amount-col">{{ formatCurrency(item.amount) }}</span>
                </div>
                <div class="receipt-doc-item-row receipt-doc-total-row">
                  <span>合計</span>
                  <span class="receipt-doc-amount-col">{{ formatCurrency(snapshot.total_amount) }}</span>
                </div>
              </div>

              <div class="receipt-doc-row">
                <span class="receipt-doc-label">收款日期</span>
                <span class="receipt-doc-value">{{ snapshot.paid_at || '—' }}</span>
              </div>
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">收款方式</span>
                <span class="receipt-doc-value">{{ paymentMethodLabel(snapshot.method) }}</span>
              </div>

              <div class="receipt-doc-refund" v-if="snapshot.refund_policy">
                <div class="receipt-doc-label">退費規定</div>
                <div class="receipt-doc-refund-text">{{ snapshot.refund_policy }}</div>
              </div>
            </div>

            <div class="receipt-doc-footer">
              <div>經辦人：__________</div>
              <div>補習班用印：</div>
              <div class="receipt-doc-footer-note">
                此收據由 AllTrue 系統產生<br />
                開立時間：{{ snapshot.confirmed_at || snapshot.paid_at || '—' }}
              </div>
            </div>

            <div v-if="receipt.status === 'voided'" class="receipt-voided-watermark">作廢</div>
          </div>
        </div>

        <!-- Actions -->
        <div class="receipt-actions">
          <button class="ghost" type="button" @click="printReceipt">
            <span class="material-symbols-outlined">print</span>
            列印
          </button>
        </div>
      </template>
    </div>

    <!-- Void / PDF / legal-info deferred — backend not on main (§R79) -->
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import {
  adaptPaymentReportReceipt,
  paymentReportReceiptUrl,
  parsePositiveReportId,
} from '../lib/paymentReportReceipt.js';

const props = defineProps({
  show: Boolean,
  reportId: { type: Number, default: null },
});
defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const receipt = ref(null);
const receiptPrintRef = ref(null);

const snapshot = computed(() => receipt.value?.content_snapshot || {});
const schoolName = computed(() => snapshot.value.school_name || '台北全真一對一補習班');

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}
function formatDateTime(dt) {
  if (!dt) return '—';
  try { return new Date(dt).toLocaleString('zh-TW'); } catch { return dt; }
}
function paymentMethodLabel(m) {
  const labels = { cash: '現金', transfer: '匯款', card: '信用卡', line_pay: 'LINE Pay', backfill: '現金（補建）' };
  return labels[m] || m || '—';
}
function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function parseError(resp) {
  const body = await resp.json().catch(() => ({}));
  if (body.message) return new Error(body.message);
  if (resp.status === 403) return new Error('沒有權限查看此收據（可能跨分校）');
  if (resp.status === 404) return new Error('找不到這筆核帳紀錄，無法產生收據');
  if (resp.status === 422) return new Error('尚未核帳確認，無法產生收據');
  return new Error(`載入收據失敗（${resp.status}）`);
}

/** Hotfix (#1197): only GET payment-reports/{id}/receipt — never /api/v1/receipts*. */
async function loadReceipt() {
  loading.value = true;
  error.value = '';
  receipt.value = null;
  const token = getToken();
  if (!token) { error.value = '請先登入'; loading.value = false; return; }

  const reportId = parsePositiveReportId(props.reportId);
  if (reportId == null) {
    error.value = '缺少核帳紀錄編號，無法開啟收據';
    loading.value = false;
    return;
  }

  try {
    const resp = await fetch(paymentReportReceiptUrl(reportId), {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw await parseError(resp);
    receipt.value = adaptPaymentReportReceipt(await resp.json(), reportId);
  } catch (e) {
    error.value = e.message || '載入收據失敗';
  } finally {
    loading.value = false;
  }
}

function printReceipt() {
  if (!receiptPrintRef.value) return;
  window.print();
}

watch(() => [props.show, props.reportId], async ([visible]) => {
  if (!visible) return;
  await loadReceipt();
}, { immediate: true });
</script>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 10000; padding: 16px;
}
.modal {
  background: var(--card-bg); border-radius: 14px; padding: 20px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.receipt-modal { width: 100%; max-width: 680px; max-height: 92vh; overflow-y: auto; }
.receipt-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.receipt-header h3 { margin: 0; font-size: 18px; }
.icon-btn { border: none; background: none; cursor: pointer; padding: 4px; border-radius: 8px; color: var(--text-light); }
.icon-btn:hover { background: var(--bg); }

.receipt-loading, .receipt-error {
  text-align: center; padding: 48px 0; color: var(--text-light);
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.spin { animation: rotate 1s linear infinite; font-size: 32px; }
@keyframes rotate { to { transform: rotate(360deg); } }

.receipt-backfill-notice {
  background: var(--ds-warning-wash); border: 1px solid var(--ds-warning); color: var(--ds-warning);
  padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;
  display: flex; align-items: flex-start; gap: 6px;
}
.receipt-voided-banner {
  background: var(--ds-danger-wash); border: 1px solid var(--ds-danger); color: var(--ds-danger);
  padding: 12px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;
  display: flex; align-items: flex-start; gap: 8px;
}
.receipt-voided-banner .material-symbols-outlined { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.receipt-voided-banner p { margin: 4px 0 0; }
.voided-meta { font-size: 11px; opacity: 0.8; }

.receipt-preview-wrap {
  background: var(--bg); border-radius: 12px; padding: 16px;
  display: flex; justify-content: center; overflow-x: auto;
}
.receipt-preview-wrap.voided { opacity: 0.7; }
.receipt-document {
  width: 100%; max-width: 560px; border: 1px solid var(--border);
  border-radius: 8px; overflow: hidden; background: #fff; position: relative;
}
.receipt-doc-header { background: #14532d; color: #fff; padding: 20px; text-align: center; }
.receipt-doc-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.receipt-doc-brand { font-size: 13px; opacity: 0.85; }
.receipt-doc-license { font-size: 11px; opacity: 0.7; margin-top: 2px; }
.receipt-doc-address { font-size: 11px; opacity: 0.7; }
.receipt-doc-body { padding: 16px 20px; }
.receipt-doc-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.receipt-doc-label { color: var(--text-light); font-weight: 500; }
.receipt-doc-value { font-weight: 500; text-align: right; }
.receipt-doc-number { font-family: monospace; font-size: 14px; color: #14532d; }
.receipt-doc-items { margin: 10px 0; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.receipt-doc-items-header { display: flex; justify-content: space-between; padding: 7px 10px; background: var(--bg); font-size: 11px; font-weight: 600; color: var(--text-light); }
.receipt-doc-item-row { display: flex; justify-content: space-between; padding: 7px 10px; border-top: 1px solid var(--border); font-size: 13px; }
.receipt-doc-amount-col { text-align: right; font-variant-numeric: tabular-nums; }
.receipt-doc-total-row { background: var(--ds-success-wash); font-weight: 700; }
.receipt-doc-refund { padding: 10px 0; font-size: 12px; }
.receipt-doc-refund-text { margin-top: 4px; color: var(--text-light); line-height: 1.6; white-space: pre-line; }
.receipt-doc-footer { padding: 12px 20px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-light); display: flex; justify-content: space-between; align-items: flex-start; }
.receipt-doc-footer-note { text-align: right; line-height: 1.4; }
.receipt-voided-watermark {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-25deg);
  font-size: 80px; font-weight: 900; color: rgba(220,38,38,.12);
  pointer-events: none; white-space: nowrap; user-select: none;
}

.receipt-actions { display: flex; gap: 8px; justify-content: center; margin-top: 16px; flex-wrap: wrap; align-items: center; }
.receipt-actions button { display: inline-flex; align-items: center; gap: 6px; }
.receipt-pdf-format { display: flex; gap: 12px; margin-right: 8px; }
.receipt-format-label { font-size: 12px; display: flex; align-items: center; gap: 4px; cursor: pointer; }
.receipt-btn-void { color: var(--ds-danger); }
.receipt-btn-void:hover { background: var(--ds-danger-wash); }

.receipt-legal-setup { display: flex; flex-direction: column; gap: 16px; }
.receipt-legal-notice {
  display: flex; align-items: flex-start; gap: 8px; padding: 12px 14px;
  background: var(--ds-warning-wash); border: 1px solid var(--ds-warning);
  border-radius: 8px; color: var(--ds-warning); font-size: 13px;
}
.receipt-legal-notice p { margin: 4px 0 0; font-size: 12px; }
.legal-form { display: flex; flex-direction: column; gap: 12px; }
.legal-form label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 500; }
.legal-form input, .legal-form textarea {
  min-height: 38px; border: 1px solid var(--border); border-radius: 8px;
  padding: 8px 10px; background: var(--bg); color: var(--text); font: inherit; font-size: 13px;
}
.legal-form textarea { resize: vertical; min-height: 64px; }
.legal-form-actions { display: flex; justify-content: flex-end; gap: 8px; }

.tc-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center; z-index: 10001; padding: 16px;
}
.tc-dialog {
  background: var(--card-bg); border-radius: 14px; padding: 24px;
  width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.tc-dialog-title { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 16px; }
.tc-dialog-desc { margin: 0 0 12px; font-size: 13px; color: var(--text-light); line-height: 1.5; }
.tc-dialog-info { padding: 8px 12px; background: var(--bg); border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 12px; }
.tc-dialog-info small { display: block; margin-top: 4px; color: var(--text-light); font-weight: 400; }
.tc-dialog-label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: var(--text); }
.tc-dialog-textarea {
  width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px;
  font-size: 14px; font-family: inherit; resize: vertical; min-height: 72px; outline: none;
  background: var(--bg); color: var(--text); box-sizing: border-box;
}
.tc-dialog-textarea:focus { border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
.tc-dialog-charcount { text-align: right; font-size: 11px; color: var(--text-light); margin-top: 4px; margin-bottom: 16px; }
.tc-dialog-btns { display: flex; justify-content: flex-end; gap: 8px; }

.primary, .ghost, .tc-btn--danger {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
  border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer;
  font-family: inherit; transition: all 0.15s; border: 1px solid var(--border);
}
.primary { background: var(--primary); border-color: var(--primary); color: var(--ds-on-primary); }
.primary:hover:not(:disabled) { opacity: 0.9; }
.primary:disabled { opacity: 0.5; cursor: not-allowed; }
.ghost { background: transparent; color: var(--text); }
.ghost:hover:not(:disabled) { background: var(--bg); }
.tc-btn--danger { background: var(--ds-danger); border-color: var(--ds-danger); color: var(--ds-on-primary); }
.tc-btn--danger:hover:not(:disabled) { opacity: 0.9; }
.tc-btn--danger:disabled { background: var(--ds-hairline); border-color: var(--ds-hairline); cursor: not-allowed; }

.fade-enter-active { transition: opacity 0.2s ease; }
.fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 640px) {
  .receipt-modal { max-width: 100%; padding: 14px; }
  .receipt-doc-body { padding: 12px 14px; }
  .receipt-doc-header { padding: 16px; }
}
</style>
