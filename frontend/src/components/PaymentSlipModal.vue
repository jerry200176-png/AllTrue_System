<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal slip-modal">
      <div class="slip-header">
        <h3>繳費通知單預覽</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div v-if="loading" class="slip-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="slip-error">
        <p>{{ error }}</p>
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>

      <template v-else-if="doc">
        <div class="slip-preview-wrap">
          <!-- What you see here is exactly the exported PNG. -->
          <BillingDocument ref="docRef" :doc="doc" />
        </div>
        <div class="slip-actions">
          <button class="primary" @click="downloadPng" :disabled="exporting">
            <span class="material-symbols-outlined">download</span>
            下載圖片
          </button>
          <button class="ghost" @click="copyToClipboard" :disabled="copying || exporting">
            <span class="material-symbols-outlined">content_copy</span>
            {{ copying ? '已複製' : '複製到剪貼簿' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import BillingDocument from './BillingDocument.vue';
import { paymentSlipView } from '../lib/billingDocumentView.js';

const props = defineProps({
  show: Boolean,
  invoiceId: { type: Number, default: null },
  studentClassId: { type: Number, default: null },
});
defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const raw = ref(null);
const doc = computed(() => (raw.value ? paymentSlipView(raw.value) : null));
const docRef = ref(null);
const copying = ref(false);
const exporting = ref(false);

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function fetchInvoiceSlip(id) {
  const resp = await fetch(`/api/v1/invoices/${id}/slip-data`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!resp.ok) throw new Error('無法載入帳單資料');
  return resp.json();
}

async function fetchTuitionSlip(scId) {
  const resp = await fetch(`/api/v1/alerts/tuition-slip/${scId}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!resp.ok) {
    const body = await resp.json().catch(() => ({}));
    throw new Error(body.message || '無法載入催繳資料');
  }
  return resp.json();
}

// ─── Export (modern-screenshot, MIT) ─────────────────────────────
async function renderBlob() {
  const { domToBlob } = await import('modern-screenshot');
  // font: false — the slip uses system fonts, nothing to embed.
  return domToBlob(docRef.value.$el, { scale: 2, backgroundColor: '#ffffff', type: 'image/png', font: false });
}

async function warmUp() {
  // Fonts and the logo must be ready before capture; the first capture on
  // iOS Safari can come out blank, so render once up front and discard it.
  await document.fonts?.ready;
  const img = docRef.value?.$el?.querySelector('img');
  if (img && !img.complete) await new Promise(r => { img.onload = img.onerror = r; });
  await renderBlob().catch(() => {});
}

watch(() => props.show, async (v) => {
  if (!v) return;
  if (!props.invoiceId && !props.studentClassId) return;
  loading.value = true;
  error.value = '';
  raw.value = null;
  try {
    raw.value = props.invoiceId
      ? await fetchInvoiceSlip(props.invoiceId)
      : await fetchTuitionSlip(props.studentClassId);
    loading.value = false;
    await nextTick();
    exporting.value = true;
    await warmUp();
  } catch (e) {
    error.value = e.message || '載入失敗';
    loading.value = false;
  } finally {
    exporting.value = false;
  }
}, { immediate: true });

async function downloadPng() {
  if (!docRef.value) return;
  exporting.value = true;
  try {
    const blob = await renderBlob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.download = doc.value.filename;
    link.href = url;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch {
    alert('圖片產生失敗，請再試一次');
  } finally {
    exporting.value = false;
  }
}

async function copyToClipboard() {
  if (!docRef.value) return;
  try {
    // Pass the promise straight in: Safari only allows clipboard writes
    // started synchronously inside the click.
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': renderBlob() })]);
    copying.value = true;
    setTimeout(() => { copying.value = false; }, 2000);
  } catch {
    alert('複製失敗，請使用下載功能');
  }
}
</script>

<style scoped>
.slip-modal {
  width: 100%;
  max-width: 660px;
  max-height: 92vh;
  overflow-y: auto;
  padding: 20px;
}
.slip-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.slip-header h3 { margin: 0; }
.icon-btn {
  border: none;
  background: none;
  cursor: pointer;
  padding: 4px;
  border-radius: 8px;
  color: var(--text-light);
}
.icon-btn:hover { background: var(--bg); }
.slip-loading, .slip-error {
  text-align: center;
  padding: 48px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.spin {
  animation: rotate 1s linear infinite;
  font-size: 32px;
}
@keyframes rotate { to { transform: rotate(360deg); } }

.slip-preview-wrap {
  background: var(--bg);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  justify-content: center;
  overflow-x: auto;
}
.slip-actions {
  display: flex;
  gap: 8px;
  justify-content: center;
  margin-top: 16px;
  flex-wrap: wrap;
}
.slip-actions button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>
