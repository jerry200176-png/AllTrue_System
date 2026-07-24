<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal" style="max-width: 420px;" role="dialog" aria-modal="true" aria-labelledby="cm-reschedule-title">
      <h3 id="cm-reschedule-title" class="modal-title">調課</h3>
      <p class="modal-desc">{{ rescheduleDesc }}</p>
      <div v-if="sessionOptions.length === 0" class="form-group">
        <p class="hint">此課程無可調課堂次（請確認開課日與排課設定）。</p>
      </div>
      <template v-else>
        <div class="form-group">
          <label for="cm-reschedule-session">選擇要調動的堂次</label>
          <select id="cm-reschedule-session" v-model="form.original_date" :disabled="submitting">
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
            <button class="small ghost btn-makeup-query" type="button" :disabled="makeupLoading || submitting" @click="$emit('query-makeup')">
              {{ makeupLoading ? '查詢中…' : makeupQueryLabel }}
            </button>
          </div>
          <div class="form-group">
            <label for="cm-reschedule-date">新日期</label>
            <input id="cm-reschedule-date" v-model="form.new_date" type="date" :disabled="submitting" />
          </div>
          <div class="form-group">
            <label for="cm-reschedule-start">新開始時間</label>
            <select id="cm-reschedule-start" v-model="form.new_start" :disabled="submitting">
              <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>預計新結束時間</label>
            <p class="computed-end-time">{{ computedEndTime || '—' }}</p>
          </div>
        </template>
        <div v-if="error" class="dialog-error" role="alert">{{ error }}</div>
        <div class="actions">
          <button class="ghost" type="button" :disabled="submitting" @click="$emit('close')">取消</button>
          <button class="primary" type="button" :disabled="submitting || !form.new_date" @click="$emit('submit')">
            {{ submitting ? '調課中…' : '確認調課' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';
import { MAKEUP_SLOT_QUERY_LABEL, RESCHEDULE_ACTION_DESC } from '../../lib/scheduleDisplay';

const props = defineProps({
  show: Boolean,
  form: Object,
  sessionOptions: Array,
  timeOptions: Array,
  makeupLoading: Boolean,
  dayLabel: Function,
  dayOfWeekFromDate: Function,
  computeEndTime: Function,
  error: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
});
defineEmits(['close', 'submit', 'query-makeup']);
const makeupQueryLabel = MAKEUP_SLOT_QUERY_LABEL;
const rescheduleDesc = RESCHEDULE_ACTION_DESC;
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
.dialog-error {
  margin: 0 0 12px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--ds-danger, #c62828) 35%, transparent);
  background: color-mix(in srgb, var(--ds-danger, #c62828) 10%, var(--ds-canvas, #fff));
  color: var(--ds-danger, #c62828);
  font-size: 13px;
  line-height: 1.45;
}
</style>
