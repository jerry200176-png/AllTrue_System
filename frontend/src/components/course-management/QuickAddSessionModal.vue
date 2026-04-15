<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 440px;">
      <h3 class="modal-title">加課／補登（不增加總堂數）</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
      </p>
      <p class="modal-hint">堂數制課程若堂次已排滿，系統會將最後一筆可調整的未來排課移至您選的日期，總堂數不變。</p>
      <div class="form-group">
        <label>本堂日期</label>
        <input v-model="form.session_date" type="date" @change="$emit('check')" />
      </div>
      <div class="form-group">
        <label>開始時間</label>
        <select v-model="form.start_time" @change="$emit('check')">
          <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div class="form-group">
        <label>時長（分鐘）</label>
        <input v-model.number="form.duration_minutes" type="number" min="30" step="30" />
      </div>
      <div class="form-group">
        <label>備註（選填）</label>
        <input v-model.trim="form.note" type="text" placeholder="例如：臨時加課" />
      </div>
      <div class="form-group">
        <label class="hint">
          <input v-model="form.auto_approve" type="checkbox" />
          若該堂已下課，直接補登並扣堂
        </label>
      </div>

      <div v-if="conflict && conflict.conflict_type !== 'none'" class="conflict-banner">
        <p class="conflict-msg">{{ conflict.message }}</p>
        <ul v-if="conflict.suggested_actions && conflict.suggested_actions.length" class="conflict-actions">
          <li v-for="action in conflict.suggested_actions" :key="action">{{ action }}</li>
        </ul>
      </div>

      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button
          class="primary"
          :disabled="submitDisabled"
          @click="$emit('submit')"
        >
          {{ checking ? '檢查中…' : '確認送出' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  form: Object,
  timeOptions: Array,
  conflict: { type: Object, default: null },
  checking: { type: Boolean, default: false },
});
defineEmits(['close', 'submit', 'check']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const submitDisabled = computed(() =>
  props.checking || (props.conflict && props.conflict.conflict_type !== 'none')
);
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 4px; line-height: 1.6; }
.modal-hint { color: var(--text-light); font-size: 12px; margin-bottom: 16px; line-height: 1.5; opacity: 0.8; }
.conflict-banner {
  background: #fff3e0; border: 1px solid #ffb74d; border-radius: 8px;
  padding: 10px 14px; margin: 12px 0;
}
.conflict-msg { color: #e65100; font-size: 13px; font-weight: 600; margin: 0 0 6px; line-height: 1.5; }
.conflict-actions { margin: 0; padding-left: 18px; color: #bf360c; font-size: 12px; line-height: 1.6; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
