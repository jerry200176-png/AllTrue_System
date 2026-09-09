<!--
  CalendarLeaveModal（#740 Modals — 請假登記）
  Presentational：父層持有 leaveForm 與 submitLeave API 邏輯。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal" style="width: 420px;">
      <h3>📋 請假登記</h3>
      <p class="hint">請假不扣堂數、不需填寫評量表</p>
      <div class="form-group">
        <label>學生</label>
        <p style="font-weight: 600;">{{ studentName }}</p>
      </div>
      <div class="form-group">
        <label>科目</label>
        <p>{{ subjectLabel }}</p>
      </div>
      <div class="form-group">
        <label>請假日期</label>
        <input v-model="form.schedule_date" type="date" />
      </div>
      <div class="form-group">
        <label>原時段</label>
        <p>{{ originalSlotLabel }}</p>
      </div>
      <div class="impact-preview" role="note" aria-label="請假影響預覽">
        <div class="impact-preview__head">
          <span class="impact-preview__icon" aria-hidden="true">i</span>
          <div>
            <strong>{{ impactPreview?.title || '請假送出前影響預覽' }}</strong>
            <p>{{ impactPreview?.summary || '系統正在計算這次請假的影響。' }}</p>
          </div>
        </div>
        <ul class="impact-preview__list">
          <li v-for="item in impactItems" :key="item">{{ item }}</li>
        </ul>
        <label class="impact-confirm">
          <input v-model="impactAccepted" type="checkbox" />
          <span>我已確認上述影響，確定要送出這次請假</span>
        </label>
      </div>
      <p v-if="error" class="submit-error" role="alert">請假登記失敗：{{ error }}</p>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="!canSubmit || submitting" @click="$emit('submit')">{{ submitting ? '送出中…' : '確認請假' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import './calendarModalRwd.css';

const props = defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  studentName: { type: String, default: '—' },
  subjectLabel: { type: String, default: '—' },
  originalSlotLabel: { type: String, default: '—' },
  impactPreview: { type: Object, default: null },
  error: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
});
defineEmits(['close', 'submit']);
const impactAccepted = ref(false);
const impactItems = computed(() => {
  const items = props.impactPreview?.items;
  return Array.isArray(items) && items.length
    ? items
    : ['本堂會標記為請假，不扣堂數', '該堂不需要填寫學習評量'];
});
const canSubmit = computed(() => Boolean(props.form?.schedule_date) && impactAccepted.value);
watch(() => [props.show, props.form?.schedule_date], () => { impactAccepted.value = false; });
</script>

<style scoped>
.impact-preview { margin: 12px 0 4px; padding: 12px 14px; border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft); }
.impact-preview__head { display: flex; gap: 10px; align-items: flex-start; }
.impact-preview__head strong { display: block; font-size: 14px; margin-bottom: 2px; }
.impact-preview__head p { margin: 0; font-size: 12px; line-height: 1.5; }
.impact-preview__icon { width: 22px; height: 22px; display: inline-grid; place-items: center; flex: 0 0 auto; border-radius: 999px; color: var(--ds-canvas); background: var(--ds-ink-mute); font-weight: 800; }
.impact-preview__list { margin: 10px 0; padding-left: 20px; font-size: 13px; line-height: 1.6; }
.impact-confirm { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; cursor: pointer; }
.impact-confirm input { margin-top: 3px; }
.submit-error { color: var(--ds-danger); font-size: 13px; margin: 10px 0 0; }
</style>
