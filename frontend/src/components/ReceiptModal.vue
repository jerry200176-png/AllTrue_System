<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal receipt-modal">
      <div class="receipt-header">
        <h3>正式收據</h3>
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

      <!-- Missing legal info setup -->
      <div v-else-if="showLegalSetup" class="receipt-legal-setup">
        <div class="receipt-legal-notice">
          <span class="material-symbols-outlined">warning</span>
          <div>
            <strong>無法開立收據：分校法定資訊未完整設定</strong>
            <p>缺少以下欄位：{{ missingFields.join('、') }}</p>
          </div>
        </div>
        <form class="legal-form" @submit.prevent="submitLegalInfo">
          <label v-if="missingFields.includes('地址')">
            補習班地址
            <input v-model="legalForm.address" type="text" required placeholder="台北市〇〇區〇〇路〇號" />
          </label>
          <label v-if="missingFields.includes('立案證號')">
            立案證號
            <input v-model="legalForm.license_number" type="text" required placeholder="北市教終字第XXXXX號" />
          </label>
          <label v-if="missingFields.includes('退費規定')">
            退費規定
            <textarea v-model="legalForm.refund_policy_text" rows="3" placeholder="依《短期補習班設立及管理準則》第24條辦理…"></textarea>
          </label>
          <div class="legal-form-actions">
            <button type="button" class="ghost" @click="$emit('close')">稍後設定</button>
            <button type="submit" class="primary" :disabled="legalSaving">
              <span v-if="legalSaving" class="material-symbols-outlined spin">progress_activity</span>
              儲存並開立收據
            </button>
          </div>
        </form>
      </div>

      <template v-else-if="receipt">
        <!-- Backfill notice legacy -->
        <div v-if="receipt.payment_report_id && receipt.is_backfilled" class="receipt-backfill-notice">
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
              <div class="receipt-doc-title">正式收據</div>
              <div class="receipt-doc-brand">{{ schoolName }}</div>
              <div class="receipt-doc-license">立案證號：{{ snapshot.license_number || '—' }}</div>
              <div class="receipt-doc-address">{{ snapshot.address || '—' }}</div>
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
                開立時間：{{ formatDateTime(receipt.issued_at) }}
              </div>
            </div>

            <div v-if="receipt.status === 'voided'" class="receipt-voided-watermark">作廢</div>
          </div>
        </div>

        <!-- Actions -->
        <div class="receipt-actions">
          <div class="receipt-pdf-format">
            <label class="receipt-format-label">
              <input type="radio" v-model="pdfFormat" value="a4" /> A4
            </label>
            <label class="receipt-format-label">
              <input type="radio" v-model="pdfFormat" value="thermal80" /> 80mm 熱感紙
            </label>
          </div>
          <button class="primary" @click="downloadPdf" :disabled="pdfDownloading">
            <span v-if="pdfDownloading" class="material-symbols-outlined spin">progress_activity</span>
            <span v-else class="material-symbols-outlined">download</span>
            下載 PDF
          </button>
          <button class="ghost" @click="printReceipt">
            <span class="material-symbols-outlined">print</span>
            列印
          </button>
          <button
            v-if="receipt.status === 'active'"
            class="ghost receipt-btn-void"
            @click="openVoidDialog"
          >
            <span class="material-symbols-outlined">cancel</span>
            作廢收據
          </button>
        </div>
      </template>
    </div>

    <!-- Void Confirmation Dialog -->
    <Transition name="fade">
      <div v-if="voidDialogOpen" class="tc-overlay" @click.self="voidDialogOpen = false">
        <div class="tc-dialog">
          <h3 class="tc-dialog-title">
            <span class="material-symbols-outlined" style="font-size:22px;color:var(--danger)">warning</span>
            作廢收據確認
          </h3>
          <p class="tc-dialog-desc">
            作廢後收據將顯示「作廢」浮水印，且此操作無法復原。原付款紀錄不受影響，仍可重新開立收據。
          </p>
          <div class="tc-dialog-info" v-if="receipt">
            <span>{{ snapshot.student_name }} — {{ receipt.receipt_number }}</span>
            <small>{{ formatCurrency(snapshot.total_amount) }}</small>
          </div>
          <label class="tc-dialog-label">作廢原因（必填）</label>
          <textarea
            v-model="voidReason"
            class="tc-dialog-textarea"
            placeholder="請輸入作廢原因…"
            maxlength="500"
            rows="3"
          ></textarea>
          <div class="tc-dialog-charcount">{{ voidReason.length }} / 500</div>
          <div class="tc-dialog-btns">
            <button class="ghost" @click="voidDialogOpen = false" :disabled="voiding">取消</button>
            <button class="tc-btn--danger" @click="confirmVoid" :disabled="!voidReason.trim() || voiding">
              <span v-if="voiding" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
              確認作廢
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';

const props = defineProps({
  show: Boolean,
  reportId: { type: Number, default: null },
  paymentId: { type: Number, default: null },
  receiptId: { type: Number, default: null },
  campusId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'receiptCreated']);

const loading = ref(false);
const error = ref('');
const receipt = ref(null);
const pdfDownloading = ref(false);
const pdfFormat = ref('a4');
const receiptPrintRef = ref(null);

const voidDialogOpen = ref(false);
const voidReason = ref('');
const voiding = ref(false);

const showLegalSetup = ref(false);
const missingFields = ref([]);
const legalSaving = ref(false);
const legalForm = reactive({ address: '', license_number: '', refund_policy_text: '' });

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
  const labels = { cash: '現金', transfer: '匯款', card: '信用卡', line_pay: 'LINE Pay' };
  return labels[m] || m || '—';
}
function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function loadReceipt() {
  loading.value = true;
  error.value = '';
  receipt.value = null;
  showLegalSetup.value = false;
  missingFields.value = [];
  const token = getToken();
  if (!token) { error.value = '請先登入'; loading.value = false; return; }

  try {
    if (props.receiptId) {
      const resp = await fetch(`/api/v1/receipts/${props.receiptId}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!resp.ok) throw await parseError(resp);
      receipt.value = await resp.json();
      loading.value = false;
      return;
    }

    const queryParam = props.paymentId
      ? `payment_id=${props.paymentId}`
      : props.reportId ? `payment_report_id=${props.reportId}` : null;
    if (!queryParam) { error.value = '未提供付款資訊'; loading.value = false; return; }

    let resp = await fetch(`/api/v1/receipts?${queryParam}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    if (resp.ok) {
      const list = await resp.json();
      const data = Array.isArray(list?.data) ? list.data : Array.isArray(list) ? list : [];
      const active = data.find(r => r.status === 'active');
      if (active) { receipt.value = active; loading.value = false; return; }
      if (data.length > 0) { receipt.value = data[0]; loading.value = false; return; }
    }

    const body = props.paymentId ? { payment_id: props.paymentId } : { payment_report_id: props.reportId };
    resp = await fetch('/api/v1/receipts', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });

    if (resp.status === 422) {
      const err = await resp.json().catch(() => ({}));
      if (err.missing_fields) { showLegalSetup.value = true; missingFields.value = err.missing_fields; loading.value = false; return; }
      throw new Error(err.message || '無法開立收據');
    }
    if (!resp.ok) throw await parseError(resp);
    receipt.value = await resp.json();
    emit('receiptCreated', receipt.value);
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function parseError(resp) {
  const body = await resp.json().catch(() => ({}));
  return new Error(body.message || `請求失敗（${resp.status}）`);
}

async function downloadPdf() {
  if (!receipt.value) return;
  pdfDownloading.value = true;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/receipts/${receipt.value.id}/pdf?format=${pdfFormat.value}`, {
      headers: { Accept: 'application/pdf', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw await parseError(resp);
    const blob = await resp.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `收據_${receipt.value.receipt_number}.pdf`;
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = e.message || 'PDF 下載失敗';
  } finally {
    pdfDownloading.value = false;
  }
}

function printReceipt() {
  if (!receiptPrintRef.value) return;
  const printWindow = window.open('', '_blank');
  if (!printWindow) { error.value = '瀏覽器封鎖了列印視窗，請允許彈出視窗後重試'; return; }
  printWindow.opener = null;
  const html = receiptPrintRef.value.outerHTML;
  printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>收據 ${receipt.value?.receipt_number || ''}</title>
<style>body{font-family:"Noto Sans TC",Arial,sans-serif;color:#1f2937;margin:20px}
.receipt-document{max-width:210mm;margin:0 auto;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;position:relative}
.receipt-doc-header{background:#14532d;color:#fff;padding:24px;text-align:center}
.receipt-doc-title{font-size:22px;font-weight:700;margin-bottom:6px}
.receipt-doc-brand{font-size:14px;opacity:.85}
.receipt-doc-license{font-size:12px;opacity:.7;margin-top:4px}
.receipt-doc-address{font-size:12px;opacity:.7}
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
.receipt-doc-refund{padding:12px 0;font-size:12px}
.receipt-doc-refund-text{margin-top:4px;color:#6b7280;line-height:1.6;white-space:pre-line}
.receipt-doc-footer{padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;display:flex;justify-content:space-between}
.receipt-doc-footer-note{text-align:right}
.receipt-voided-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-25deg);font-size:96px;font-weight:900;color:rgba(220,38,38,.15);pointer-events:none;white-space:nowrap}
@media print{body{margin:15mm}}</style></head><body>${html}</body></html>`);
  printWindow.document.close();
  setTimeout(() => printWindow.print(), 300);
}

function openVoidDialog() { voidReason.value = ''; voidDialogOpen.value = true; }

async function confirmVoid() {
  if (!receipt.value || !voidReason.value.trim()) return;
  voiding.value = true;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/receipts/${receipt.value.id}/void`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ reason: voidReason.value.trim() }),
    });
    if (!resp.ok) throw await parseError(resp);
    receipt.value = await resp.json();
    voidDialogOpen.value = false;
  } catch (e) {
    error.value = e.message || '作廢失敗';
  } finally {
    voiding.value = false;
  }
}

async function submitLegalInfo() {
  if (!props.campusId) { error.value = '缺少校區資訊'; return; }
  legalSaving.value = true;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/campuses/${props.campusId}/legal-info`, {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(legalForm),
    });
    if (!resp.ok) throw await parseError(resp);
    showLegalSetup.value = false;
    await loadReceipt();
  } catch (e) {
    error.value = e.message || '設定失敗';
  } finally {
    legalSaving.value = false;
  }
}

watch(() => props.show, async (v) => {
  if (!v) return;
  if (!props.receiptId && !props.paymentId && !props.reportId) {
    error.value = '未提供收據或付款資訊';
    return;
  }
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
