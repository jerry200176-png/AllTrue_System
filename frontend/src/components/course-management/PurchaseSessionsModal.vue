<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 420px;">
      <h3 class="modal-title">加購堂數</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
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
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit')">確認加購</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({ show: Boolean, form: Object });
defineEmits(['close', 'submit']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
</style>
