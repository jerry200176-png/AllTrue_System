<!--
  CalendarRescheduleModal（#740 Modals — 調課）
  Presentational：父層持有 rescheduleForm 與 submitReschedule API 邏輯。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal" style="width: 420px;" role="dialog" aria-modal="true" aria-labelledby="cal-reschedule-title">
      <h3 id="cal-reschedule-title">調課</h3>
      <p class="hint">{{ rescheduleDesc }}</p>
      <div class="form-group">
        <label>學生</label>
        <p style="font-weight: 600;">{{ studentName }}</p>
      </div>
      <div class="form-group">
        <label>科目</label>
        <p>{{ subjectLabel }}</p>
      </div>
      <div class="form-group">
        <label>原時段</label>
        <p>{{ originalSlotLabel }}</p>
      </div>
      <hr style="border: none; border-top: 1px solid var(--ds-canvas-soft); margin: 12px 0;" />
      <div class="form-group">
        <label for="cal-reschedule-date">新日期</label>
        <input id="cal-reschedule-date" v-model="form.new_date" type="date" :disabled="submitting" />
      </div>
      <div class="form-group">
        <label for="cal-reschedule-start">新開始時間</label>
        <select id="cal-reschedule-start" v-model="form.new_start" :disabled="submitting" @change="$emit('new-start-change')">
          <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div class="form-group">
        <label>預計新結束時間</label>
        <p class="computed-time">{{ newEndTime }}</p>
      </div>
      <div v-if="error" class="dialog-error" role="alert">{{ error }}</div>
      <div class="actions">
        <button class="ghost" type="button" :disabled="submitting" @click="$emit('close')">取消</button>
        <button class="primary" type="button" :disabled="submitting || !form.new_date" @click="$emit('submit')">
          {{ submitting ? '調課中…' : '確認調課' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import './calendarModalRwd.css';
import { RESCHEDULE_ACTION_DESC } from '../../../lib/scheduleDisplay';

defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  studentName: { type: String, default: '—' },
  subjectLabel: { type: String, default: '—' },
  originalSlotLabel: { type: String, default: '—' },
  newEndTime: { type: String, default: '--:--' },
  timeOptions: { type: Array, default: () => [] },
  error: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
});
defineEmits(['close', 'submit', 'new-start-change']);
const rescheduleDesc = RESCHEDULE_ACTION_DESC;
</script>

<style scoped>
.computed-time {
  margin: 0;
  padding: 10px 12px;
  background: var(--bg-muted, var(--ds-canvas-soft));
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  line-height: 1.4;
  color: var(--text);
}
.dialog-error {
  margin: 0 0 12px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--ds-danger);
  background: var(--ds-canvas-soft);
  color: var(--ds-danger);
  font-size: 13px;
  line-height: 1.45;
}
</style>
