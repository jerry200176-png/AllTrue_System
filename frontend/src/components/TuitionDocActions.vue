<template>
  <span class="tc-doc-actions">
    <span class="tc-doc-act">
      <button
        type="button"
        class="tc-btn tc-btn--slip"
        data-testid="tc-doc-slip"
        :disabled="!slip.ok"
        :aria-describedby="slip.ok ? null : `${uid}-slip`"
        :title="slip.ok ? '產生繳費單' : slip.reason"
        @click="$emit('slip', row)"
      >
        <span class="material-symbols-outlined">receipt_long</span>
        繳費單
      </button>
      <span v-if="!slip.ok" :id="`${uid}-slip`" class="tc-doc-reason" data-testid="tc-doc-slip-reason">{{ slip.reason }}</span>
    </span>
    <span class="tc-doc-act">
      <button
        type="button"
        class="tc-btn tc-btn--receipt"
        data-testid="tc-doc-receipt"
        :disabled="!receipt.ok || busy"
        :aria-describedby="receipt.ok ? null : `${uid}-receipt`"
        :title="receipt.ok ? '查看收據' : receipt.reason"
        @click="$emit('receipt', row)"
      >
        <span class="material-symbols-outlined">receipt</span>
        收據
      </button>
      <span v-if="!receipt.ok" :id="`${uid}-receipt`" class="tc-doc-reason" data-testid="tc-doc-receipt-reason">{{ receipt.reason }}</span>
      <button v-if="legacy && canView" type="button" class="tc-doc-link" data-testid="tc-doc-ledger" @click="$emit('ledger', row)">看繳費明細</button>
    </span>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { isLegacyPaidNoInvoice } from '../lib/legacyPaid.js';

// 繳費單／收據固定兩顆入口。可用條件沿用帳務中心既有的 payment_status 判斷，
// 不新增 API／金流規則；不可用時 disabled 並顯示一行原因。
const props = defineProps({
  row: { type: Object, required: true },
  canView: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
});
defineEmits(['slip', 'receipt', 'ledger']);

const SLIP_STATUSES = ['unpaid', 'partial', 'pending_reconciliation'];
const RECEIPT_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];
const NO_PERMISSION = '沒有權限';
const uid = `tc-doc-${props.row.id}`;

// 舊制已繳費、沒有帳單（後端 legacy_paid_without_invoice：無帳單且 Paid=1；
// 帳務中心待處理列沒帶旗標時，以「已繳狀態＋無 invoice_id」判斷）。
const legacy = computed(() => isLegacyPaidNoInvoice(props.row));

const slip = computed(() => {
  if (!props.canView) return { ok: false, reason: NO_PERMISSION };
  if (legacy.value) return { ok: false, reason: '已在舊系統繳清，沒有新帳單' };
  const s = props.row.payment_status;
  if (SLIP_STATUSES.includes(s)) return { ok: true };
  if (s === 'pending_report') return { ok: false, reason: '已回報，等確認入帳' };
  if (RECEIPT_STATUSES.includes(s)) return { ok: false, reason: '已繳清，不用繳費單' };
  return { ok: false, reason: '還沒開帳單' };
});

const receipt = computed(() => {
  if (!props.canView) return { ok: false, reason: NO_PERMISSION };
  if (legacy.value) return { ok: false, reason: '舊系統繳費，系統內沒有電子收據；可看繳費明細' };
  if (RECEIPT_STATUSES.includes(props.row.payment_status)) return { ok: true };
  return { ok: false, reason: '收款確認後才有收據' };
});
</script>

<style scoped>
.tc-doc-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; flex: 1 1 100%; width: 100%; min-width: min(14rem, 100%); }
.tc-doc-act { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; min-width: 0; max-width: 100%; }
.tc-doc-act .tc-btn {
  /* 頁面的 scoped .tc-btn 樣式進不到子元件，這裡複製必要部分 */
  display: inline-flex; align-items: center; gap: 4px; flex: 0 0 auto; min-height: 44px;
  padding: 5px 10px; border: 1px solid var(--border); background: var(--card-bg);
  border-radius: 7px; cursor: pointer; font: inherit; font-size: 12px; font-weight: 500; white-space: nowrap;
}
.tc-doc-act .tc-btn .material-symbols-outlined { font-size: 15px; }
.tc-doc-act .tc-btn--slip { color: var(--ds-primary-text); }
.tc-doc-act .tc-btn--receipt { color: var(--ds-success); }
.tc-doc-act .tc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tc-doc-link { background: none; border: 0; padding: 0; min-height: 44px; font-size: 12px; color: var(--ds-primary-text); text-decoration: underline; cursor: pointer; flex: 1 1 100%; text-align: left; min-height: 32px; }
.tc-doc-reason { flex: 1 1 6rem; min-width: 0; font-size: 12px; line-height: 1.3; color: var(--ds-ink-mute); white-space: normal; }
</style>
