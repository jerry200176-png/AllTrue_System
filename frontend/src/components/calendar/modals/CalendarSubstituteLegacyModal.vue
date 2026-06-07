<!--
  CalendarSubstituteLegacyModal（#740 Modals — 舊版代課 select）
  Presentational：feature flag 關閉時使用；V2 走 SubstituteTeacherPickerModal。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal" style="width: 440px;">
      <h3>👤 換代課老師</h3>
      <p class="hint">僅替換此堂授課老師，不影響課程主檔與後續排課。</p>
      <div class="form-group">
        <label>學生</label>
        <p style="font-weight: 600;">{{ studentName }}</p>
      </div>
      <div class="form-group">
        <label>科目</label>
        <p>{{ subjectLabel }}</p>
      </div>
      <div class="form-group">
        <label>日期 / 時段</label>
        <p>{{ sessionSlotLabel }}</p>
      </div>
      <div class="form-group">
        <label>正班老師</label>
        <p>{{ form.original_teacher_name || '—' }}</p>
      </div>
      <div class="form-group">
        <label>代課老師 <span style="color: #ef4444;">*</span></label>
        <select v-model="form.substitute_teacher_id">
          <option value="">請選擇</option>
          <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
        </select>
      </div>
      <div class="form-group">
        <label>原因（選填）</label>
        <input v-model="form.reason" type="text" placeholder="例：正班老師請假" style="width: 100%;" />
      </div>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button
          class="primary"
          :disabled="submitting || !form.substitute_teacher_id"
          @click="$emit('submit')"
        >{{ submitting ? '處理中…' : '確認代課' }}</button>
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
  sessionSlotLabel: { type: String, default: '—' },
  teachers: { type: Array, default: () => [] },
  submitting: { type: Boolean, default: false },
});
defineEmits(['close', 'submit']);
</script>
