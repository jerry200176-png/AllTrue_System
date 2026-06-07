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
          <input v-model="form.schedule_date" type="date" />
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
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit')">確認加課</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import './calendarModalRwd.css';
import SearchableSelect from '../../SearchableSelect.vue';

defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  studentOptions: { type: Array, default: () => [] },
  subjectOptions: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  newEndTime: { type: String, default: '--:--' },
  isMonthly: { type: Boolean, default: false },
});
defineEmits(['close', 'submit', 'duration-change', 'start-time-change']);
</script>

<style scoped>
.computed-time {
  margin: 0;
  padding: 10px 12px;
  background: var(--bg-muted, #f5f5f5);
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  line-height: 1.4;
  color: var(--text);
}
</style>
