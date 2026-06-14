<!--
  CalendarRescheduleModal（#740 Modals — 調課）
  Presentational：父層持有 rescheduleForm 與 submitReschedule API 邏輯。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal" style="width: 420px;">
      <h3>🔄 調課</h3>
      <p class="hint">將原本的課程改到新的日期時間</p>
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
        <label>新日期</label>
        <input v-model="form.new_date" type="date" />
      </div>
      <div class="form-group">
        <label>新開始時間</label>
        <select v-model="form.new_start" @change="$emit('new-start-change')">
          <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div class="form-group">
        <label>預計新結束時間</label>
        <p class="computed-time">{{ newEndTime }}</p>
      </div>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit')">確認調課</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import './calendarModalRwd.css';

defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  studentName: { type: String, default: '—' },
  subjectLabel: { type: String, default: '—' },
  originalSlotLabel: { type: String, default: '—' },
  newEndTime: { type: String, default: '--:--' },
  timeOptions: { type: Array, default: () => [] },
});
defineEmits(['close', 'submit', 'new-start-change']);
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
</style>
