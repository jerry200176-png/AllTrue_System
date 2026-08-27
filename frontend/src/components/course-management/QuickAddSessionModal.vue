<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 440px;">
      <h3 class="modal-title">補課／補登（總堂數不變）</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
      </p>
      <p class="modal-hint">堂數制課程若堂次已排滿，系統會將最後一筆可調整的未來排課移至您選的日期，購買總堂數不會增加。</p>
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
        <input v-model.trim="form.note" type="text" placeholder="例如：補課原因或家長備註" />
      </div>
      <div class="form-group">
        <label class="hint">
          <input v-model="form.auto_approve" type="checkbox" />
          若該堂已下課，直接補登並扣堂，評量同時自動核准
        </label>
      </div>

      <div v-if="conflict && conflict.conflict_type !== 'none'" class="conflict-banner">
        <p class="conflict-msg">{{ conflict.message }}</p>
        <ul v-if="conflict.suggested_actions && conflict.suggested_actions.length" class="conflict-actions">
          <li v-for="action in conflict.suggested_actions" :key="action">{{ action }}</li>
        </ul>
        <button type="button" class="ghost conflict-retry" @click="$emit('check')">重新檢查</button>
      </div>

      <div v-if="showAutoApproveWarning" class="conflict-banner auto-approve-banner">
        <p class="conflict-msg">
          此時段已經過去，送出後這堂會直接標記為「已上課」，評量也會自動核准，不會再進入審核清單。請確認日期／時間沒有選錯。
        </p>
      </div>

      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button
          class="primary"
          :disabled="submitDisabled"
          @click="handleSubmit"
        >
          {{ checking ? '檢查中…' : (showAutoApproveWarning ? '確認補登（已上課＋自動核准）' : '確認送出') }}
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
const emit = defineEmits(['close', 'submit', 'check']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const submitDisabled = computed(() =>
  props.checking || (props.conflict && props.conflict.conflict_type !== 'none')
);
const showAutoApproveWarning = computed(() => !!(props.conflict?.is_ended && props.form?.auto_approve));

function handleSubmit() {
  if (showAutoApproveWarning.value) {
    const ok = window.confirm(
      `${props.form.session_date} ${props.form.start_time} 已經過去，送出後會直接標記為已上課並自動核准評量。確定要補登嗎？`
    );
    if (!ok) return;
  }
  emit('submit');
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 4px; line-height: 1.6; }
.modal-hint { color: var(--text-light); font-size: 12px; margin-bottom: 16px; line-height: 1.5; opacity: 0.8; }
.conflict-banner {
  background: var(--ds-warning-wash); border: 1px solid var(--ds-warning); border-radius: 8px;
  padding: 10px 14px; margin: 12px 0;
}
.conflict-msg { color: var(--ds-primary); font-size: 13px; font-weight: 600; margin: 0 0 6px; line-height: 1.5; }
.conflict-actions { margin: 0; padding-left: 18px; color: var(--ds-danger); font-size: 12px; line-height: 1.6; }
.conflict-retry { margin-top: 8px; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
