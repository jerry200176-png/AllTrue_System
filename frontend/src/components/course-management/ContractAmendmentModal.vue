<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && !loadingPreview && $emit('close')">
    <div class="modal course-modal amendment-modal" role="dialog" aria-modal="true" aria-labelledby="contract-amendment-title">
      <h3 id="contract-amendment-title" class="modal-title">提前結束／調整合約總堂數</h3>
      <p class="modal-desc">
        {{ course?.student_name || '此學生' }}／{{ subjectLabel }}／來源課程
      </p>
      <div class="amendment-banner">
        <strong>這不是堂次轉移</strong>
        <span>不需要目標課程；原合約與已上課紀錄保留，未來預排只會被取消。</span>
      </div>

      <div class="contract-summary">
        <span>目前總堂數 <strong>{{ currentCount }}</strong></span>
        <span>目前剩餘 <strong>{{ currentRemaining }}</strong></span>
        <span>已完成 <strong>{{ preview?.completed_sessions ?? '—' }}</strong></span>
      </div>

      <label class="form-label" for="amendment-new-count">調整後總堂數</label>
      <div class="count-row">
        <input id="amendment-new-count" v-model.number="newSessionCount" type="number" min="1" :max="Math.max(1, currentCount - 1)" step="1" :disabled="submitting || loadingPreview" class="form-input" />
        <button class="secondary" type="button" :disabled="loadingPreview || submitting || !validCount" @click="$emit('preview', newSessionCount)">
          {{ loadingPreview ? '檢查中…' : '預覽影響' }}
        </button>
      </div>
      <p class="form-hint">新總堂數不可低於已完成堂數；送出前必須先完成預覽。</p>

      <div v-if="preview" class="amendment-preview" role="status">
        <div class="preview-line"><strong>{{ preview.original_session_count }} 堂</strong><span>→</span><strong>{{ preview.new_session_count }} 堂</strong></div>
        <div class="preview-grid">
          <span>剩餘堂數</span><strong>{{ preview.original_remaining_sessions }} → {{ preview.new_remaining_sessions }}</strong>
          <span>會取消的 ClassSession</span><strong>{{ preview.affected_future_scheduled_count }}</strong>
          <span>會取消的排程投影</span><strong>{{ preview.affected_future_schedules_count ?? 0 }}</strong>
        </div>
        <p v-if="preview.affected_future_scheduled?.length" class="preview-list">
          未來堂次：{{ preview.affected_future_scheduled.map((row) => `${row.date} ${row.start_time}-${row.end_time}`).join('、') }}
        </p>
        <p class="financial-note">帳務摘要：Invoice {{ preview.financial?.invoice_count ?? 0 }} 筆／Payment {{ preview.financial?.payment_count ?? 0 }} 筆／PaymentReport {{ preview.financial?.payment_report_count ?? 0 }} 筆。{{ preview.financial_note }}</p>
      </div>

      <p v-if="errorMessage" class="amendment-error" role="alert">{{ errorMessage }}</p>

      <label class="form-label" for="amendment-reason">調整原因（必填）</label>
      <textarea id="amendment-reason" v-model.trim="reason" class="form-input" rows="3" maxlength="255" :disabled="submitting" placeholder="例如：學生不再繼續上課，主任確認提前結束本期合約" />

      <div class="actions">
        <button class="ghost" :disabled="submitting || loadingPreview" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="submitting || loadingPreview || !preview || !reason || preview.new_session_count !== newSessionCount" @click="onSubmit">
          {{ submitting ? '處理中…' : '確認提前結束' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  course: { type: Object, default: null },
  preview: { type: Object, default: null },
  loadingPreview: Boolean,
  submitting: Boolean,
  errorMessage: { type: String, default: '' },
});
const emit = defineEmits(['close', 'preview', 'submit']);
const newSessionCount = ref(1);
const reason = ref('');
const subjectLabel = computed(() => getSubjectLabel(props.course?.subject_name || props.course?.subject || ''));
const currentCount = computed(() => Number(props.course?.sessions_purchased ?? props.course?.SessionCount ?? 0));
const currentRemaining = computed(() => Number(props.course?.remaining_sessions ?? props.course?.RemainingSessions ?? 0));
const validCount = computed(() => Number.isInteger(newSessionCount.value) && newSessionCount.value >= 1 && newSessionCount.value < currentCount.value);

watch(() => [props.show, props.course?.id ?? props.course?.ID], ([isShown]) => {
  if (isShown) {
    newSessionCount.value = Math.max(1, currentCount.value);
    reason.value = '';
  }
});

function onSubmit() {
  if (!props.preview || !validCount.value || !reason.value) return;
  emit('submit', { newSessionCount: newSessionCount.value, reason: reason.value });
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; } .modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.amendment-banner, .amendment-preview { padding: 10px 12px; border-radius: 10px; line-height: 1.5; font-size: 12px; }
.amendment-banner { display: grid; gap: 3px; margin-bottom: 14px; background: var(--ds-primary-wash); color: var(--ds-primary-deep); } .contract-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; color: var(--ds-ink-mute); font-size: 12px; } .contract-summary strong { display: block; color: var(--text); font-size: 18px; }
.form-label { display: block; font-size: 13px; font-weight: 700; color: var(--text); margin: 10px 0 6px; } .count-row { display: flex; gap: 8px; } .form-input { width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 14px; } .count-row .form-input { max-width: 150px; }
.secondary { border: 1px solid var(--ds-primary); border-radius: 8px; background: var(--ds-canvas); color: var(--ds-primary-deep); padding: 8px 12px; cursor: pointer; }
.form-hint, .preview-list, .financial-note { color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; } .amendment-preview { margin: 14px 0; background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); } .preview-line { display: flex; justify-content: center; gap: 12px; font-size: 18px; margin-bottom: 10px; } .preview-grid { display: grid; grid-template-columns: 1fr auto; gap: 8px; margin-bottom: 8px; } .preview-grid strong { text-align: right; }
.amendment-error { margin: 10px 0; padding: 8px 10px; border-radius: 8px; background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 13px; } .financial-note { margin: 8px 0 0; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
