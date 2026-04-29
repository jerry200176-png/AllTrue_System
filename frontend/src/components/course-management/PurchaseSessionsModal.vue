<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal premium-renewal-modal" style="max-width: 420px;">
      <div class="premium-modal-header">
        <span class="premium-modal-icon">＋</span>
        <div>
          <p class="premium-kicker">Renewal Batch</p>
          <h3 class="modal-title">加購堂數</h3>
          <p class="modal-desc">{{ form.student_name }} - {{ subjectLabel }}</p>
        </div>
      </div>
      <p class="modal-note premium-note">
        {{ isPackageMode
          ? '此課程屬於多科共用方案，加購會增加整個方案的共用總堂數，所有方案科目一起沿用同一個堂數池。'
          : '加購會建立一筆新的未繳課程批次，並在新批次詳情顯示上課日期；原課程堂數不會被改寫。'
        }}
      </p>
      <div class="form-group">
        <label>加購堂數</label>
        <input v-model.number="form.sessions" type="number" min="1" step="1" />
      </div>
      <div v-if="!isPackageMode" class="form-group">
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
  isPackageMode: { type: Boolean, default: false },
});
defineEmits(['close', 'submit']);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.premium-renewal-modal {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(59, 130, 246, 0.2);
  box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22), inset 0 1px 0 rgba(255,255,255,0.7);
  background:
    radial-gradient(circle at top right, rgba(59,130,246,0.16), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96));
}
.premium-renewal-modal::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 4px;
  background: linear-gradient(90deg, #38bdf8, #6366f1, #f59e0b);
}
.premium-modal-header { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
.premium-modal-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 16px;
  color: #1d4ed8;
  background: linear-gradient(135deg, #dbeafe, #eef2ff);
  border: 1px solid #bfdbfe;
  box-shadow: 0 10px 26px rgba(37,99,235,0.18);
  font-weight: 900;
}
.premium-kicker { margin: 0 0 2px; color: #2563eb; font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0; line-height: 1.6; }
.modal-note { background: #fff8e1; border: 1px solid #ffe0a3; border-radius: 8px; color: #7a4b00; font-size: 13px; line-height: 1.6; margin: -8px 0 16px; padding: 10px 12px; }
.premium-note { border-radius: 14px; border-color: #fed7aa; background: linear-gradient(135deg, #fff7ed, #fffbeb); }
</style>
