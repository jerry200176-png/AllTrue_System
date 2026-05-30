<template>
  <Teleport to="body">
    <div class="sd-overlay" role="dialog" aria-modal="true" :aria-label="title" @click.self="closeIfAllowed">
      <div class="sd-sheet" :class="{ 'sd-sheet-readonly': readonly }">
        <header class="sd-head">
          <h3 class="sd-title">
            <span class="material-symbols-outlined" aria-hidden="true">{{ readonly ? 'visibility' : 'flag' }}</span>
            {{ title }}
          </h3>
          <button class="sd-close" type="button" aria-label="關閉" @click="closeIfAllowed">&times;</button>
        </header>

        <div class="sd-body">
          <!-- Context summary (when tied to a session) -->
          <div v-if="sessionContext" class="sd-context">
            <div class="sd-context-row"><span class="sd-context-label">日期</span><span>{{ sessionContext.date || '—' }}</span></div>
            <div class="sd-context-row"><span class="sd-context-label">時段</span><span>{{ sessionContext.time || '—' }}</span></div>
            <div class="sd-context-row"><span class="sd-context-label">科目</span><span>{{ sessionContext.subject || '—' }}</span></div>
            <div class="sd-context-row"><span class="sd-context-label">學生</span><span>{{ sessionContext.student || '—' }}</span></div>
          </div>

          <!-- READ-ONLY: show existing report (duplicate-guard / status view) -->
          <template v-if="readonly">
            <div class="sd-readonly-note">
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              <span>此堂次已有回報，以下為目前狀態。</span>
            </div>
            <div class="sd-readonly-field">
              <div class="sd-readonly-label">出入類型</div>
              <div class="sd-readonly-value">{{ existingTypeLabel }}</div>
            </div>
            <div v-if="existing?.notes" class="sd-readonly-field">
              <div class="sd-readonly-label">我的備註</div>
              <div class="sd-readonly-value sd-readonly-note-text">{{ existing.notes }}</div>
            </div>
            <div class="sd-readonly-field">
              <div class="sd-readonly-label">狀態</div>
              <div class="sd-readonly-value">
                <span class="sd-status-badge" :class="`sd-status-${existing?.status || 'pending'}`">{{ statusLabel(existing?.status) }}</span>
              </div>
            </div>
            <div v-if="existing?.resolution_note" class="sd-readonly-field">
              <div class="sd-readonly-label">主任處理說明</div>
              <div class="sd-readonly-value sd-readonly-note-text">{{ existing.resolution_note }}</div>
            </div>
          </template>

          <!-- FORM mode -->
          <template v-else>
            <!-- Discrepancy type radio cards -->
            <fieldset class="sd-field sd-field-types">
              <legend class="sd-label">出入類型 <span class="sd-required" aria-hidden="true">*</span></legend>
              <div class="sd-radio-grid">
                <label
                  v-for="opt in typeOptions"
                  :key="opt.value"
                  class="sd-radio-card"
                  :class="{ active: form.discrepancy_type === opt.value }"
                >
                  <input
                    type="radio"
                    name="sd-type"
                    :value="opt.value"
                    v-model="form.discrepancy_type"
                  />
                  <div class="sd-radio-body">
                    <div class="sd-radio-label">{{ opt.label }}</div>
                    <div class="sd-radio-hint">{{ opt.hint }}</div>
                  </div>
                </label>
              </div>
            </fieldset>

            <!-- PRD 2026-04-18 FR-006: corrected time-range -->
            <div class="sd-field sd-field-corrected" :class="{ 'sd-field-corrected-active': form.discrepancy_type === 'wrong_time' }">
              <label class="sd-label" for="sd-corrected">
                正確時間應為
                <span class="sd-optional">（選填）</span>
                <span
                  v-if="form.discrepancy_type === 'wrong_time'"
                  class="sd-accent-tag"
                  aria-hidden="true"
                >建議填寫</span>
              </label>
              <input
                id="sd-corrected"
                ref="correctedInput"
                v-model.trim="form.corrected_time_range"
                class="sd-input"
                :class="{ 'sd-input-emphasis': form.discrepancy_type === 'wrong_time', 'sd-input-error': correctedError }"
                maxlength="32"
                inputmode="text"
                autocomplete="off"
                placeholder="例：14:00-16:00（若不確定可留空）"
                aria-describedby="sd-corrected-hint"
                @blur="validateCorrected"
                @input="onCorrectedInput"
              />
              <p
                v-if="correctedError"
                id="sd-corrected-error"
                class="sd-inline-error"
                role="alert"
              >
                {{ correctedError }}
              </p>
              <p v-else id="sd-corrected-hint" class="sd-hint">
                格式應為 HH:MM-HH:MM，例：14:00-16:00
              </p>
            </div>

            <!-- Missing-session extra fields -->
            <div v-if="mode === 'missing'" class="sd-field-group">
              <div class="sd-field">
                <label class="sd-label" for="sd-mis-subject">科目 <span class="sd-required" aria-hidden="true">*</span></label>
                <input id="sd-mis-subject" v-model.trim="form.subject" class="sd-input" maxlength="64" placeholder="例：國文" />
              </div>
              <div class="sd-field">
                <label class="sd-label" for="sd-mis-student">學生姓名 <span class="sd-required" aria-hidden="true">*</span></label>
                <input id="sd-mis-student" v-model.trim="form.student_name" class="sd-input" maxlength="64" placeholder="現場學生的姓名" />
              </div>
              <div class="sd-field sd-field-half">
                <label class="sd-label" for="sd-mis-date">日期</label>
                <input id="sd-mis-date" v-model="form.session_date" type="date" class="sd-input" />
              </div>
              <div class="sd-field sd-field-half">
                <label class="sd-label" for="sd-mis-time">時段 <span class="sd-required" aria-hidden="true">*</span></label>
                <input id="sd-mis-time" v-model.trim="form.time_range" class="sd-input" maxlength="32" placeholder="例：14:00–15:30" />
              </div>
            </div>

            <div class="sd-field">
              <label class="sd-label" for="sd-notes">備註（選填）</label>
              <textarea
                id="sd-notes"
                v-model="form.notes"
                class="sd-textarea"
                rows="3"
                :maxlength="NOTE_MAX"
                placeholder="補充說明（選填）"
              ></textarea>
              <div class="sd-counter" :class="{ 'sd-counter-limit': atLimit }">
                {{ noteLen }}/{{ NOTE_MAX }}{{ atLimit ? ' 已達上限' : '' }}
              </div>
            </div>

            <p v-if="errorMsg" class="sd-error" role="alert">{{ errorMsg }}</p>
          </template>
        </div>

        <footer class="sd-foot">
          <template v-if="readonly">
            <button
              v-if="canWithdraw"
              class="sd-btn sd-btn-danger"
              type="button"
              :disabled="withdrawing"
              @click="handleWithdraw"
            >
              <span v-if="withdrawing" class="sd-spinner" aria-hidden="true"></span>
              {{ withdrawing ? '撤銷中…' : '撤銷回報' }}
            </button>
            <button class="sd-btn sd-btn-primary" type="button" @click="close">我知道了</button>
          </template>
          <template v-else>
            <button class="sd-btn sd-btn-ghost" type="button" :disabled="submitting" @click="close">取消</button>
            <button
              class="sd-btn sd-btn-primary"
              type="button"
              :disabled="!canSubmit || submitting"
              @click="submit"
            >
              <span v-if="submitting" class="sd-spinner" aria-hidden="true"></span>
              {{ submitting ? '送出中…' : '送出回報' }}
            </button>
          </template>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { createDiscrepancy, DISCREPANCY_TYPES, STATUS_LABELS, withdrawDiscrepancy } from '../lib/scheduleDiscrepanciesApi';

const NOTE_MAX = 200;

const props = defineProps({
  mode: { type: String, default: 'session' },                 // 'session' | 'missing'
  branchId: { type: [Number, String], required: true },
  classSessionId: { type: [Number, String], default: null },  // required when mode==='session'
  sessionContext: { type: Object, default: null },            // { date, time, subject, student }
  existing: { type: Object, default: null },                  // when provided → read-only view
});

const emit = defineEmits(['close', 'submitted', 'withdrawn']);

const readonly = computed(() => Boolean(props.existing));
const title = computed(() => {
  if (readonly.value) return '已回報出入';
  return props.mode === 'missing' ? '回報此課不在系統中' : '回報課表出入';
});

const typeOptions = computed(() => {
  if (props.mode === 'missing') {
    return [{ value: 'missing_session', label: '此課不在系統中', hint: '學生到場但系統找不到對應堂次' }];
  }
  return DISCREPANCY_TYPES;
});

const form = reactive({
  discrepancy_type: props.mode === 'missing' ? 'missing_session' : '',
  subject: props.sessionContext?.subject || '',
  student_name: props.sessionContext?.student || '',
  session_date: props.sessionContext?.date || todayYmd(),
  time_range: props.sessionContext?.time || '',
  corrected_time_range: '',
  notes: '',
});

const submitting = ref(false);
const withdrawing = ref(false);
const errorMsg = ref('');
const correctedError = ref('');
const correctedInput = ref(null);

// PRD 2026-04-18 FR-006: when the teacher selects 「上課時間有誤」,
// auto-focus the corrected-time field so filling it in is one tap away.
watch(() => form.discrepancy_type, (next, prev) => {
  if (next === 'wrong_time' && prev !== 'wrong_time') {
    nextTick(() => {
      const el = correctedInput.value;
      if (el && typeof el.focus === 'function') {
        try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch {}
        el.focus();
      }
    });
  }
});

// HH:MM-HH:MM (24h) with lenient whitespace + start < end check.
const CORRECTED_REGEX = /^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/;

function normalizeCorrected(raw) {
  const v = (raw || '').replace(/\s+/g, '');
  if (v === '') return { ok: true, value: null };
  const m = CORRECTED_REGEX.exec(v);
  if (!m) return { ok: false, value: null };
  const [, h1, m1, h2, m2] = m;
  const hh1 = Number(h1), mm1 = Number(m1), hh2 = Number(h2), mm2 = Number(m2);
  if (hh1 > 23 || hh2 > 23 || mm1 > 59 || mm2 > 59) return { ok: false, value: null };
  const start = hh1 * 60 + mm1;
  const end = hh2 * 60 + mm2;
  if (start >= end) return { ok: false, value: null };
  const padded = `${String(hh1).padStart(2, '0')}:${String(mm1).padStart(2, '0')}-${String(hh2).padStart(2, '0')}:${String(mm2).padStart(2, '0')}`;
  return { ok: true, value: padded };
}

function onCorrectedInput() {
  // Clear error as soon as the teacher starts typing a fix.
  if (correctedError.value) correctedError.value = '';
}

function validateCorrected() {
  const raw = form.corrected_time_range;
  if (!raw) { correctedError.value = ''; return true; }
  const { ok } = normalizeCorrected(raw);
  correctedError.value = ok ? '' : '格式應為 HH:MM-HH:MM，例：14:00-16:00';
  return ok;
}

const canWithdraw = computed(() => readonly.value && props.existing?.status === 'pending');

async function handleWithdraw() {
  if (!canWithdraw.value || withdrawing.value) return;
  const confirmed = window.confirm('確定撤銷此回報？主任將看不到此筆內容。');
  if (!confirmed) return;
  withdrawing.value = true;
  errorMsg.value = '';
  try {
    const res = await withdrawDiscrepancy(props.existing.id);
    emit('withdrawn', res);
  } catch (e) {
    errorMsg.value = e?.payload?.current_status === 'acknowledged'
      ? '主任已確認，無法撤銷'
      : (e?.message || '撤銷失敗，請稍後再試');
  } finally {
    withdrawing.value = false;
  }
}

const noteLen = computed(() => (form.notes || '').length);
const atLimit = computed(() => noteLen.value >= NOTE_MAX);

const canSubmit = computed(() => {
  if (!form.discrepancy_type) return false;
  if (props.mode === 'missing') {
    if (!form.subject || !form.student_name || !form.time_range) return false;
  }
  if (noteLen.value > NOTE_MAX) return false;
  // If the teacher typed a corrected time, require valid format before enabling submit.
  if (form.corrected_time_range && !normalizeCorrected(form.corrected_time_range).ok) return false;
  return true;
});

const existingTypeLabel = computed(() => {
  const key = props.existing?.discrepancy_type;
  return props.existing?.discrepancy_type_label || DISCREPANCY_TYPES.find((t) => t.value === key)?.label || key || '—';
});

function statusLabel(s) { return STATUS_LABELS[s] || s || '—'; }

function todayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function closeIfAllowed() {
  if (submitting.value) return;
  close();
}

function close() {
  emit('close');
}

async function submit() {
  if (!canSubmit.value || submitting.value) return;
  if (!validateCorrected()) return;
  errorMsg.value = '';
  submitting.value = true;

  const correctedNorm = normalizeCorrected(form.corrected_time_range);
  const payload = {
    branch_id: Number(props.branchId),
    discrepancy_type: form.discrepancy_type,
    notes: form.notes || null,
  };
  if (correctedNorm.ok && correctedNorm.value) {
    payload.corrected_time_range = correctedNorm.value;
  }
  if (props.mode === 'session' && props.classSessionId) {
    payload.class_session_id = Number(props.classSessionId);
    if (props.sessionContext?.date) payload.session_date = props.sessionContext.date;
    if (props.sessionContext?.time) payload.time_range = props.sessionContext.time;
    if (props.sessionContext?.subject) payload.subject = props.sessionContext.subject;
    if (props.sessionContext?.student) payload.student_name = props.sessionContext.student;
  } else if (props.mode === 'missing') {
    payload.class_session_id = null;
    payload.subject = form.subject;
    payload.student_name = form.student_name;
    payload.time_range = form.time_range;
    if (form.session_date) payload.session_date = form.session_date;
  }

  try {
    const result = await createDiscrepancy(payload);
    emit('submitted', result);
  } catch (e) {
    errorMsg.value = e?.message || '送出失敗，請稍後再試';
  } finally {
    submitting.value = false;
  }
}

// Allow ESC close via keyboard (bubble)
watch(() => props.existing, () => { errorMsg.value = ''; });
</script>

<style scoped>
.sd-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 10050;
  animation: sd-fade 150ms ease-out;
}
@keyframes sd-fade { from { opacity: 0 } to { opacity: 1 } }

.sd-sheet {
  background: var(--card-bg, #fff);
  color: var(--text-primary, #0f172a);
  width: 100%;
  max-width: 480px;
  max-height: calc(100vh - 32px);
  overflow: auto;
  border-radius: 12px;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.2);
  display: flex;
  flex-direction: column;
}
.sd-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-soft, #e5e7eb);
}
.sd-title {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
}
.sd-title .material-symbols-outlined { color: var(--warning, #f59e0b); font-size: 22px; }
.sd-sheet-readonly .sd-title .material-symbols-outlined { color: var(--ds-ink-mute, #64748b); }
.sd-close {
  background: none;
  border: 0;
  font-size: 24px;
  cursor: pointer;
  color: var(--text-muted, #64748b);
  line-height: 1;
  padding: 4px 8px;
  min-height: 44px;
  min-width: 44px;
}
.sd-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
.sd-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 12px 20px;
  border-top: 1px solid var(--border-soft, #e5e7eb);
  position: sticky;
  bottom: 0;
  background: var(--card-bg, #fff);
}

.sd-context {
  background: var(--surface-muted, #f8fafc);
  border: 1px solid var(--border-soft, #e5e7eb);
  border-radius: 8px;
  padding: 10px 12px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px 16px;
  font-size: 13px;
}
.sd-context-row { display: flex; gap: 6px; }
.sd-context-label { color: var(--text-muted, #64748b); min-width: 36px; }

.sd-field { display: flex; flex-direction: column; gap: 6px; }
.sd-field-group { display: grid; grid-template-columns: 1fr; gap: 12px; }
@media (min-width: 480px) { .sd-field-group { grid-template-columns: 1fr 1fr; } .sd-field-half { grid-column: span 1; } .sd-field-group > .sd-field:nth-child(-n+2) { grid-column: 1 / -1 } }

.sd-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary, #0f172a);
}
.sd-required { color: var(--danger, #ef4444); margin-left: 2px; }

.sd-input, .sd-textarea {
  width: 100%;
  border: 1px solid var(--border, #cbd5e1);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  background: var(--input-bg, #fff);
  color: inherit;
  min-height: 44px;
  box-sizing: border-box;
}
.sd-textarea { min-height: 88px; resize: vertical; }
.sd-input:focus, .sd-textarea:focus {
  outline: none;
  border-color: var(--primary, #ef6c00);
  box-shadow: var(--ds-focus-ring, 0 0 0 3px rgba(245, 124, 0, 0.22));
}

.sd-radio-grid { display: grid; grid-template-columns: 1fr; gap: 8px; }
.sd-radio-card {
  position: relative;
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px 14px;
  border: 1.5px solid var(--border, #cbd5e1);
  border-radius: 10px;
  cursor: pointer;
  min-height: 44px;
  background: var(--input-bg, #fff);
  transition: border-color 120ms ease, background 120ms ease;
}
.sd-radio-card:hover { border-color: var(--primary, #2563eb); }
.sd-radio-card.active {
  border-color: var(--warning, #f59e0b);
  background: var(--warning-soft, #fffbeb);
}
.sd-radio-card input { margin-top: 2px; width: 18px; height: 18px; accent-color: var(--warning, #f59e0b); }
.sd-radio-label { font-weight: 600; font-size: 14px; }
.sd-radio-hint { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px; }

.sd-counter {
  font-size: 12px;
  color: var(--text-muted, #64748b);
  align-self: flex-end;
}

/* PRD 2026-04-18 FR-006: corrected-time field emphasis when type=wrong_time */
.sd-field-corrected { gap: 6px; }
.sd-optional { font-weight: 400; color: var(--text-muted, #64748b); margin-left: 4px; font-size: 12px; }
.sd-accent-tag {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--primary, #2563eb);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
}
.sd-input-emphasis {
  border-color: var(--primary, #ef6c00) !important;
  box-shadow: 0 0 0 2px rgba(245, 124, 0, 0.1);
}
.sd-input-error {
  border-color: var(--danger, #ef4444) !important;
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.15);
}
.sd-hint {
  margin: 0;
  font-size: 12px;
  color: var(--text-muted, #64748b);
}
.sd-inline-error {
  margin: 0;
  font-size: 12px;
  color: var(--danger, #ef4444);
  font-weight: 500;
}
.sd-counter-limit { color: var(--danger, #ef4444); font-weight: 600; }

.sd-error {
  margin: 0;
  color: var(--danger, #ef4444);
  font-size: 13px;
  background: rgba(239, 68, 68, 0.08);
  border: 1px solid rgba(239, 68, 68, 0.2);
  padding: 8px 10px;
  border-radius: 8px;
}

.sd-readonly-note {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--ds-canvas-soft, #f6f9fc);
  border: 1px solid var(--ds-hairline, #e3e8ee);
  color: var(--ds-ink, #1a1a1a);
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
}
.sd-readonly-field { display: flex; flex-direction: column; gap: 4px; }
.sd-readonly-label { font-size: 12px; color: var(--text-muted, #64748b); }
.sd-readonly-value { font-size: 14px; font-weight: 500; }
.sd-readonly-note-text { white-space: pre-wrap; background: var(--surface-muted, #f8fafc); padding: 8px 10px; border-radius: 6px; }

.sd-status-badge {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 999px;
}
.sd-status-pending { background: var(--warning-soft, #fffbeb); color: var(--warning-strong, #b45309); border: 1px solid var(--warning-border, #fde68a); }
.sd-status-acknowledged { background: var(--info-soft, #eff6ff); color: var(--info-strong, #1d4ed8); border: 1px solid var(--info-border, #bfdbfe); }
.sd-status-resolved { background: var(--success-soft, #ecfdf5); color: var(--success-strong, #047857); border: 1px solid var(--success-border, #a7f3d0); }
.sd-status-withdrawn { background: var(--surface-muted, #f1f5f9); color: var(--text-muted, #64748b); border: 1px solid var(--border-soft, #e2e8f0); }

.sd-btn {
  border: 0;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  padding: 10px 18px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
}
.sd-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.sd-btn-ghost { background: transparent; color: var(--text-primary, #0f172a); border: 1px solid var(--border, #cbd5e1); }
.sd-btn-primary { background: var(--primary, #ef6c00); color: #fff; }
.sd-btn-primary:hover:not(:disabled) { background: var(--ds-primary-press, #d84315); }
.sd-btn-danger { background: transparent; color: var(--danger, #ef4444); border: 1px solid var(--danger, #ef4444); }
.sd-btn-danger:hover:not(:disabled) { background: rgba(239, 68, 68, 0.08); }

.sd-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: sd-spin 0.8s linear infinite;
}
@keyframes sd-spin { to { transform: rotate(360deg); } }

/* Mobile: full-width submit stack */
@media (max-width: 480px) {
  .sd-sheet { max-width: 100%; border-radius: 12px 12px 0 0; margin-top: auto; }
  .sd-overlay { padding: 0; align-items: flex-end; }
  .sd-foot { flex-direction: column-reverse; }
  .sd-foot .sd-btn { width: 100%; justify-content: center; }
}
</style>
