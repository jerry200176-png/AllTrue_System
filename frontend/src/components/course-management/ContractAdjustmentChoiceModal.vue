<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal adjustment-choice-modal" role="dialog" aria-modal="true" aria-labelledby="contract-adjustment-title">
      <h3 id="contract-adjustment-title" class="modal-title">合約／堂次調整</h3>
      <p class="modal-desc">{{ studentName }}／{{ subjectLabel }}</p>
      <p class="choice-intro">先想清楚你要處理的是哪一件事，再點進去。兩個「改少堂數」流程用途不同，選錯會走到不對的結果。</p>

      <div class="choice-list">
        <button
          type="button"
          class="choice-card"
          :class="{ 'choice-card--disabled': !billingEnabled }"
          :disabled="!billingEnabled"
          :aria-disabled="!billingEnabled ? 'true' : undefined"
          :title="billingDisabledReason || undefined"
          @click="onChoose('billing')"
        >
          <span class="choice-card__icon" aria-hidden="true">↺</span>
          <span class="choice-card__copy">
            <strong>課還要繼續，只是堂數／金額開錯</strong>
            <small>未付款時把應收堂數改少（例如 5 堂改成 4 堂），費用會一併重算；已上課紀錄保留。</small>
            <small v-if="!billingEnabled" class="choice-card__blocked">{{ billingDisabledReason }}</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>

        <button type="button" class="choice-card choice-card--amendment" @click="onChoose('amendment')">
          <span class="choice-card__icon" aria-hidden="true">⊖</span>
          <span class="choice-card__copy">
            <strong>學生不上了，這期合約要結束</strong>
            <small>把總堂數調成已完成堂數、剩餘清零，並取消未來預排。不會自動改金額或退費。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>

        <button type="button" class="choice-card" @click="onChoose('transfer')">
          <span class="choice-card__icon" aria-hidden="true">↪</span>
          <span class="choice-card__copy">
            <strong>上課紀錄掛錯合約，要搬到另一份</strong>
            <small>只搬移評量與點名紀錄；不改任何課程堂數或金額。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>
      </div>

      <p class="choice-footnote">提前結束會保留原合約、已上課紀錄與稽核軌跡；帳務要另處理。不要用「編輯」直接改已發生的扣堂資料。</p>

      <div class="actions">
        <button type="button" class="ghost" @click="$emit('close')">取消</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  subject: { type: String, default: '' },
  /** Course payment_status: unpaid | partial | pending_report | paid */
  paymentStatus: { type: String, default: '' },
  /** When false, hide/disable unpaid billing correction regardless of paymentStatus. */
  billingAvailable: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'choose']);

const subjectLabel = computed(() => getSubjectLabel(props.subject));

const normalizedPaymentStatus = computed(() => String(props.paymentStatus || '').trim().toLowerCase());

const billingEnabled = computed(() => {
  if (!props.billingAvailable) return false;
  // Only enable when explicitly unpaid; unknown/empty is treated as unavailable to avoid wrong path.
  return normalizedPaymentStatus.value === 'unpaid';
});

const billingDisabledReason = computed(() => {
  if (billingEnabled.value) return '';
  if (!props.billingAvailable) {
    return '此課程不適用「未付款堂數改少」；若學生不繼續，請選「這期合約要結束」。';
  }
  if (normalizedPaymentStatus.value === 'paid') {
    return '已繳費不可用此流程；若學生不繼續，請選「這期合約要結束」。帳務差額請另走帳務中心。';
  }
  if (normalizedPaymentStatus.value === 'pending_report') {
    return '尚有待對帳繳費回報，請先到帳務中心處理後再改堂數。';
  }
  if (normalizedPaymentStatus.value === 'partial') {
    return '已有部分收款，不可用此流程改堂數；請先到帳務中心處理。';
  }
  return '僅未付款課程可用「堂數／金額開錯」流程；其他情況請選結束合約或轉移紀錄。';
});

function onChoose(action) {
  if (action === 'billing' && !billingEnabled.value) return;
  emit('choose', action);
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.choice-intro { margin: 0 0 14px; color: var(--ds-ink-mute); font-size: 13px; line-height: 1.5; }
.choice-list { display: grid; gap: 10px; }
.choice-card {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 13px 12px;
  border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft);
  color: var(--text); text-align: left; cursor: pointer;
}
.choice-card:hover, .choice-card:focus-visible { border-color: var(--ds-primary); background: var(--ds-canvas); outline: none; }
.choice-card--disabled,
.choice-card:disabled {
  cursor: not-allowed; opacity: 0.72; background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline);
}
.choice-card--disabled:hover,
.choice-card:disabled:hover,
.choice-card--disabled:focus-visible,
.choice-card:disabled:focus-visible {
  border-color: var(--ds-hairline); background: var(--ds-canvas-soft);
}
.choice-card__icon { flex: 0 0 28px; font-size: 22px; color: var(--ds-primary-deep); text-align: center; }
.choice-card__copy { display: grid; gap: 4px; flex: 1; }
.choice-card__copy strong { font-size: 14px; }
.choice-card__copy small { color: var(--ds-ink-mute); font-size: 12px; line-height: 1.45; }
.choice-card__blocked { color: var(--ds-danger, #b42318); font-weight: 600; }
.choice-card__arrow { color: var(--ds-ink-mute); font-size: 24px; line-height: 1; }
.choice-footnote { margin: 14px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
