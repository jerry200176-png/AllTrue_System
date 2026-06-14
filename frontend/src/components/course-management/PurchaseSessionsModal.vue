<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal premium-renewal-modal" style="max-width: 420px;">
      <div class="premium-modal-header">
        <span class="premium-modal-icon">＋</span>
        <div>
          <p class="premium-kicker">Renewal Batch</p>
          <h3 class="modal-title">{{ isPackageMode ? '調整共用方案堂數' : '加購堂數' }}</h3>
          <p class="modal-desc">{{ form.student_name }} - {{ subjectLabel }}</p>
        </div>
      </div>

      <div v-if="isPackageMode" class="package-op-toggle" role="tablist">
        <button
          type="button"
          role="tab"
          :class="['package-op-btn', { active: form.package_op !== 'set' }]"
          :disabled="submitting"
          @click="selectOp('add')"
        >加購堂數</button>
        <button
          type="button"
          role="tab"
          :class="['package-op-btn', { active: form.package_op === 'set' }]"
          :disabled="submitting"
          @click="selectOp('set')"
        >設定總堂數</button>
      </div>

      <p class="modal-note premium-note">
        {{ packageNote }}
      </p>
      <div class="form-group">
        <label>{{ sessionsLabel }}</label>
        <input v-model.number="form.sessions" type="number" min="1" step="1" />
        <span v-if="isPackageMode" class="field-hint">目前共用總堂數 {{ currentTotal }} 堂，已使用 {{ usedSessions }} 堂。</span>
      </div>
      <div v-if="!isPackageMode" class="form-group">
        <label>新批次開始日期</label>
        <input v-model="form.start_date" type="date" />
      </div>
      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button class="primary" :disabled="submitting" @click="$emit('submit')">
          {{ submitting ? '處理中…' : submitLabel }}
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
  isPackageMode: { type: Boolean, default: false },
  currentTotal: { type: Number, default: 0 },
  usedSessions: { type: Number, default: 0 },
});
defineEmits(['close', 'submit']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const isSetMode = computed(() => props.isPackageMode && props.form?.package_op === 'set');
const sessionsLabel = computed(() => (isSetMode.value ? '設定後的總堂數' : '加購堂數'));
const submitLabel = computed(() => (isSetMode.value ? '確認設定' : '確認加購'));
function selectOp(op) {
  if (!props.form || props.form.package_op === op) return;
  props.form.package_op = op;
  // Pre-fill the shared input with a sensible value for the chosen operation:
  // 'set' starts from the current total (an absolute), 'add' from a small delta.
  props.form.sessions = op === 'set' ? (Number(props.currentTotal) || 0) : 8;
}
const packageNote = computed(() => {
  if (!props.isPackageMode) {
    return '加購會建立一筆新的未繳課程批次，並在新批次詳情顯示上課日期；原課程堂數不會被改寫。';
  }
  if (isSetMode.value) {
    return '此課程屬於多科共用方案。設定總堂數會把整個方案的共用總堂數改為你輸入的數字（所有方案科目共用同一個堂數池）；不可低於已使用堂數。';
  }
  return '此課程屬於多科共用方案，加購會增加整個方案的共用總堂數，所有方案科目一起沿用同一個堂數池。';
});
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
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
  background: var(--ds-brand-gradient, linear-gradient(90deg, var(--ds-primary-soft), var(--ds-primary)));
}
.premium-modal-header { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
.premium-modal-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 16px;
  color: var(--ds-primary-deep, var(--ds-primary));
  background: var(--ds-primary-wash, var(--ds-primary-wash));
  border: 1px solid rgba(245, 124, 0, 0.3);
  box-shadow: 0 10px 26px rgba(245,124,0,0.18);
  font-weight: 900;
}
.premium-kicker { margin: 0 0 2px; color: var(--ds-brand-orange, var(--ds-primary)); font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
.package-op-toggle { display: flex; gap: 6px; margin: 0 0 14px; padding: 4px; background: var(--ds-canvas-soft, var(--ds-canvas-soft)); border: 1px solid var(--ds-hairline, var(--ds-hairline)); border-radius: 12px; }
.package-op-btn { flex: 1; padding: 8px 10px; border: 0; border-radius: 9px; background: transparent; color: var(--text-light, var(--ds-ink-mute)); font-size: 13px; font-weight: 700; cursor: pointer; transition: var(--transition, all 0.2s ease); }
.package-op-btn:hover:not(.active):not(:disabled) { color: var(--text, var(--ds-ink)); }
.package-op-btn.active { background: var(--card-bg, var(--ds-canvas)); color: var(--primary, var(--ds-ink-mute)); box-shadow: var(--ds-shadow-1, 0 1px 3px rgba(0,55,112,0.08)); }
.package-op-btn:disabled { cursor: not-allowed; opacity: 0.6; }
.field-hint { display: block; margin-top: 6px; color: var(--text-light, var(--ds-ink-mute)); font-size: 12px; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0; line-height: 1.6; }
.modal-note { background: var(--ds-primary-wash); border: 1px solid var(--ds-warning); border-radius: 8px; color: var(--ds-warning); font-size: 13px; line-height: 1.6; margin: -8px 0 16px; padding: 10px 12px; }
.premium-note { border-radius: 14px; border-color: var(--ds-warning-wash); background: linear-gradient(135deg, var(--ds-warning-wash), var(--ds-warning-wash)); }
</style>
