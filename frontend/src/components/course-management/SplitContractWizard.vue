<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal split-contract-wizard" role="dialog" aria-modal="true" aria-labelledby="split-contract-title">
      <div class="wizard-header">
        <div>
          <p class="wizard-kicker">合約拆分精靈</p>
          <h3 id="split-contract-title" class="modal-title">{{ course?.student_name || '此學生' }}／{{ course?.subject_name || course?.subject || '課程' }}</h3>
        </div>
        <span class="wizard-progress">第 {{ step }} 步／3</span>
      </div>

      <nav class="wizard-steps" aria-label="合約拆分步驟">
        <span v-for="item in STEP_LABELS" :key="item.step" class="wizard-step" :class="{ active: step === item.step, done: step > item.step }">
          <span class="wizard-step-number">{{ step > item.step ? '✓' : item.step }}</span>
          <span>{{ item.label }}</span>
        </span>
      </nav>

      <section v-if="step === 1" aria-labelledby="split-step-one-title">
        <h4 id="split-step-one-title" class="wizard-section-title">選擇要搬到新合約的已上課堂次</h4>
        <p class="period-hint">只會搬移已上課紀錄；評量與點名會一起保留。未上課排程不會被搬移。</p>
        <div class="session-pick-list">
          <label v-for="session in sessions" :key="session.id" class="session-pick-row">
            <input v-model="selectedIds" type="checkbox" :value="Number(session.id)" :disabled="submitting" />
            <span class="session-pick-date">{{ session.date }}</span>
            <span class="session-pick-status">{{ statusLabel(session.status) }}</span>
          </label>
          <p v-if="sessions.length === 0" class="empty-hint">目前沒有可搬移的已上課堂次。</p>
        </div>
        <label class="form-label">新合約首堂日期
          <input id="split-start-date" v-model="startDate" type="date" class="form-input" :disabled="submitting" />
        </label>
        <p class="form-hint">選取 {{ selectedIds.length }} 堂；下一步會由後端自動試算兩份合約。</p>
      </section>

      <section v-else-if="step === 2" aria-labelledby="split-step-two-title">
        <h4 id="split-step-two-title" class="wizard-section-title">確認自動試算</h4>
        <p class="period-hint">搬課會先發生，再把來源合約更正到搬課後的已使用堂數；兩筆變更會一次完成或全部不變。</p>
        <div v-if="previewLoading" class="wizard-loading" role="status">正在試算合約金額與堂數…</div>
        <div v-else-if="preview" class="split-summary" data-testid="split-summary">
          <article class="split-summary-card">
            <span class="split-summary-label">舊合約更正後</span>
            <strong>{{ formatCount(preview.source_correction?.session_count) }} 堂</strong>
            <span>{{ formatMoney(preview.source_correction?.charge) }}</span>
            <small>已搬走 {{ formatCount(preview.selected_session_count) }} 堂紀錄</small>
          </article>
          <span class="split-arrow" aria-hidden="true">→</span>
          <article class="split-summary-card split-summary-card--new">
            <span class="split-summary-label">新合約</span>
            <strong>{{ formatCount(preview.new_course?.session_count) }} 堂</strong>
            <span>{{ formatMoney(preview.new_course?.charge) }}</span>
            <small>搬入 {{ formatCount(preview.new_course?.transferred_session_count) }} 堂＋未來 {{ formatCount(preview.new_course?.future_session_count) }} 堂</small>
          </article>
        </div>
      </section>

      <section v-else aria-labelledby="split-step-three-title">
        <h4 id="split-step-three-title" class="wizard-section-title">確認送出</h4>
        <p class="period-hint">送出後會建立未收款新合約、搬移評量／點名，並更新舊合約與未付款帳單。此操作會留下稽核紀錄。</p>
        <label class="form-label">拆分原因（必填）
          <textarea id="split-reason" v-model.trim="reason" class="form-input" rows="3" maxlength="255" placeholder="例如：主任確認本期實際收費與剩餘堂次拆分"></textarea>
        </label>
        <p class="form-hint">請確認金額與堂數無誤，再按下「確認拆分合約」。</p>
      </section>

      <p v-if="displayError" class="wizard-error" role="alert">{{ displayError }}</p>

      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="step === 1 ? $emit('close') : goBack()">{{ step === 1 ? '取消' : '上一步' }}</button>
        <button v-if="step === 1" class="primary" :disabled="submitting || selectedIds.length === 0 || !startDate" @click="requestPreview">下一步：試算</button>
        <button v-else-if="step === 2" class="primary" :disabled="submitting || previewLoading || !preview" @click="step = 3">下一步：確認送出</button>
        <button v-else class="primary" :disabled="submitting || !reason" @click="submit">{{ submitting ? '處理中…' : '確認拆分合約' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const STEP_LABELS = [
  { step: 1, label: '選堂次' },
  { step: 2, label: '看試算' },
  { step: 3, label: '確認' },
];
const STATUS_LABELS = { completed: '已上課', attended: '已上課', late: '已上課（遲到）' };

const props = defineProps({
  show: Boolean,
  course: { type: Object, default: null },
  sessions: { type: Array, default: () => [] },
  preview: { type: Object, default: null },
  previewLoading: Boolean,
  submitting: Boolean,
  errorMessage: { type: String, default: '' },
});
const emit = defineEmits(['close', 'preview', 'submit']);

const step = ref(1);
const selectedIds = ref([]);
const startDate = ref('');
const reason = ref('');
const localError = ref('');
const displayError = computed(() => props.errorMessage || localError.value);

watch(() => props.show, (visible) => {
  if (visible) {
    step.value = 1;
    selectedIds.value = [];
    startDate.value = '';
    reason.value = '';
    localError.value = '';
  }
});
watch(() => props.preview, (preview) => {
  if (preview && step.value === 1) step.value = 2;
});

function statusLabel(status) {
  return STATUS_LABELS[String(status || '').toLowerCase()] || '已使用';
}
function formatCount(value) {
  return Number(value || 0).toLocaleString();
}
function formatMoney(value) {
  return `$${Number(value || 0).toLocaleString()}`;
}
function requestPreview() {
  localError.value = '';
  if (selectedIds.value.length === 0 || !startDate.value) {
    localError.value = '請至少選擇一堂已上課紀錄，並填寫新合約首堂日期。';
    return;
  }
  emit('preview', { sessionIds: selectedIds.value.map(Number), startDate: startDate.value });
  step.value = 2;
}
function goBack() {
  step.value -= 1;
  localError.value = '';
}
function submit() {
  if (!reason.value) {
    localError.value = '請填寫拆分原因。';
    return;
  }
  emit('submit', {
    sessionIds: selectedIds.value.map(Number),
    startDate: startDate.value,
    reason: reason.value,
  });
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.wizard-header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.wizard-kicker { margin: 0 0 3px; color: var(--ds-ink-mute); font-size: 12px; font-weight: 800; letter-spacing: .04em; }
.modal-title { margin: 0; color: var(--text); font-size: 1.2rem; font-weight: 800; }
.wizard-progress { color: var(--ds-ink-mute); font-size: 12px; white-space: nowrap; }
.wizard-steps { display: flex; gap: 8px; margin: 18px 0; padding-bottom: 12px; border-bottom: 1px solid var(--ds-hairline); }
.wizard-step { display: flex; align-items: center; gap: 5px; color: var(--ds-ink-mute); font-size: 12px; font-weight: 700; }
.wizard-step:not(:last-child)::after { content: '—'; margin-left: 3px; color: var(--ds-hairline-strong); }
.wizard-step.active { color: var(--ds-brand); }
.wizard-step.done { color: var(--ds-success); }
.wizard-step-number { display: inline-grid; width: 22px; height: 22px; place-items: center; border: 1px solid currentColor; border-radius: 50%; }
.wizard-section-title { margin: 0 0 8px; color: var(--text); font-size: 15px; }
.period-hint { margin: 0 0 14px; padding: 10px 12px; border-radius: 9px; background: var(--ds-canvas-soft); color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.session-pick-list { display: flex; max-height: 230px; flex-direction: column; gap: 4px; overflow-y: auto; margin-bottom: 14px; padding: 6px; border: 1px solid var(--ds-hairline); border-radius: 8px; }
.session-pick-row { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 6px; color: var(--text); font-size: 13px; cursor: pointer; }
.session-pick-row:hover { background: var(--ds-canvas-soft); }
.session-pick-date { flex: 1; }
.session-pick-status, .form-hint, .empty-hint { color: var(--ds-ink-mute); font-size: 12px; }
.form-label { display: block; margin: 12px 0 0; color: var(--text); font-size: 13px; font-weight: 700; }
.form-input { display: block; width: 100%; box-sizing: border-box; margin-top: 6px; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font: inherit; }
.form-hint { margin: 7px 0 0; font-weight: 400; }
.wizard-loading { padding: 24px 0; color: var(--ds-ink-mute); text-align: center; }
.split-summary { display: flex; align-items: stretch; gap: 8px; }
.split-summary-card { flex: 1; display: flex; min-height: 125px; flex-direction: column; justify-content: center; gap: 5px; padding: 14px; border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas-soft); }
.split-summary-card--new { border-color: var(--ds-brand); background: var(--ds-brand-wash); }
.split-summary-label { color: var(--ds-ink-mute); font-size: 12px; font-weight: 800; }
.split-summary-card strong { color: var(--text); font-size: 22px; }
.split-summary-card span { color: var(--text); font-size: 17px; font-variant-numeric: tabular-nums; }
.split-summary-card small { color: var(--ds-ink-mute); font-size: 11px; line-height: 1.4; }
.split-arrow { align-self: center; color: var(--ds-brand); font-size: 20px; font-weight: 800; }
.wizard-error { margin: 14px 0 0; padding: 9px 10px; border-radius: 8px; background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 13px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 12px; border-top: 1px solid var(--ds-hairline); }
@media (max-width: 560px) { .split-summary { flex-direction: column; } .split-arrow { transform: rotate(90deg); } }
</style>
