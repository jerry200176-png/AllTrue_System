<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal">
      <h3 class="modal-title">月結續約</h3>
      <p class="modal-desc">
        {{ form.student_name }} — {{ subjectLabel }}
      </p>

      <div class="info-row">
        <span class="info-label">結算日</span>
        <span class="info-value">{{ form.settlement_day ? '每月 ' + form.settlement_day + ' 日' : '未設定' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">每月堂數</span>
        <span class="info-value">{{ form.monthly_sessions ?? '未設定' }} 堂</span>
      </div>
      <div class="info-row">
        <span class="info-label">目前到期日</span>
        <span class="info-value">{{ form.current_end_date || '無到期日' }}</span>
      </div>

      <hr class="divider" />

      <div class="form-group">
        <label>續約方式</label>
        <div class="mode-toggle">
          <button :class="['mode-btn', { active: mode === 'months' }]" @click="mode = 'months'">延長月數</button>
          <button :class="['mode-btn', { active: mode === 'date' }]" @click="mode = 'date'">指定到期日</button>
        </div>
      </div>

      <div v-if="mode === 'months'" class="form-group">
        <label>延長月數</label>
        <input v-model.number="form.months" type="number" min="1" max="24" step="1" placeholder="1" />
        <span class="hint">新到期日：{{ computedEndDate }}</span>
      </div>

      <div v-if="mode === 'date'" class="form-group">
        <label>新到期日</label>
        <input v-model="form.end_date" type="date" :min="minDate" />
      </div>

      <div class="actions">
        <button class="ghost" @click="$emit('close')">取消</button>
        <button class="primary" @click="$emit('submit', finalEndDate)">確認續約</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({ show: Boolean, form: Object });
defineEmits(['close', 'submit']);

const mode = ref('months');

const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));

const minDate = computed(() => {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
});

const computedEndDate = computed(() => {
  const months = Number(props.form?.months ?? 1);
  if (!months || months <= 0) return '—';
  const base = props.form?.current_end_date
    ? new Date(props.form.current_end_date)
    : new Date();
  base.setMonth(base.getMonth() + months);
  return base.toISOString().slice(0, 10);
});

const finalEndDate = computed(() => {
  if (mode.value === 'date') return props.form?.end_date || '';
  return computedEndDate.value;
});
</script>

<style scoped>
.course-modal { width: 100%; max-width: 440px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 16px; }
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px; }
.info-label { color: var(--text-light); }
.info-value { font-weight: 600; color: var(--text); }
.divider { border: none; border-top: 1px solid var(--border, #e0e0e0); margin: 14px 0; }
.mode-toggle { display: flex; gap: 8px; margin-bottom: 4px; }
.mode-btn { flex: 1; padding: 6px 12px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--bg-card, #fff); cursor: pointer; font-size: 13px; color: var(--text-light); transition: all .15s; }
.mode-btn.active { border-color: var(--primary, #1976d2); background: var(--primary, #1976d2); color: #fff; font-weight: 600; }
.hint { display: block; margin-top: 6px; font-size: 12px; color: var(--text-light); }
</style>
