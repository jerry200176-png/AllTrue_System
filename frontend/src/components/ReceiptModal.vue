<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal receipt-modal">
      <div class="receipt-header">
        <h3>正式收據</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉" type="button">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div v-if="loading" class="receipt-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="receipt-error">
        <p>{{ error }}</p>
        <button class="ghost" type="button" @click="$emit('close')">關閉</button>
      </div>

      <template v-else-if="receipt">
        <div v-if="receipt.is_backfilled" class="receipt-backfill-notice">
          <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px">info</span>
          <span>此收據由系統依舊繳費記錄補建，原始付款方式與日期可能不精確。</span>
        </div>

        <div class="receipt-preview-wrap">
          <div ref="receiptPrintRef" class="receipt-document">
            <div class="receipt-doc-header">
              <div class="receipt-doc-title">正式收據</div>
              <div class="receipt-doc-brand">{{ schoolName }}</div>
              <div v-if="snapshot.campus_name" class="receipt-doc-address">分校：{{ snapshot.campus_name }}</div>
            </div>

            <div class="receipt-doc-body">
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">學生姓名</span>
                <span class="receipt-doc-value">{{ snapshot.student_name || '—' }}</span>
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

              <div v-if="sessionDateLines.length" class="receipt-doc-sessions">
                <div class="receipt-doc-label">上課日期</div>
                <ul class="receipt-session-list">
                  <li v-for="(line, i) in sessionDateLines" :key="i">
                    <span class="receipt-session-dot" aria-hidden="true"></span>
                    {{ line.text }}
                    <em v-if="line.expected">（預期）</em>
                  </li>
                </ul>
              </div>

              <div class="receipt-doc-row">
                <span class="receipt-doc-label">收款日期</span>
                <span class="receipt-doc-value">{{ snapshot.paid_at || '—' }}</span>
              </div>
              <div class="receipt-doc-row">
                <span class="receipt-doc-label">收款方式</span>
                <span class="receipt-doc-value">{{ paymentMethodLabel(snapshot.method) }}</span>
              </div>
              <div v-if="snapshot.confirmed_by" class="receipt-doc-row">
                <span class="receipt-doc-label">核帳人</span>
                <span class="receipt-doc-value">{{ snapshot.confirmed_by }}</span>
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
          </div>
        </div>

        <div class="receipt-actions">
          <button class="ghost" type="button" @click="printReceipt">
            <span class="material-symbols-outlined">print</span>
            列印
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import {
  adaptPaymentReportReceipt,
  paymentReportReceiptUrl,
} from '../lib/paymentReportReceipt.js';

const BRAND_TITLE = '台北全真一對一補習班';

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
const schoolName = computed(() => snapshot.value.school_name || BRAND_TITLE);

const sessionDateLines = computed(() => {
  const sessions = snapshot.value.session_dates;
  if (Array.isArray(sessions) && sessions.length) {
    return sessions.map((e) => ({
      text: e?.date ?? e,
      expected: !!(e && e.expected),
    }));
  }
  const attended = snapshot.value.attended_dates;
  if (Array.isArray(attended) && attended.length) {
    return attended.map((d) => ({ text: d, expected: false }));
  }
  return [];
});

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}

function paymentMethodLabel(m) {
  const labels = {
    cash: '現金',
    transfer: '匯款',
    card: '信用卡',
    line_pay: 'LINE Pay',
    backfill: '現金（補建）',
  };
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

async function loadReceipt() {
  loading.value = true;
  error.value = '';
  receipt.value = null;

  const token = getToken();
  if (!token) {
    error.value = '請先登入';
    loading.value = false;
    return;
  }

  const reportId = Number(props.reportId);
  if (!reportId) {
    error.value = '缺少核帳紀錄編號，無法開啟收據';
    loading.value = false;
    return;
  }

  try {
    const resp = await fetch(paymentReportReceiptUrl(reportId), {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw await parseError(resp);
    const api = await resp.json();
    receipt.value = adaptPaymentReportReceipt(api, reportId);
  } catch (e) {
    error.value = e.message || '載入收據失敗';
  } finally {
    loading.value = false;
  }
}

function printReceipt() {
  if (!receiptPrintRef.value) return;
  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    error.value = '瀏覽器封鎖了列印視窗，請允許彈出視窗後重試';
    return;
  }
  printWindow.opener = null;
  const html = receiptPrintRef.value.outerHTML;
  printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>收據 ${receipt.value?.receipt_number || ''}</title>
<style>body{font-family:"Noto Sans TC",Arial,sans-serif;color:#1f2937;margin:20px}
.receipt-document{max-width:210mm;margin:0 auto;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;position:relative}
.receipt-doc-header{background:#14532d;color:#fff;padding:24px;text-align:center}
.receipt-doc-title{font-size:22px;font-weight:700;margin-bottom:6px}
.receipt-doc-brand{font-size:14px;opacity:.85}
.receipt-doc-address{font-size:12px;opacity:.7;margin-top:4px}
.receipt-doc-body{padding:20px 24px}
.receipt-doc-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:13px}
.receipt-doc-label{color:#6b7280;font-weight:500}
.receipt-doc-value{font-weight:500;text-align:right}
.receipt-doc-number{font-family:monospace;font-size:14px;color:#14532d}
.receipt-doc-items{margin:12px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
.receipt-doc-items-header{display:flex;justify-content:space-between;padding:8px 12px;background:#f3f4f6;font-size:12px;font-weight:600;color:#6b7280}
.receipt-doc-item-row{display:flex;justify-content:space-between;padding:8px 12px;border-top:1px solid #e5e7eb;font-size:13px}
.receipt-doc-amount-col{text-align:right;font-variant-numeric:tabular-nums}
.receipt-doc-total-row{background:#f0fdf4;font-weight:700}
.receipt-doc-sessions{padding:12px 0;font-size:12px}
.receipt-session-list{list-style:none;margin:8px 0 0;padding:0}
.receipt-session-list li{display:flex;align-items:center;gap:8px;padding:4px 0;color:#374151}
.receipt-session-dot{width:8px;height:8px;border-radius:50%;background:#16a34a;flex-shrink:0}
.receipt-session-list em{color:#6b7280;font-style:normal;margin-left:4px}
.receipt-doc-footer{padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;display:flex;justify-content:space-between}
.receipt-doc-footer-note{text-align:right}
@media print{body{margin:15mm}}</style></head><body>${html}</body></html>`);
  printWindow.document.close();
  setTimeout(() => printWindow.print(), 300);
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
.icon-btn:hover { background: var(--bg); color: var(--text); }
.receipt-loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 48px; color: var(--text-light); }
.receipt-error { text-align: center; padding: 32px 16px; color: var(--danger, #dc2626); }
.receipt-error p { margin: 0 0 16px; }
.receipt-backfill-notice {
  display: flex; gap: 8px; align-items: flex-start;
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
  padding: 10px 14px; margin-bottom: 12px; font-size: 13px; color: #92400e;
}
.receipt-preview-wrap { margin-bottom: 16px; }
.receipt-document {
  border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; position: relative;
  background: #fff; color: #1f2937;
}
.receipt-doc-header { background: #14532d; color: #fff; padding: 24px; text-align: center; }
.receipt-doc-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.receipt-doc-brand { font-size: 14px; opacity: .85; }
.receipt-doc-address { font-size: 12px; opacity: .7; margin-top: 4px; }
.receipt-doc-body { padding: 20px 24px; }
.receipt-doc-row {
  display: flex; justify-content: space-between; padding: 8px 0;
  border-bottom: 1px solid #e5e7eb; font-size: 13px;
}
.receipt-doc-label { color: #6b7280; font-weight: 500; }
.receipt-doc-value { font-weight: 500; text-align: right; }
.receipt-doc-number { font-family: monospace; font-size: 14px; color: #14532d; }
.receipt-doc-items { margin: 12px 0; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
.receipt-doc-items-header {
  display: flex; justify-content: space-between; padding: 8px 12px;
  background: #f3f4f6; font-size: 12px; font-weight: 600; color: #6b7280;
}
.receipt-doc-item-row {
  display: flex; justify-content: space-between; padding: 8px 12px;
  border-top: 1px solid #e5e7eb; font-size: 13px;
}
.receipt-doc-amount-col { text-align: right; font-variant-numeric: tabular-nums; }
.receipt-doc-total-row { background: #f0fdf4; font-weight: 700; }
.receipt-doc-sessions { padding: 12px 0; font-size: 12px; }
.receipt-session-list { list-style: none; margin: 8px 0 0; padding: 0; }
.receipt-session-list li { display: flex; align-items: center; gap: 8px; padding: 4px 0; color: #374151; }
.receipt-session-dot { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; flex-shrink: 0; }
.receipt-session-list em { color: #6b7280; font-style: normal; margin-left: 4px; }
.receipt-doc-footer {
  padding: 16px 24px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af;
  display: flex; justify-content: space-between;
}
.receipt-doc-footer-note { text-align: right; }
.receipt-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
