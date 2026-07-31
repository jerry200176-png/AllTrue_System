<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal lpi-modal">
      <div class="lpi-header">
        <h3>最新繳費資訊</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <p class="lpi-subtitle">{{ course?.student_name || '' }} — {{ course?.subject || '' }}</p>

      <div v-if="loading" class="lpi-loading">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入中…</span>
      </div>

      <div v-else-if="error" class="lpi-error">{{ error }}</div>

      <div v-else-if="!latest" class="lpi-empty">尚無繳費紀錄</div>

      <div v-else class="lpi-body">
        <div class="lpi-row">
          <span class="lpi-label">狀態</span>
          <span class="lpi-value">
            <span class="lpi-status-chip" :class="latest.status || 'unknown'">{{ statusLabel(latest.status) }}</span>
          </span>
        </div>
        <div class="lpi-row">
          <span class="lpi-label">金額</span>
          <span class="lpi-value lpi-amount">NT$ {{ Number(latest.amount || 0).toLocaleString('zh-TW') }}</span>
        </div>
        <div class="lpi-row">
          <span class="lpi-label">繳費日期</span>
          <span class="lpi-value">{{ latest.paid_at || '—' }}</span>
        </div>
        <div class="lpi-row">
          <span class="lpi-label">繳費方式</span>
          <span class="lpi-value">{{ methodLabel(latest.method) }}</span>
        </div>
        <div v-if="latest.method === 'transfer' && latest.account_last5" class="lpi-row">
          <span class="lpi-label">匯款帳號後5碼</span>
          <span class="lpi-value">{{ latest.account_last5 }}</span>
        </div>
        <div class="lpi-row">
          <span class="lpi-label">備註</span>
          <span class="lpi-value">{{ latest.note || '無' }}</span>
        </div>

        <div class="lpi-receipt">
          <button
            v-if="latest.report_id && latest.status === 'confirmed'"
            class="small primary"
            @click="$emit('view-receipt', latest.report_id)"
          >
            <span class="material-symbols-outlined" style="font-size:16px">receipt_long</span>
            查看收據
          </button>
          <span v-else-if="latest.report_id" class="lpi-receipt-hint">尚未核帳確認，暫無收據</span>
          <span v-else class="lpi-receipt-hint">此筆繳費無電子收據記錄</span>
        </div>
      </div>

      <div class="actions" style="margin-top: 16px;">
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  course: { type: Object, default: null },
});
defineEmits(['close', 'view-receipt']);

const loading = ref(false);
const error = ref('');
const payments = ref([]);

// "最新" = paid_at desc；沒有 domain rule 定義同日多筆時的順序，以付款 id desc 作為穩定 tie-breaker。
const latest = computed(() => {
  const effective = payments.value.filter((p) => !p.is_void);
  if (effective.length === 0) return null;
  const sorted = [...effective].sort((a, b) => {
    const dateCompare = (b.paid_at || '').localeCompare(a.paid_at || '');
    if (dateCompare !== 0) return dateCompare;
    return (b.id || 0) - (a.id || 0);
  });
  return sorted[0];
});

function statusLabel(status) {
  const labels = { confirmed: '已收款', pending: '待核帳', rejected: '已退回', voided: '已撤銷' };
  return labels[status] || '已收款';
}
function methodLabel(method) {
  const labels = { cash: '現金', transfer: '匯款', void: '作廢' };
  return labels[method] || method || '—';
}

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function load() {
  loading.value = true;
  error.value = '';
  payments.value = [];
  const courseId = props.course?.id;
  if (!courseId) { loading.value = false; return; }

  const token = getToken();
  if (!token) { error.value = '請先登入'; loading.value = false; return; }

  try {
    const res = await fetch(`/api/v1/student-classes/${courseId}/invoices`, {
      credentials: 'include',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });

    if (!res.ok) {
      if (res.status === 403) {
        error.value = '沒有權限查看此筆繳費資訊（可能跨分校）';
      } else {
        const body = await res.json().catch(() => ({}));
        error.value = body.message || `載入失敗（${res.status}）`;
      }
      return;
    }

    const json = await res.json().catch(() => ({}));
    payments.value = (json.invoices || []).flatMap((inv) => inv.payments || []);
  } catch (e) {
    error.value = e?.message || '載入繳費資訊失敗';
  } finally {
    loading.value = false;
  }
}

watch(() => [props.show, props.course?.id], ([visible]) => {
  if (!visible) return;
  load();
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
.lpi-modal { width: 100%; max-width: 420px; }
.lpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.lpi-header h3 { margin: 0; font-size: 18px; }
.lpi-subtitle { margin: 0 0 16px; font-size: 13px; color: var(--text-light); }
.icon-btn { border: none; background: none; cursor: pointer; padding: 4px; border-radius: 8px; color: var(--text-light); }
.icon-btn:hover { background: var(--bg); }

.lpi-loading, .lpi-error, .lpi-empty {
  text-align: center; padding: 32px 0; color: var(--text-light); font-size: 13px;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.spin { animation: rotate 1s linear infinite; font-size: 24px; }
@keyframes rotate { to { transform: rotate(360deg); } }

.lpi-body { display: flex; flex-direction: column; }
.lpi-row {
  display: flex; justify-content: space-between; gap: 12px;
  padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px;
}
.lpi-label { color: var(--text-light); font-weight: 500; flex-shrink: 0; }
.lpi-value { text-align: right; font-weight: 500; }
.lpi-amount { font-variant-numeric: tabular-nums; }

.lpi-status-chip {
  display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;
  background: var(--ds-success-wash); color: var(--ds-success);
}
.lpi-status-chip.pending { background: var(--ds-warning-wash); color: var(--ds-warning); }
.lpi-status-chip.rejected,
.lpi-status-chip.voided { background: var(--ds-danger-wash); color: var(--ds-danger); }

.lpi-receipt { margin-top: 12px; display: flex; justify-content: center; }
.lpi-receipt-hint { font-size: 12px; color: var(--text-light); }

.small, .ghost, .primary {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
  border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer;
  font-family: inherit; transition: all 0.15s; border: 1px solid var(--border);
}
.primary { background: var(--primary); border-color: var(--primary); color: var(--ds-on-primary); }
.primary:hover { opacity: 0.9; }
.ghost { background: transparent; color: var(--text); }
.ghost:hover { background: var(--bg); }

@media (max-width: 640px) {
  .lpi-modal { max-width: 100%; padding: 14px; }
}
</style>
