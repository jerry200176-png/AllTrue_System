<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 440px;">
      <h3 class="modal-title">加課／補登（不增加總堂數）</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
      </p>
      <div class="form-group">
        <label>上課日期</label>
        <input v-model="form.session_date" type="date" />
      </div>
      <div class="form-group">
        <label>開始時間</label>
        <select v-model="form.start_time">
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
      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit')">確認送出</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({ show: Boolean, form: Object, timeOptions: Array });
defineEmits(['close', 'submit']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
</style>
