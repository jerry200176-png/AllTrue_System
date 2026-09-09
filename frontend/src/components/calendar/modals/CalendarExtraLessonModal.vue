<!--
  CalendarExtraLessonModal（#740 Modals — 加課）
  Presentational：父層持有 extraForm 與 submitExtraLesson API 邏輯。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal" style="width: 480px;">
      <h3>＋ 加課</h3>
      <p class="hint" v-if="isMonthly">月結制加課需額外繳費，老師需上傳評量表</p>
      <p class="hint" v-else>堂數制加課會提早用完堂數（不額外收費），老師需上傳評量表</p>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <div class="form-group">
          <label>學生</label>
          <SearchableSelect
            v-model="form.student_id"
            :options="studentOptions"
            placeholder="輸入學生姓名搜尋..."
          />
        </div>
        <div class="form-group">
          <label>科目</label>
          <select v-model="form.subject">
            <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>老師</label>
          <select v-model="form.teacher_id">
            <option value="">請選擇</option>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>類型</label>
          <select v-model="form.class_type">
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
            <option value="trial">試聽</option>
          </select>
        </div>
        <div class="form-group">
          <label>日期</label>
          <input v-model="form.schedule_date" type="date" @change="$emit('check')" />
        </div>
        <div class="form-group">
          <label>時長</label>
          <select v-model.number="form.duration_hours" @change="$emit('duration-change')">
            <option :value="1">1 小時</option>
            <option :value="1.5">1.5 小時</option>
            <option :value="2">2 小時</option>
            <option :value="2.5">2.5 小時</option>
            <option :value="3">3 小時</option>
          </select>
        </div>
        <div class="form-group">
          <label>開始時間</label>
          <input
            v-model="form.start_time"
            type="time"
            step="1800"
            @change="$emit('start-time-change')"
          />
          <p class="hint" style="margin-top: 4px;">僅可選整點或半點</p>
        </div>
        <div class="form-group">
          <label>預計結束時間</label>
          <p class="computed-time">{{ newEndTime }}</p>
        </div>
      </div>
      <div v-if="checking" class="check-status" role="status">正在檢查加課時段…</div>
      <div v-else-if="checkError" class="check-status check-status--error" role="alert">
        {{ checkError }}
        <button type="button" class="ghost check-retry" @click="$emit('check')">重新檢查</button>
      </div>
      <div v-else-if="check && !check.can_add" class="check-status check-status--error" role="alert">
        {{ check.message || '此時段無法加課。' }}
        <button type="button" class="ghost check-retry" @click="$emit('check')">重新檢查</button>
      </div>
      <div v-else-if="check?.is_ended" class="check-status check-status--warning">
        <label>
          <input v-model="form.auto_approve" type="checkbox" />
          此時段已結束；我確認要直接補登、扣堂並自動核准評量
        </label>
      </div>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="!canSubmit" @click="handleSubmit">{{ checking ? '檢查中…' : '確認加課' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import './calendarModalRwd.css';
import SearchableSelect from '../../SearchableSelect.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  studentOptions: { type: Array, default: () => [] },
  subjectOptions: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  newEndTime: { type: String, default: '--:--' },
  isMonthly: { type: Boolean, default: false },
  check: { type: Object, default: null },
  checking: { type: Boolean, default: false },
  checkError: { type: String, default: '' },
});
const emit = defineEmits(['close', 'submit', 'duration-change', 'start-time-change', 'check']);
const canSubmit = computed(() => !props.checking && Boolean(props.check?.can_add));
function handleSubmit() {
  if (props.check?.is_ended && props.form?.auto_approve) {
    const ok = window.confirm(
      `${props.form.schedule_date} ${props.form.start_time} 已經過去，送出後會直接標記為已上課並自動核准評量。確定要補登嗎？`,
    );
    if (!ok) return;
  }
  emit('submit');
}
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
.check-status { margin: 14px 0 0; padding: 10px 12px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 13px; line-height: 1.5; }
.check-status--error { color: var(--ds-danger); border-color: var(--ds-danger); }
.check-status--warning { color: var(--ds-warning); border-color: var(--ds-warning); }
.check-retry { margin-left: 8px; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
