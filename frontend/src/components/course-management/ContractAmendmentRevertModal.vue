<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal" role="dialog" aria-modal="true" aria-labelledby="contract-revert-title">
      <h3 id="contract-revert-title" class="modal-title">撤銷調整</h3>
      <p class="modal-desc">{{ course?.student_name || '此學生' }}／{{ subjectLabel }}</p>

      <p v-if="loadingPreview" class="form-hint">檢查中…</p>
      <div v-if="preview" class="revert-preview" role="status">
        <div class="preview-line"><strong>{{ preview.current_session_count }} 堂</strong><span>→</span><strong>{{ preview.restored_session_count }} 堂</strong></div>
        <p>剩餘堂數 {{ preview.current_remaining_sessions }} → {{ preview.restored_remaining_sessions }}；將恢復 {{ preview.restorable_sessions_count }} 筆被取消的堂次、{{ preview.restorable_schedules_count }} 筆排程。</p>
        <p v-if="preview.unscheduled_remaining_sessions > 0" class="revert-warning">撤銷後有 {{ preview.unscheduled_remaining_sessions }} 堂尚未排課，系統不會自動排課，請自行安排。</p>
        <p class="form-hint">帳務資料不會變更。</p>
      </div>
      <p v-if="errorMessage" class="revert-error" role="alert">{{ errorMessage }}</p>

      <label class="form-label" for="revert-reason">撤銷原因（必填）</label>
      <textarea id="revert-reason" v-model.trim="reason" class="form-input" rows="3" maxlength="255" :disabled="submitting" />

      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="submitting || loadingPreview || !preview || !reason" @click="$emit('submit', reason)">
          {{ submitting ? '處理中…' : '確認撤銷調整' }}
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
defineEmits(['close', 'submit']);
const reason = ref('');
const subjectLabel = computed(() => getSubjectLabel(props.course?.subject_name || props.course?.subject || ''));
watch(() => props.show, (isShown) => { if (isShown) reason.value = ''; });
</script>

<style scoped>
.course-modal { width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc, .form-hint { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.revert-preview { padding: 10px 12px; border-radius: 10px; background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); font-size: 13px; line-height: 1.5; }
.preview-line { display: flex; justify-content: center; gap: 12px; font-size: 18px; margin-bottom: 8px; }
.revert-warning, .revert-error { margin: 10px 0; padding: 8px 10px; border-radius: 8px; font-size: 13px; }
.revert-warning { background: var(--ds-warning-wash); color: var(--ds-warning-ink); }
.revert-error { background: var(--ds-danger-wash); color: var(--ds-danger); }
.form-label { display: block; font-size: 13px; font-weight: 700; color: var(--text); margin: 10px 0 6px; }
.form-input { width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 14px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
