<template>
  <span class="tc-doc-actions">
    <span v-for="a in actions" :key="a.key" class="tc-doc-act">
      <button
        type="button"
        :class="['tc-btn', `tc-btn--${a.key}`]"
        :data-testid="`tc-doc-${a.key}`"
        :disabled="!a.state.ok || (a.key === 'receipt' && busy)"
        :aria-describedby="a.state.ok ? null : `${uid}-${a.key}`"
        :title="a.state.ok ? a.title : a.state.reason"
        @click="$emit(a.key, row)"
      >
        <span class="material-symbols-outlined">{{ a.icon }}</span>
        {{ a.label }}
      </button>
      <span v-if="!a.state.ok" :id="`${uid}-${a.key}`" class="tc-doc-reason" :data-testid="`tc-doc-${a.key}-reason`">{{ a.state.reason }}</span>
      <button v-if="a.key === 'receipt' && legacy && canView && row.id != null" type="button" class="tc-doc-link" data-testid="tc-doc-ledger" @click="$emit('ledger', row)">看繳費明細</button>
    </span>
  </span>
</template>

<script>
let seq = 0; // module-level: one counter for every instance
</script>

<script setup>
import { computed } from 'vue';
import { isLegacyPaidNoInvoice } from '../lib/legacyPaid.js';
import { slipAvailability, receiptAvailability } from '../lib/billingDocState.js';

// 繳費單／收據固定兩顆入口：可用條件在 lib/billingDocState.js（學生帳務合約卡共用同一條規則），不可用時 disabled 並寫一行原因。
const props = defineProps({
  row: { type: Object, required: true },
  canView: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
});
defineEmits(['slip', 'receipt', 'ledger']);

const uid = `tc-doc-${++seq}`; // 月結包的列 id 為 null，所以用實例流水號讓 aria-describedby 不重複。

const legacy = computed(() => isLegacyPaidNoInvoice(props.row));
const slip = computed(() => slipAvailability(props.row, props.canView));
const receipt = computed(() => receiptAvailability(props.row, props.canView));
const actions = computed(() => [
  { key: 'slip', label: '繳費單', icon: 'receipt_long', title: '產生繳費單', state: slip.value },
  { key: 'receipt', label: '收據', icon: 'receipt', title: '查看收據', state: receipt.value },
]);
</script>

<style scoped>
.tc-doc-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; flex: 1 1 100%; width: 100%; min-width: min(14rem, 100%); }
.tc-doc-act { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; min-width: 0; max-width: 100%; }
/* 頁面的 scoped .tc-btn 樣式進不到子元件，這裡複製必要部分 */
.tc-doc-act .tc-btn { display: inline-flex; align-items: center; gap: 4px; flex: 0 0 auto; min-height: 44px; padding: 5px 10px; border: 1px solid var(--border); background: var(--card-bg); border-radius: var(--ds-radius-md); cursor: pointer; font: inherit; font-size: var(--ds-font-size-sm); font-weight: 500; white-space: nowrap; }
.tc-doc-act .tc-btn .material-symbols-outlined { font-size: var(--ds-font-size-base); }
.tc-doc-act .tc-btn--slip { color: var(--ds-primary-text); }
.tc-doc-act .tc-btn--receipt { color: var(--ds-success); }
.tc-doc-act .tc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tc-doc-link { flex: 1 1 100%; min-height: 32px; padding: 0; border: 0; background: none; font-size: var(--ds-font-size-sm); text-align: left; text-decoration: underline; color: var(--ds-primary-text); cursor: pointer; }
.tc-doc-reason { flex: 1 1 6rem; min-width: 0; font-size: var(--ds-font-size-sm); line-height: 1.3; color: var(--ds-ink-mute); white-space: normal; }
</style>
