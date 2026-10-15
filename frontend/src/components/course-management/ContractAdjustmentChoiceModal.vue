<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal adjustment-choice-modal" role="dialog" aria-modal="true" aria-labelledby="contract-adjustment-title">
      <h3 id="contract-adjustment-title" class="modal-title">合約／堂次調整</h3>
      <p class="modal-desc">{{ studentName }}／{{ subjectLabel }}</p>
      <p class="choice-intro">先選你要處理的事情，系統會帶到正確流程。</p>

      <!-- Founder 2026-10-15 Q8=A (#4014): lessons are added through 續課, never by raising this contract. -->
      <div class="renew-hint" data-testid="contract-adjust-renew-hint">
        <p class="renew-hint__text"><strong>要加堂？</strong>這裡不能把堂數改多，請用「續課」：會另外建立一期新的堂數和帳單，原合約與已上課紀錄都不變。</p>
        <button type="button" class="renew-hint__action" data-testid="contract-adjust-renew" @click="onChoose('renew')">前往續課</button>
      </div>

      <div class="choice-list">
        <button
          type="button"
          class="choice-card"
          :class="{ 'choice-card--disabled': !billingEnabled }"
          :disabled="!billingEnabled"
          :title="billingDisabledReason || undefined"
          @click="onChoose('billing')"
        >
          <span class="choice-card__icon" aria-hidden="true">↺</span>
          <span class="choice-card__copy">
            <strong>未付款，堂數改少</strong>
            <small>例如原本 5 堂，最後只收 4 堂；已上課紀錄保留。</small>
            <small v-if="!billingEnabled" class="choice-card__blocked">{{ billingDisabledReason }}</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>

        <button type="button" class="choice-card choice-card--amendment" @click="onChoose('amendment')">
          <span class="choice-card__icon" aria-hidden="true">⊖</span>
          <span class="choice-card__copy">
            <strong>提前結束／調整合約總堂數</strong>
            <small>例如原本 8 堂、已完成 3 堂，調整為 3 堂；不需要目標課程，未來預排會取消。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>

        <button type="button" class="choice-card" @click="onChoose('transfer')">
          <span class="choice-card__icon" aria-hidden="true">↪</span>
          <span class="choice-card__copy">
            <strong>把已上課紀錄轉到另一份合約</strong>
            <small>搬移評量與點名紀錄；不改任何課程堂數或金額。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>
      </div>

      <p class="choice-footnote">提前結束會保留原合約、已上課紀錄與稽核軌跡；帳務不在此流程自動改動。不要用「編輯」直接改已發生的扣堂資料。</p>

      <div class="actions">
        <button type="button" class="ghost" @click="$emit('close')">取消</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';
import { REPORT_STATUS_LABELS, TUITION_STATUS_CONFIG } from '../../lib/courseMoneyState.js';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  subject: { type: String, default: '' },
  paymentStatus: { type: String, default: '' },
  billingAvailable: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'choose']);

const subjectLabel = computed(() => getSubjectLabel(props.subject));
const normalizedPaymentStatus = computed(() => String(props.paymentStatus || '').trim().toLowerCase());
const billingEnabled = computed(() => props.billingAvailable && normalizedPaymentStatus.value === 'unpaid');
const billingDisabledReason = computed(() => {
  if (billingEnabled.value) return '';
  if (!props.billingAvailable) return '此課程不適用未付款堂數更正；請依既有帳務中心指引處理。';
  if (normalizedPaymentStatus.value === 'paid') return `${TUITION_STATUS_CONFIG.paid.label}的課不可用此流程；請到帳務中心處理。`;
  if (normalizedPaymentStatus.value === 'pending_report') return `尚有「${REPORT_STATUS_LABELS.pending}」的繳費回報，請先到帳務中心處理。`;
  if (normalizedPaymentStatus.value === 'partial') return '已有部分收款，不可用此流程改堂數；請先到帳務中心處理。';
  return '僅未付款課程可用此流程；請依既有帳務中心指引處理。';
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
.choice-intro { margin: 0 0 14px; color: var(--ds-ink-mute); font-size: 13px; }
.renew-hint {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0 0 12px; padding: 10px 12px;
  border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-canvas);
}
.renew-hint__text { flex: 1 1 240px; margin: 0; color: var(--text); font-size: var(--ds-font-size-sm); line-height: 1.5; }
.renew-hint__action {
  border: 1px solid var(--ds-primary); border-radius: var(--ds-radius-md); background: var(--ds-canvas-soft);
  color: var(--ds-primary-text); font-weight: 700; padding: 6px 12px; cursor: pointer;
}
.renew-hint__action:hover, .renew-hint__action:focus-visible { background: var(--ds-canvas); outline: 2px solid var(--ds-primary); outline-offset: 1px; }
.choice-list { display: grid; gap: 10px; }
.choice-card {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 13px 12px;
  border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft);
  color: var(--text); text-align: left; cursor: pointer;
}
.choice-card:hover, .choice-card:focus-visible { border-color: var(--ds-primary); background: var(--ds-canvas); outline: none; }
.choice-card--disabled, .choice-card:disabled { cursor: not-allowed; opacity: 0.72; }
.choice-card--disabled:hover, .choice-card:disabled:hover { border-color: var(--ds-hairline); background: var(--ds-canvas-soft); }
.choice-card__icon { flex: 0 0 28px; font-size: 22px; color: var(--ds-primary-text); text-align: center; }
.choice-card__copy { display: grid; gap: 4px; flex: 1; }
.choice-card__copy strong { font-size: 14px; }
.choice-card__copy small { color: var(--ds-ink-mute); font-size: 12px; line-height: 1.45; }
.choice-card__blocked { color: var(--ds-danger); font-weight: 600; }
.choice-card__arrow { color: var(--ds-ink-mute); font-size: 24px; line-height: 1; }
.choice-footnote { margin: 14px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
