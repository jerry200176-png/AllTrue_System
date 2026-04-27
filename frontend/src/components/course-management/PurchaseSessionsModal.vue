<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal" style="max-width: 420px;">
      <h3 class="modal-title">加購堂數</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
      </p>
      <p class="modal-note">
        加購會建立一筆新的未繳課程批次，並在新批次詳情顯示上課日期；原課程堂數不會被改寫。
      </p>
      <div class="form-group">
        <label>加購堂數</label>
        <input v-model.number="form.sessions" type="number" min="1" step="1" />
      </div>
      <div class="form-group">
        <label>新批次開始日期</label>
        <input v-model="form.start_date" type="date" />
      </div>
      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="submitting" @click="$emit('submit')">
          {{ submitting ? '建立中…' : '確認加購' }}
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
  submitting: { type: Boolean, default: false },
});
defineEmits(['close', 'submit']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.modal-note { background: #fff8e1; border: 1px solid #ffe0a3; border-radius: 8px; color: #7a4b00; font-size: 13px; line-height: 1.6; margin: -8px 0 16px; padding: 10px 12px; }
</style>
