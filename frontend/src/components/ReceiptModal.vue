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

        <!-- Billing mode changed since issue (#934) -->
        <div v-if="receipt.billing_mode_changed" class="receipt-backfill-notice">
          <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;margin-top:1px">warning</span>
          <span>此課程計費模式已變更，此收據可能已被取代，請以最新繳費紀錄為準。</span>
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

        <!-- Receipt document preview: exactly what the image export captures. -->
        <div class="receipt-preview-wrap" :class="{ voided: receipt.status === 'voided' }">
          <BillingDocument ref="docRef" :doc="doc" class="receipt-document" />
        </div>

        <div v-if="snapshot.course_lifecycle_label || snapshot.first_session_note" class="receipt-ops" aria-label="帳務說明">
          <p v-if="snapshot.course_lifecycle_label">課程狀態：{{ snapshot.course_lifecycle_label }}</p>
          <p v-if="snapshot.first_session_note">
            第一堂課：{{ snapshot.first_session_display || '—' }}
            · {{ snapshot.first_session_note }}
          </p>
        </div>

        <!-- Actions -->
        <div class="receipt-actions">
          <button data-testid="copy-receipt-image" class="primary" type="button" :disabled="copyState === 'copying'" @click="copyReceiptImage">
            <span class="material-symbols-outlined">image</span>
            {{ copyState === 'copied-image' ? '已複製圖片' : '複製圖片' }}
          </button>
          <button data-testid="copy-receipt-text" class="ghost" type="button" :disabled="copyState === 'copying'" @click="copyReceiptText">
            <span class="material-symbols-outlined">content_copy</span>
            {{ copyState === 'copied-text' ? '已複製文字' : '複製文字' }}
          </button>
          <button data-testid="download-receipt-image" class="ghost" type="button" :disabled="copyState === 'copying'" @click="downloadReceiptImage">
            <span class="material-symbols-outlined">download</span>
            下載圖片
          </button>
          <button class="ghost" type="button" @click="printReceipt">
            <span class="material-symbols-outlined">print</span>
            列印
          </button>
          <span v-if="copyState === 'error'" class="receipt-copy-error" role="status">{{ copyError }}</span>
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
  buildReceiptCopyText,
  paymentReportReceiptUrl,
  parsePositiveReportId,
} from '../lib/paymentReportReceipt.js';
import { receiptImageBlob } from '../lib/receiptImage.js';
import { receiptView } from '../lib/billingDocumentView.js';
import BillingDocument from './BillingDocument.vue';

const props = defineProps({
  show: Boolean,
  reportId: { type: Number, default: null },
});
defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const receipt = ref(null);
const docRef = ref(null);
const copyState = ref('idle');
const copyError = ref('');

const snapshot = computed(() => receipt.value?.content_snapshot || {});
const doc = computed(() => (receipt.value ? receiptView(receipt.value) : null));

function formatDateTime(dt) {
  if (!dt) return '—';
  try { return new Date(dt).toLocaleString('zh-TW'); } catch { return dt; }
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
  if (!docRef.value) return;
  window.print();
}

async function copyReceiptImage() {
  if (!receipt.value) return;
  copyState.value = 'copying';
  copyError.value = '';
  try {
    if (typeof navigator.clipboard?.write !== 'function' || typeof window.ClipboardItem !== 'function') {
      throw new Error('image_clipboard_unsupported');
    }
    const blob = await receiptImageBlob({ source: docRef.value?.$el });
    await navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]);
    copyState.value = 'copied-image';
  } catch (error) {
    copyState.value = 'error';
    copyError.value = error.message === 'image_clipboard_unsupported'
      ? '此瀏覽器不支援直接複製圖片，請按「下載圖片」後再傳給家長。'
      : '複製圖片失敗，請按「下載圖片」後再傳給家長。';
  }
}

async function copyReceiptText() {
  if (!receipt.value) return;
  copyState.value = 'copying';
  copyError.value = '';
  const text = buildReceiptCopyText(snapshot.value, receipt.value.receipt_number);
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      if (!document.execCommand('copy')) throw new Error('copy_failed');
      textarea.remove();
    }
    copyState.value = 'copied-text';
  } catch {
    copyState.value = 'error';
    copyError.value = '複製文字失敗，請改用列印或手動選取收據內容。';
  }
}

async function downloadReceiptImage() {
  if (!receipt.value) return;
  copyState.value = 'copying';
  copyError.value = '';
  try {
    const blob = await receiptImageBlob({ source: docRef.value?.$el });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `電子收據-${receipt.value.receipt_number || 'receipt'}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    copyState.value = 'downloaded';
  } catch {
    copyState.value = 'error';
    copyError.value = '下載圖片失敗，請改用列印或手動截圖。';
  }
}

watch(() => [props.show, props.reportId], async ([visible]) => {
  if (!visible) return;
  copyState.value = 'idle';
  copyError.value = '';
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
.receipt-ops { margin-top: 12px; padding: 10px 12px; background: var(--ds-canvas-soft); border-radius: 8px; font-size: 12px; color: var(--ds-ink-mute); }
.receipt-ops p { margin: 0 0 4px; }
.receipt-ops p:last-child { margin-bottom: 0; }
@media print { .receipt-ops { display: none; } }

.receipt-actions { display: flex; gap: 8px; justify-content: center; margin-top: 16px; flex-wrap: wrap; align-items: center; }
.receipt-actions button { display: inline-flex; align-items: center; gap: 6px; }
.receipt-copy-error { flex-basis: 100%; color: var(--ds-danger); font-size: 12px; text-align: center; }
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
}
</style>
