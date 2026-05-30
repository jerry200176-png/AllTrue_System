<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal premium-renewal-modal">
      <div class="premium-modal-header">
        <span class="premium-modal-icon">↻</span>
        <div>
          <p class="premium-kicker">Monthly Renewal</p>
          <h3 class="modal-title">月結續約</h3>
          <p class="modal-desc">{{ form.student_name }} - {{ subjectLabel }}</p>
        </div>
      </div>

      <div class="renewal-hud">
        <div class="info-row">
          <span class="info-label">結算日</span>
          <span class="info-value">{{ form.settlement_day ? '每月 ' + form.settlement_day + ' 日' : '未設定' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">每月堂數</span>
          <span class="info-value">{{ form.monthly_sessions ?? '未設定' }} 堂</span>
        </div>
        <div class="info-row">
          <span class="info-label">舊期到期日</span>
          <span class="info-value">{{ form.current_end_date || '無到期日' }}</span>
        </div>
      </div>
      <p class="period-hint">確認後會建立「新一期課程」，舊課程會結算成歷史，帳單不會再混在同一筆課程。</p>

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
        <span class="hint">新一期到期日：{{ computedEndDate }}</span>
      </div>

      <div v-if="mode === 'date'" class="form-group">
        <label>新到期日</label>
        <input v-model="form.end_date" type="date" :min="minDate" />
      </div>

      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="submitting" @click="$emit('submit', finalEndDate)">
          {{ submitting ? '建立中…' : '建立新一期' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  form: Object,
  submitting: { type: Boolean, default: false },
});
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
.premium-renewal-modal {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(245, 124, 0, 0.2);
  box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22), inset 0 1px 0 rgba(255,255,255,0.7);
  background:
    radial-gradient(circle at top right, rgba(245,124,0,0.14), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96));
}
.premium-renewal-modal::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 4px;
  background: var(--ds-brand-gradient, linear-gradient(90deg, #ffb300, #ef6c00));
}
.premium-modal-header { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
.premium-modal-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 16px;
  color: var(--ds-primary-deep, #e65100);
  background: var(--ds-primary-wash, #fff8e1);
  border: 1px solid rgba(245, 124, 0, 0.3);
  box-shadow: 0 10px 26px rgba(245,124,0,0.18);
  font-weight: 900;
}
.premium-kicker { margin: 0 0 2px; color: var(--ds-brand-orange, #ef6c00); font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0; }
.renewal-hud { display: grid; gap: 8px; margin-bottom: 14px; }
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 11px; font-size: 13px; border: 1px solid #e2e8f0; border-radius: 12px; background: rgba(255,255,255,0.68); }
.info-label { color: var(--text-light); }
.info-value { font-weight: 800; color: var(--text); }
.divider { border: none; border-top: 1px solid var(--border, #e0e0e0); margin: 14px 0; }
.mode-toggle { display: flex; gap: 8px; margin-bottom: 4px; }
.mode-btn { flex: 1; padding: 6px 12px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--bg-card, #fff); cursor: pointer; font-size: 13px; color: var(--text-light); transition: all .15s; }
.mode-btn.active { border-color: var(--primary, #1976d2); background: var(--primary, #1976d2); color: #fff; font-weight: 600; }
.hint { display: block; margin-top: 6px; font-size: 12px; color: var(--text-light); }
.period-hint {
  margin: 10px 0 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  line-height: 1.5;
}
</style>
