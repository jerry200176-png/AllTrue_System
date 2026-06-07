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
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit')">確認請假</button>
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
});
defineEmits(['close', 'submit']);
</script>
