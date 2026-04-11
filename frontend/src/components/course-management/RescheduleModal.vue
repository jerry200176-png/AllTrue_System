<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 420px;">
      <h3 class="modal-title">調課</h3>
      <p class="modal-desc">將原本的課程改到新的日期時間</p>
      <div v-if="sessionOptions.length === 0" class="form-group">
        <p class="hint">此課程無可調課堂次（請確認開課日與排課設定）。</p>
      </div>
      <template v-else>
        <div class="form-group">
          <label>選擇要調動的堂次</label>
          <select v-model="form.original_date">
            <option value="">請選擇</option>
            <option v-for="opt in sessionOptions" :key="opt.date" :value="opt.date">
              第{{ opt.index }}堂 {{ opt.date }} {{ dayLabel(dayOfWeekFromDate(opt.date)) }}
            </option>
          </select>
        </div>
        <template v-if="form.original_date">
          <div class="form-group">
            <label>學生</label>
            <p style="font-weight: 600;">{{ form.student_name }}</p>
          </div>
          <div class="form-group">
            <label>科目</label>
            <p>{{ subjectLabel }}</p>
          </div>
          <div class="form-group">
            <label>原時段</label>
            <p>{{ form.original_date }} {{ form.original_start }}~{{ form.original_end }}</p>
          </div>
          <hr style="border: none; border-top: 1px solid var(--border); margin: 12px 0;" />
          <div style="margin-bottom: 12px;">
            <button class="small ghost btn-makeup-query" @click="$emit('query-makeup')" :disabled="makeupLoading">
              {{ makeupLoading ? '查詢中…' : '查詢可補課時段' }}
            </button>
          </div>
          <div class="form-group">
            <label>新日期</label>
            <input v-model="form.new_date" type="date" />
          </div>
          <div class="form-group">
            <label>新開始時間</label>
            <select v-model="form.new_start">
              <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>預計新結束時間</label>
            <p class="computed-end-time">{{ computedEndTime || '—' }}</p>
          </div>
        </template>
        <div class="actions">
          <button class="ghost" @click="$emit('close')">取消</button>
          <button class="primary" @click="$emit('submit')" :disabled="!form.new_date">確認調課</button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  form: Object,
  sessionOptions: Array,
  timeOptions: Array,
  makeupLoading: Boolean,
  dayLabel: Function,
  dayOfWeekFromDate: Function,
  computeEndTime: Function,
});
defineEmits(['close', 'submit', 'query-makeup']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const computedEndTime = computed(() => props.computeEndTime?.(props.form?.new_start, props.form?.duration_hours) || '');
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }
.btn-makeup-query {
  width: 100%; padding: 8px 12px !important; font-size: 13px !important; font-weight: 600;
  border: 1px dashed var(--primary) !important; color: var(--primary) !important;
  border-radius: 8px; transition: var(--transition);
}
.btn-makeup-query:hover:not(:disabled) { background: var(--primary-bg) !important; }
.btn-makeup-query:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
